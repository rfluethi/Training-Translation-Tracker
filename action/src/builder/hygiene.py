"""Data-hygiene report.

Collects observations during the build about the quality of the input data
that do not stop the action run but still need attention. The result is
written as a Markdown file (`data-hygiene.md`) and committed to the
`data` branch.

Categories:

  1. Issues whose body has no HTML marker table
     (=> components appear as "all open"; migration recommended)
  2. Issues with parse_error (markers present but table is unparseable)
  3. Multiple issues for the same original URL
  4. Issues without an extractable original URL (end up as orphans)
  5. Creators/reviewers with suspicious characters
     (trailing punctuation, leftover @ prefixes, double spaces)
  6. Items in scope without a (matching) issue (i.e. "still to do")
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from datetime import datetime, timezone

from ..github.issues import ParsedIssue
from ..inventory.base import InventoryItem


# Suspicious characters at the start/end of a username
_SUSPICIOUS_USER_RE = re.compile(r"^[@\s]|[\s.,;:!?]$")


@dataclass
class HygieneReport:
    """A collection of maintenance-relevant observations from the last build."""

    issues_without_markers: list[tuple[int, str]] = field(default_factory=list)
    """(issue_number, title): issues whose body has no TRANSLATION-STATUS markers."""

    issues_with_parse_errors: list[tuple[int, str]] = field(default_factory=list)
    """(issue_number, title): markers present, but the table is unparseable."""

    duplicate_url_clusters: list[tuple[str, list[int]]] = field(default_factory=list)
    """(url, [issue_numbers]): more than one issue points to the same URL."""

    issues_without_original_url: list[tuple[int, str]] = field(default_factory=list)
    """(issue_number, title): body contains no parseable link-to-original-content."""

    suspicious_users: list[tuple[int, str, str, str]] = field(default_factory=list)
    """(issue_number, component, role, value): username with a suspicious character."""

    inventory_items_without_issue: list[tuple[str, str]] = field(default_factory=list)
    """(url, title): in scope.yml, but no DACH issue for it yet (i.e. "still to do")."""


def collect_hygiene(
    parsed_issues: list[ParsedIssue],
    inventory: list[InventoryItem],
    matched_inventory_urls: set[str],
    issue_index: dict[str, list[ParsedIssue]],
) -> HygieneReport:
    """Compute the hygiene report from joiner intermediate state."""

    report = HygieneReport()

    # 1+2: body-format problems + 5: suspicious usernames
    for issue in parsed_issues:
        body = issue.parsed
        # Marker check: parser sets components=None when no markers are present.
        # parse_error is set when markers are present but the table is empty/broken.
        if body.parse_error:
            report.issues_with_parse_errors.append((issue.number, issue.raw.title))
        elif body.components is None:
            report.issues_without_markers.append((issue.number, issue.raw.title))

        # Suspicious usernames in components
        for comp in issue.components:
            for role, value in (("Creator", comp.creator), ("Reviewer", comp.reviewer)):
                if value and _SUSPICIOUS_USER_RE.search(value):
                    report.suspicious_users.append((issue.number, comp.name, role, value))

    # 3: duplicates
    for url, issues in issue_index.items():
        if len(issues) > 1:
            numbers = sorted(i.number for i in issues)
            report.duplicate_url_clusters.append((url, numbers))

    # 4: issues without an original URL
    for issue in parsed_issues:
        if not issue.normalized_original:
            report.issues_without_original_url.append((issue.number, issue.raw.title))

    # 6: scope items without an issue
    for item in inventory:
        if item.url_en not in matched_inventory_urls:
            report.inventory_items_without_issue.append((item.url_en, item.title_en))

    return report


def render_hygiene_markdown(
    report: HygieneReport,
    *,
    generated_at: datetime | None = None,
) -> str:
    """Render the report as Markdown."""

    generated = (generated_at or datetime.now(timezone.utc)).astimezone(timezone.utc)
    ts = generated.strftime("%Y-%m-%dT%H:%M:%SZ")

    sections: list[str] = []
    sections.append("# Data-Hygiene Report")
    sections.append("")
    sections.append(f"As of: `{ts}`")
    sections.append("")
    sections.append(
        "Observations from the latest build that need attention. None of these "
        "stops the build; everything is meant as nice-to-fix."
    )
    sections.append("")

    # 1. Issues without marker table
    sections.append(
        "## Issues without new marker table "
        f"({len(report.issues_without_markers)})"
    )
    sections.append("")
    if report.issues_without_markers:
        sections.append(
            "The body contains no `<!-- TRANSLATION-STATUS-START -->` block. "
            "Components currently appear as all `open` (from the default templates). "
            "Migration to the format from `Issue-Vorlage-DACH.md` is recommended."
        )
        sections.append("")
        for number, title in report.issues_without_markers:
            sections.append(f"- [#{number}](https://github.com/WordPress/Learn/issues/{number}): {title}")
        sections.append("")
    else:
        sections.append("_None: all matched issues use the new format._")
        sections.append("")

    # 2. Parse errors
    sections.append(
        "## Issues with parse error in the table "
        f"({len(report.issues_with_parse_errors)})"
    )
    sections.append("")
    if report.issues_with_parse_errors:
        sections.append(
            "Markers are present, but between them there is no recognisable table "
            "(rows do not start with `|`, header missing, etc.). Fix the body."
        )
        sections.append("")
        for number, title in report.issues_with_parse_errors:
            sections.append(f"- [#{number}](https://github.com/WordPress/Learn/issues/{number}): {title}")
        sections.append("")
    else:
        sections.append("_None._")
        sections.append("")

    # 3. Duplicates
    sections.append(
        f"## Multiple issues for the same URL ({len(report.duplicate_url_clusters)})"
    )
    sections.append("")
    if report.duplicate_url_clusters:
        sections.append(
            "Several issues point to the same original URL. In the tracker, the "
            "lowest-numbered issue is used as the primary; the others appear as "
            "`duplicate_issues`. Please clean up: one issue per original URL."
        )
        sections.append("")
        for url, numbers in report.duplicate_url_clusters:
            primary = numbers[0]
            others = ", ".join(f"#{n}" for n in numbers[1:])
            sections.append(f"- `{url}`: primary #{primary}, duplicates: {others}")
        sections.append("")
    else:
        sections.append("_None._")
        sections.append("")

    # 4. Issues without original URL
    sections.append(
        "## Issues without an extractable original URL "
        f"({len(report.issues_without_original_url)})"
    )
    sections.append("")
    if report.issues_without_original_url:
        sections.append(
            "The body is missing a `Link to original content: <URL>` field. These "
            "issues end up in the tracker's orphan bucket. Update the body or "
            "close the issue."
        )
        sections.append("")
        for number, title in report.issues_without_original_url:
            sections.append(f"- [#{number}](https://github.com/WordPress/Learn/issues/{number}): {title}")
        sections.append("")
    else:
        sections.append("_None._")
        sections.append("")

    # 5. Suspicious usernames
    sections.append(
        "## Creator/Reviewer with suspicious characters "
        f"({len(report.suspicious_users)})"
    )
    sections.append("")
    if report.suspicious_users:
        sections.append(
            "Username starts or ends with a special character (period, `@`, "
            "whitespace, ...). Likely a typo in the issue body."
        )
        sections.append("")
        for number, component, role, value in report.suspicious_users:
            sections.append(
                f"- [#{number}](https://github.com/WordPress/Learn/issues/{number}) "
                f"component `{component}` * {role}: `{value}`"
            )
        sections.append("")
    else:
        sections.append("_None._")
        sections.append("")

    # 6. Items without an issue
    sections.append(
        "## Scope items without an issue "
        f"({len(report.inventory_items_without_issue)})"
    )
    sections.append("")
    if report.inventory_items_without_issue:
        sections.append(
            "Listed in scope.yml, but no DACH translation issue exists for it yet. "
            "In the tracker they appear with all components as `open`: the honest "
            "`still-to-do` list."
        )
        sections.append("")
        for url, title in report.inventory_items_without_issue:
            sections.append(f"- [{title}]({url})")
        sections.append("")
    else:
        sections.append("_None: every scope item is being worked on at least once._")
        sections.append("")

    return "\n".join(sections)
