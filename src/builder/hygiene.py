"""Datenhygiene-Bericht.

Sammelt während des Builds Beobachtungen über die Qualität der Eingabedaten,
die zwar den Action-Lauf nicht stoppen, aber pflegbedürftig sind. Das Ergebnis
wird als Markdown-Datei (`data-hygiene.md`) geschrieben und auf den
`data`-Branch committet.

Kategorien:

  1. Issues, deren Body keine HTML-Marker-Tabelle hat
     (=> Komponenten erscheinen als „alle open", Migration empfohlen)
  2. Issues mit parse_error (Marker vorhanden, Tabelle unparseable)
  3. Mehrfach-Issues für dieselbe Original-URL
  4. Issues ohne extrahierbare Original-URL (landen als Orphans)
  5. Creator/Reviewer mit verdächtigen Zeichen
     (trailing punctuation, übrig gebliebene @-Präfixe, doppelte Spaces)
  6. Items im Scope ohne (passendes) Issue (= „noch zu tun")
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from datetime import datetime, timezone

from ..github.issues import ParsedIssue
from ..inventory.base import InventoryItem


# Verdächtige Zeichen am Anfang/Ende eines Usernames
_SUSPICIOUS_USER_RE = re.compile(r"^[@\s]|[\s.,;:!?]$")


@dataclass
class HygieneReport:
    """Eine Sammlung pflegerelevanter Beobachtungen aus dem letzten Build."""

    issues_without_markers: list[tuple[int, str]] = field(default_factory=list)
    """(issue_number, title) — Issues, deren Body keine TRANSLATION-STATUS-Marker hat."""

    issues_with_parse_errors: list[tuple[int, str]] = field(default_factory=list)
    """(issue_number, title) — Marker vorhanden, Tabelle aber unparseable."""

    duplicate_url_clusters: list[tuple[str, list[int]]] = field(default_factory=list)
    """(url, [issue_numbers]) — mehr als ein Issue zeigt auf dieselbe URL."""

    issues_without_original_url: list[tuple[int, str]] = field(default_factory=list)
    """(issue_number, title) — Body enthält keinen parsbaren Link-to-original-content."""

    suspicious_users: list[tuple[int, str, str, str]] = field(default_factory=list)
    """(issue_number, component, role, value) — Username mit verdächtigem Zeichen."""

    inventory_items_without_issue: list[tuple[str, str]] = field(default_factory=list)
    """(url, title) — In scope.yml, aber kein DACH-Issue dazu (= "noch zu tun")."""


def collect_hygiene(
    parsed_issues: list[ParsedIssue],
    inventory: list[InventoryItem],
    matched_inventory_urls: set[str],
    issue_index: dict[str, list[ParsedIssue]],
) -> HygieneReport:
    """Berechnet den Hygiene-Bericht aus Joiner-Zwischenständen."""

    report = HygieneReport()

    # 1+2: Body-Format-Probleme + 5: verdächtige Usernames
    for issue in parsed_issues:
        body = issue.parsed
        # Marker check: parser setzt components=None, wenn keine Marker da sind.
        # parse_error wird gesetzt, wenn Marker da sind, aber die Tabelle leer/kaputt ist.
        if body.parse_error:
            report.issues_with_parse_errors.append((issue.number, issue.raw.title))
        elif body.components is None:
            report.issues_without_markers.append((issue.number, issue.raw.title))

        # Verdächtige Usernames in Komponenten
        for comp in issue.components:
            for role, value in (("Creator", comp.creator), ("Reviewer", comp.reviewer)):
                if value and _SUSPICIOUS_USER_RE.search(value):
                    report.suspicious_users.append((issue.number, comp.name, role, value))

    # 3: Duplikate
    for url, issues in issue_index.items():
        if len(issues) > 1:
            numbers = sorted(i.number for i in issues)
            report.duplicate_url_clusters.append((url, numbers))

    # 4: Issues ohne Original-URL
    for issue in parsed_issues:
        if not issue.normalized_original:
            report.issues_without_original_url.append((issue.number, issue.raw.title))

    # 6: Scope-Items ohne Issue
    for item in inventory:
        if item.url_en not in matched_inventory_urls:
            report.inventory_items_without_issue.append((item.url_en, item.title_en))

    return report


def render_hygiene_markdown(
    report: HygieneReport,
    *,
    generated_at: datetime | None = None,
) -> str:
    """Rendert den Bericht als Markdown."""

    generated = (generated_at or datetime.now(timezone.utc)).astimezone(timezone.utc)
    ts = generated.strftime("%Y-%m-%dT%H:%M:%SZ")

    sections: list[str] = []
    sections.append("# Datenhygiene-Bericht")
    sections.append("")
    sections.append(f"Stand: `{ts}`")
    sections.append("")
    sections.append(
        "Beobachtungen aus dem letzten Build, die Pflege brauchen. Nichts davon "
        "stoppt den Build — alles ist als nice-to-fix zu verstehen."
    )
    sections.append("")

    # 1. Issues ohne Marker-Tabelle
    sections.append(
        "## Issues ohne neue Marker-Tabelle "
        f"({len(report.issues_without_markers)})"
    )
    sections.append("")
    if report.issues_without_markers:
        sections.append(
            "Der Body enthält keinen `<!-- TRANSLATION-STATUS-START -->`-Block. "
            "Komponenten erscheinen aktuell als alle `open` (aus den Default-Templates). "
            "Migration auf das Format aus `Issue-Vorlage-DACH.md` empfohlen."
        )
        sections.append("")
        for number, title in report.issues_without_markers:
            sections.append(f"- [#{number}](https://github.com/WordPress/Learn/issues/{number}) — {title}")
        sections.append("")
    else:
        sections.append("_Keine — alle gematchten Issues nutzen das neue Format._")
        sections.append("")

    # 2. Parse-Errors
    sections.append(
        "## Issues mit Parse-Error in der Tabelle "
        f"({len(report.issues_with_parse_errors)})"
    )
    sections.append("")
    if report.issues_with_parse_errors:
        sections.append(
            "Marker sind vorhanden, aber zwischen ihnen steht keine erkennbare Tabelle "
            "(Zeilen fangen nicht mit `|` an, Header fehlt, etc.). Body korrigieren."
        )
        sections.append("")
        for number, title in report.issues_with_parse_errors:
            sections.append(f"- [#{number}](https://github.com/WordPress/Learn/issues/{number}) — {title}")
        sections.append("")
    else:
        sections.append("_Keine._")
        sections.append("")

    # 3. Duplikate
    sections.append(
        f"## Mehrfach-Issues für dieselbe URL ({len(report.duplicate_url_clusters)})"
    )
    sections.append("")
    if report.duplicate_url_clusters:
        sections.append(
            "Mehrere Issues zeigen auf dieselbe Original-URL. Im Tracker wird das "
            "niedrigste Issue als Primary genutzt, die anderen erscheinen als "
            "`duplicate_issues`. Bitte aufräumen: ein Issue pro Original-URL."
        )
        sections.append("")
        for url, numbers in report.duplicate_url_clusters:
            primary = numbers[0]
            others = ", ".join(f"#{n}" for n in numbers[1:])
            sections.append(f"- `{url}` — Primary: #{primary}, Duplikate: {others}")
        sections.append("")
    else:
        sections.append("_Keine._")
        sections.append("")

    # 4. Issues ohne Original-URL
    sections.append(
        "## Issues ohne extrahierbare Original-URL "
        f"({len(report.issues_without_original_url)})"
    )
    sections.append("")
    if report.issues_without_original_url:
        sections.append(
            "Im Body fehlt ein Feld `Link to original content: <URL>`. Diese Issues "
            "landen im Orphan-Bucket des Trackers. Body ergänzen oder Issue schließen."
        )
        sections.append("")
        for number, title in report.issues_without_original_url:
            sections.append(f"- [#{number}](https://github.com/WordPress/Learn/issues/{number}) — {title}")
        sections.append("")
    else:
        sections.append("_Keine._")
        sections.append("")

    # 5. Verdächtige Usernames
    sections.append(
        "## Creator/Reviewer mit verdächtigen Zeichen "
        f"({len(report.suspicious_users)})"
    )
    sections.append("")
    if report.suspicious_users:
        sections.append(
            "Username beginnt/endet mit Sonderzeichen (Punkt, `@`, Leerzeichen, …). "
            "Vermutlich Tippfehler im Issue-Body."
        )
        sections.append("")
        for number, component, role, value in report.suspicious_users:
            sections.append(
                f"- [#{number}](https://github.com/WordPress/Learn/issues/{number}) "
                f"Komponente `{component}` · {role}: `{value}`"
            )
        sections.append("")
    else:
        sections.append("_Keine._")
        sections.append("")

    # 6. Items ohne Issue
    sections.append(
        "## Scope-Items ohne Issue "
        f"({len(report.inventory_items_without_issue)})"
    )
    sections.append("")
    if report.inventory_items_without_issue:
        sections.append(
            "In scope.yml gelistet, aber noch kein DACH-Übersetzungs-Issue dafür "
            "vorhanden. Im Tracker erscheinen sie mit allen Komponenten als `open` — "
            "die ehrliche `noch-zu-tun`-Liste."
        )
        sections.append("")
        for url, title in report.inventory_items_without_issue:
            sections.append(f"- [{title}]({url})")
        sections.append("")
    else:
        sections.append("_Keine — jedes Scope-Item ist mindestens einmal in Arbeit._")
        sections.append("")

    return "\n".join(sections)
