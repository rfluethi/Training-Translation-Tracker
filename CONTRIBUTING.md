# Contributing

Schön, dass du mitwirken willst! Dieses Repo enthält zwei Komponenten, eine
GitHub Action in Python und ein WordPress-Plugin in PHP. Je nach dem, was du
ändern willst, ist der Workflow leicht unterschiedlich.

## Inhaltsverzeichnis

1. [Was du beitragen kannst](#was-du-beitragen-kannst)
2. [Repo-Setup](#repo-setup)
3. [Action entwickeln (Python)](#action-entwickeln-python)
4. [Plugin entwickeln (PHP)](#plugin-entwickeln-php)
5. [Dokumentation](#dokumentation)
6. [Pull-Request-Prozess](#pull-request-prozess)
7. [Andere Locales adaptieren](#andere-locales-adaptieren)
8. [Verhaltenskodex](#verhaltenskodex)

## Was du beitragen kannst

| Art | Wie | Beispiele |
|---|---|---|
| Bug-Report | Issue mit Bug-Template anlegen | Plugin zeigt falsches Layout, Action wirft Fehler |
| Feature-Request | Issue mit Feature-Template | „Filter nach Issue-Assignee", neuer Shortcode-Parameter |
| Code-Beitrag | Pull-Request | Bugfix, kleine Verbesserung, neue Inventory-Source |
| Übersetzung | Pull-Request mit `.po`-Datei | `de_DE`, `de_CH`, `en_US` für UI-Strings |
| Doku | Pull-Request mit README- oder docs-Änderungen | Tippfehler, Klarstellung, Beispiele |

## Repo-Setup

```bash
git clone https://github.com/rfluethi/Training-Translation-Tracker-Inventory-Plugin.git
cd Training-Translation-Tracker-Inventory-Plugin
```

Das Repo enthält:

- `action/`, Python-Code für die GitHub Action
- `wp-plugin/`, WordPress-Plugin
- `.github/workflows/`, Build- und Release-Workflows
- `build-plugin-zip.sh`, Plugin-ZIP bauen (lokal)

Doku-Suite (komponentenübergreifend):

- [docs/Architektur.md](docs/Architektur.md), System-Architektur und Designentscheidungen
- [docs/Developer.md](docs/Developer.md), Code-Setup, Tests, Erweiterungen
- [docs/Operations.md](docs/Operations.md), Releases, Token-Pflege, Failure-Recovery
- [docs/User-Guide.md](docs/User-Guide.md), Plugin-Bedienung und Issue-Pflege
- [action/README.md](action/README.md), kurze Action-spezifische Notizen

## Action entwickeln (Python)

### Setup

```bash
cd action
python -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

### Tests laufen lassen

```bash
cd action
python -m pytest tests/ -v
```

Tests sollten **immer grün** sein vor jedem Commit.

### Action lokal testen ohne GitHub-Token

```bash
cd action
python -m src.build --skip-issues   # baut tracker.json aus inventory-cache.json, ohne Issues
```

Das produziert `tracker.json`, `last-run.md` und `data-hygiene.md` lokal, alle drei Files liegen unter `.gitignore` und werden nie committed.

### Inventory-Cache nachziehen

Wenn du neue URLs in `scope.yml` einträgst, musst du den Inventory-Cache lokal aktualisieren (die Action selbst macht das nicht, wäre zu rate-limited auf den GitHub-Runner-IPs):

```bash
cd action
python -m src.build --refresh-cache   # holt nur fehlende URLs
git add scope.yml inventory-cache.json
git commit -m "Scope: neue URLs"
```

### Code-Style

- Python 3.10+
- Ruff für Linting (`ruff check src tests`)
- Type-Hints überall möglichst verwenden
- Tests parallel zum Code anlegen (`tests/test_<modul>.py`)

## Plugin entwickeln (PHP)

### Setup

```bash
# Symlink ins WordPress-Plugin-Verzeichnis (für lokale WP-Installation)
ln -s "$(pwd)/wp-plugin" /path/to/wp-content/plugins/training-translation-tracker
```

Oder bei jedem Test ein ZIP bauen:

```bash
./build-plugin-zip.sh
# → ~/Desktop/training-translation-tracker.zip
```

### Code-Style

- WordPress Coding Standards (siehe [WordPress.org Handbook](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/))
- Alle User-sichtbaren Strings via `__()`/`_e()`/`esc_html__()` für i18n
- Inline-Kommentare auf Deutsch oder Englisch (konsistent in einer Datei)
- Vendor-Code (z. B. JS-Bibliotheken) immer mit Klarem Hinweis auf Herkunft

### Versionierung

Bei jeder Änderung, die ein Release rechtfertigt, drei Stellen synchron updaten:

1. `wp-plugin/training-translation-tracker.php`, `Version:` im Header
2. `wp-plugin/training-translation-tracker.php`, `TTT_VERSION`-Konstante
3. `wp-plugin/readme.txt`, `Stable tag:`

Plus Eintrag im Changelog (`wp-plugin/readme.txt`).

### Release

```bash
git tag v0.2.4        # Beta: 0.x.y
git push --tags
```

Der Release-Workflow baut automatisch das ZIP und veröffentlicht es als
GitHub-Release.

## Dokumentation

Die Dokumentation liegt auf Top-Level unter `docs/`:

- `Architektur.md`, System-Architektur, Datenmodell, Entscheidungen
- `Developer.md`, Code-Setup, Module, Tests, Erweiterungspunkte
- `Operations.md`, Releases, Token, Failure-Recovery
- `User-Guide.md`, Plugin-Settings, Shortcodes, Frontend-Bedienung, Issue-Pflege
- `Issue-Vorlagen-DACH.md`, Vorlagen fürs Anlegen von Übersetzungs-Issues (Lesson, Handbook-Text, Handbook-Video)

Doku-Beiträge sind willkommen, Tippfehler-Fixes, klarere Erklärungen, Beispiele.

## Pull-Request-Prozess

1. **Fork** des Repos auf GitHub
2. **Branch** vom `main` aus erstellen mit beschreibendem Namen:
   - `fix/popover-positioning`
   - `feat/csv-export`
   - `docs/contributing-guide`
3. **Commits** mit klaren Nachrichten, Imperativ, kurz:
   - „Fix: Popover wird abgeschnitten am rechten Bildschirmrand"
   - „Add: CSV-Export für Tracker-Items"
4. **Tests** laufen lassen (Action: `pytest`, Plugin: manueller Smoke-Test)
5. **Pull-Request** auf `main` öffnen mit Beschreibung:
   - Was wird geändert?
   - Warum?
   - Wie getestet?
6. Bei Review-Feedback iterieren, keine Force-Pushes auf den PR-Branch wenn schon Review begonnen wurde.

## Andere Locales adaptieren

Andere Sprachräume können diesen Tracker für ihre eigene Locale nutzen:

1. **Fork** des Repos
2. `action/scope.yml`: `locale: French` (oder die jeweilige Project-V2-Locale)
3. `action/scope.yml`: deine Pathway- und URL-Liste eintragen
4. `inventory-cache.json` lokal mit `--refresh-cache` befüllen
5. GitHub-Secret `GH_PAT_PROJECT_READ` mit eigenem PAT setzen (Project-V2-Read-Scope)
6. Plugin-Header in `wp-plugin/training-translation-tracker.php` anpassen
   (eigener Plugin-Name, Author, Project-URI)
7. Texte in `docs/` und alle Locale-Bezugs-Strings übersetzen
8. Plugin-ZIP bauen und in der eigenen Site installieren

Pull-Requests, die generische Verbesserungen zurückgeben (z. B. neue Inventory-Sources, neue Shortcode-Optionen), sind willkommen, Locale-spezifische Änderungen aber bitte im eigenen Fork lassen.

## Verhaltenskodex

Wir folgen dem [WordPress Community Code of Conduct](https://make.wordpress.org/community/handbook/community-code-of-conduct/). Kurzform: respektvoll, hilfsbereit, konstruktiv. Wer beleidigt, ausschließt oder Hassrede verbreitet, wird ausgeschlossen.
