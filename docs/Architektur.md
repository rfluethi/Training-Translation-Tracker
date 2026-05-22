# Architektur, Training Translation Tracker

> **Zielgruppe:** Tech-Leads, neue Mitarbeitende, andere Locale-Teams, alle die das System verstehen wollen, bevor sie hineinarbeiten.
> **Status:** Architektur ist eingefroren mit Schema-Version `1`. Letzter Schliff per Plugin-Version `0.2.4` (2026-05).

## 1. Problem und Lösungsidee

### Was ist gefordert?

Das DACH-Übersetzungsteam soll auf einer WordPress-Seite jederzeit sehen können, welche Inhalte von `learn.wordpress.org` und aus dem Training-Handbook bereits ins Deutsche übersetzt sind, welche in Arbeit sind und, entscheidend, welche **noch gar nicht begonnen** wurden. Pro Inhalt sollen einzelne Komponenten (Text, Untertitel, Quiz, Video …) sichtbar sein, inklusive Creator und Reviewer.

### Was war das Problem mit der Vorgänger-Lösung?

Das frühere Plugin las GitHub-Issues *live* aus WordPress aus und parste dort Markdown-Status-Tabellen. Drei Konsequenzen:

1. **Hoher Pflegeaufwand pro Issue**, Original-URL, Übersetzungs-URL, beide Titel, WP.tv, YouTube, Order-Wert, Status-Tabelle. Tippfehler bricht die Anzeige stumm.
2. **Langsame Seite**, pro Aufruf 1 GraphQL-Call gegen GitHub + bis zu 4 Calls gegen learn.wordpress.org pro Lektion, danach PHP-Markdown-Parsing.
3. **Strukturelle Lücke**, Inhalte ohne Issue waren unsichtbar. Genau jene aber, für die das Team wissen will *dass* sie noch zu tun sind.

### Lösungsansatz

**Trennung in drei Komponenten:**

```
┌──────────────────────────┐   ┌──────────────────────────┐   ┌──────────────────────────┐
│  GitHub-Issues           │   │  GitHub Action (Python)  │   │  WordPress-Plugin (PHP)  │
│  WordPress/Learn         │──►│  baut tracker.json auf   │──►│  liest tracker.json      │
│  Project V2 #104         │   │  data-Branch alle 12 h   │   │  rendert Shortcode       │
│  Locale = German         │   │                          │   │                          │
└──────────────────────────┘   └──────────────────────────┘   └──────────────────────────┘
         Pflege                       Aggregation                       Anzeige
       durch Team                  + Schema-Validierung               im Frontend
```

- **Issues bleiben das Datenmodell**, die Markdown-Status-Tabelle wird nicht abgelöst. Was sich ändert: das Parsen wandert in die Action.
- **Action friert den Stand alle 12 h ein**, eine statische JSON-Datei auf einem Branch.
- **Plugin macht keinen einzigen externen API-Call mehr** außer einem statischen JSON-Fetch.

Resultat: schnelle Webseite, weniger Pflegeaufwand pro Issue, vollständige Übersicht inklusive Lücken, einfache Erweiterung um neue Pathways oder Locales.

## 2. Drei-Komponenten-Pipeline im Detail

### 2.1 GitHub-Issues, Datenmodell

Jedes Übersetzungs-Vorhaben hat ein Issue im Repo `WordPress/Learn`. Issues müssen drei Anforderungen erfüllen, damit sie vom Tracker aufgenommen werden:

1. Im DACH-Projekt-Board mit Custom-Field `Locale = German` markiert.
2. Original-URL in kanonischer Form (`https://`, lowercase, Trailing-Slash, ohne Query/Fragment, ohne `www.`).
3. Status-Tabelle zwischen `<!-- TRANSLATION-STATUS-START -->` und `<!-- TRANSLATION-STATUS-END -->` mit den definierten Statuswerten.

Der Vorlagensatz dafür liegt in [Issue-Vorlagen-DACH.md](Issue-Vorlagen-DACH.md).

Das Issue selbst bleibt der Single Point of Truth für Status-Änderungen. Re-Translations finden im selben Issue statt; Issues werden nie endgültig „done" geschlossen und wieder geöffnet.

### 2.2 GitHub Action, Aggregation

Die Action im Repo `Training-Translation-Tracker-Inventory-Plugin` läuft auf drei Auslösern:

- **Schedule**, `cron: "0 */12 * * *"` (alle 12 Stunden).
- **`workflow_dispatch`**, manueller Knopf in der GitHub-UI.
- **`push`** auf `main` mit Filter auf `scope.yml` und `component-templates.yml`, sofortiges Re-Build nach Konfigurations-Änderungen.

Pipeline-Schritte:

1. **Inventory laden**, aus dem committeten Cache (`action/inventory-cache.json`) oder via `--refresh-cache` lokal vom Maintainer aufgefrischt.
2. **Issues holen**, GraphQL-Query gegen das DACH-Project-Board, filtert auf `Locale = German`. Pagination ist Standard.
3. **Issues parsen**, strikter Parser, verlangt die HTML-Marker um die Status-Tabelle.
4. **Matching**, Inventory-Items werden per kanonischer URL mit Issues gematcht. Items ohne Issue bleiben mit Status `open`.
5. **Gruppierung**, gemäß `scope.yml` werden Items in Pathway → Course → Section eingeordnet. Items mit Issue aber ohne Scope-Eintrag landen als `orphan`.
6. **Schema-Validation**, gegen `tracker.schema.json` v1.
7. **Commit** auf den `data`-Branch als `tracker.json` (Force-Push, keine History).

Ausgabe: `tracker.json` + `last-run.md` (mensch-lesbarer Lauf-Bericht).

### 2.3 WordPress-Plugin, Anzeige

Plugin lebt im selben Mono-Repo unter `wp-plugin/`. Architektur-Prinzipien:

- **Keine eigenen API-Calls** außer einem `wp_remote_get` gegen die `tracker.json`-URL.
- **Transient-Cache** für die Default-Dauer von 12 h, konfigurierbar 1 bis 168 h.
- **Last-Good-Fallback**, separater Transient ohne TTL, der bei jedem erfolgreichen Fetch überschrieben wird. Bei Fehlern wird der letzte erfolgreiche Stand mit Hinweis-Span an Admins angezeigt.
- **Schlanker Renderer**, Shortcode `[translation_tracker]` mit Attributen für Pathway-Filter, Stats-Header, Orphan-/Handbook-Sichtbarkeit.
- **Inline-First-CSS**, alle Styles werden als `<style id="ttt-inline-critical">`-Block im Shortcode-Output geschrieben, damit Page-Builder/Cache-Plugins das Layout nicht entfernen können.

## 3. Datenmodell `tracker.json`

Vollständiges JSON-Schema in [`action/schemas/tracker.schema.json`](../action/schemas/tracker.schema.json).

### Top-Level

```json
{
  "schema_version": 1,
  "generated_at": "2026-05-22T08:00:00Z",
  "stats": {
    "total_items": 29, "done": 4, "review": 1, "wip": 1, "open": 23, "na": 0
  },
  "groups": [ /* Pathway / Handbook / Orphan-Gruppen */ ]
}
```

`schema_version` ist Plugin-seitig hart-kodiert (Konstante `TTT_TRACKER_SCHEMA_VERSION`). Plugin lehnt unbekannte Major-Versionen ab, Schutz vor Schema-Drift.

### Gruppen-Typen

- **`pathway`**, Lernpfad mit Courses → Sections → Items.
- **`handbook`**, Training-Handbook-Seiten, gruppiert nach Top-Level-Section-Slug.
- **`orphan`**, Items mit Issue, aber ohne Scope-Eintrag (`outside_scope`) oder Inventar-Match (`missing_in_inventory`).

### Items

Jedes Item trägt:

- Metadaten, EN/DE-Titel und -URLs, optional WP.tv/YouTube (je Original/Übersetzung getrennt).
- Komponenten-Array mit `name`, `status`, `creator`, `reviewer` pro Komponente.
- `overall_status` aus der Status-Aggregation berechnet.
- `issue`-Objekt (`number`, `url`, `state`), Verweis auf das GitHub-Issue.
- Marker, `draft_original`, `duplicate_issues`, `parse_error`, `orphan_reason`.

### `overall_status`-Algorithmus

Implementiert in der Action:

```
1. Alle Komponenten = "na"   → "na"
2. Alle Nicht-na   = "done"  → "done"
3. Mind. ein "review"        → "review"
4. Mind. ein "wip"           → "wip"
5. Sonst                     → "open"
```

Stats werden auf Item-Ebene aus `overall_status` berechnet. Die Komponenten-Stati werden im Frontend angezeigt, fließen aber nicht in die Stats-Aggregation ein.

## 4. Festgelegte Entscheidungen

Die folgenden Entscheidungen sind verbindlich für die Implementierung. Sie ergeben sich aus der ursprünglichen Konzeptions-Diskussion und sind über mehrere Phasen hinweg stabil geblieben.

### 4.1 Datenmodell und Matching

**4.1.1 URL-Normalisierung.** Kanonische Form für jedes URL-Matching: lowercase, `https`, mit Trailing-Slash, ohne Query-Parameter, ohne Fragment, ohne `www`-Subdomäne. Die Normalisierung wird in der Action zentral angewendet, sowohl für Inventar-URLs als auch für aus Issues extrahierte URLs.

**4.1.2 Items in mehreren Pathways.** Eine Lektion, die fachlich in mehreren Lernpfaden vorkommt, wird mehrfach angezeigt, einmal pro Pathway. Stats zählen jede Anzeige einzeln.

**4.1.3 Verwaiste Übersetzungen.** Existiert ein DACH-Issue zu einem Inhalt, der im Inventar nicht (mehr) gefunden wird, wird das Item weiter angezeigt und im Frontend als „verwaiste Übersetzung" markiert. Im `tracker.json` trägt es das Feld `orphan_reason: missing_in_inventory`.

**4.1.4 Mehrere Issues pro Item.** Pro Item (URL) und Sprache darf in GitHub nur **ein** Issue existieren. Bei Verstoß werden alle gefundenen Issues im `tracker.json` mitgeführt und im Frontend mit einem Warnsymbol „mehrfaches Issue" markiert. Die Bereinigung erfolgt manuell durch das Team.

**4.1.5 Originale im Status `draft`.** Lektionen / Seiten, deren englische Originalversion noch im Entwurfsstatus ist, werden ins Inventar aufgenommen und im Frontend mit dem Marker „Original noch nicht publiziert" gekennzeichnet. Heute praktisch unsichtbar, weil anonyme Requests gegen `wp/v2/lessons` keine Drafts sehen, `draft_original` bleibt immer `false`. Bei authentifizierten Requests wäre die Marker-Logik aktiv.

**4.1.6 `scope.yml`-Format.** Reine URL-Liste. Items, die übersetzt werden sollen, werden einzeln per URL eingetragen. Was nicht in `scope.yml` steht und auch kein DACH-Issue hat, erscheint nicht im Tracker. Issues ohne Scope-Eintrag landen unter „Sonstige" (`orphan_reason: outside_scope`). Spätere Erweiterungen (z. B. „alle Lessons aus diesem Course automatisch aufnehmen") sind bewusst auf eine v2 verschoben.

### 4.2 Status-Berechnung

**4.2.1 `overall_status`-Algorithmus** (siehe auch Sektion 3):

```
1. Alle Komponenten = "na"   → na
2. Alle Nicht-na    = "done" → done
3. Mind. ein "review"        → review
4. Mind. ein "wip"           → wip
5. Sonst                     → open
```

`overall_status` wird nur für Stats-Aggregation und Filterung verwendet.

**4.2.2 Stats und Komponentenanzeige.** Stats im Header werden auf **Item-Ebene** auf Basis von `overall_status` berechnet. Davon unabhängig werden in der Karte pro Item **alle Komponenten-Stati vollständig** angezeigt, diese gehen nicht in die Stats-Aggregation ein.

### 4.3 Schema-Versionierung und Item-Typ-Felder

**4.3.1 Schema-Version + Item-Typ-spezifische Felder.** `tracker.json` trägt `schema_version: 1` auf oberster Ebene. Plugin lehnt unbekannte Major-Versionen ab.

Pro Item-Typ unterscheidet sich das Set an Metadaten-Feldern. Das JSON-Schema dokumentiert pro `type`, welche Felder erlaubt sind. Plugin behandelt fehlende oder unbekannte Felder defensiv (Spalte leer / Symbol ausgeblendet).

**4.3.2 Migrations-Strategie bei Schema-Sprüngen.** Bei einem Major-Schema-Sprung erzeugt die Action vorübergehend parallel die alte und die neue Version unter `tracker.json` und z. B. `tracker.v2.json`. Sobald das Plugin migriert ist, wird die alte Datei entfernt.

### 4.4 Action-Operatives

**4.4.1 Repository-Sichtbarkeit.** Action-Repo öffentlich. Vorteile: kostenlose Action-Minuten, `tracker.json` ohnehin öffentlich, Transparenz für andere Locales.

**4.4.2 Action-Identität.** Kein separater Bot-Account. Die Action committet als `github-actions[bot]`. Der Workflow erhält `contents: write`-Permission auf den Output-Branch.

**4.4.3 Failure-Notification.** Standard-GitHub-Notification reicht zum Start. Slack-Webhook später optional.

**4.4.4 `scope.yml`-Validierung.** Action validiert nach jedem Lauf, dass jeder Eintrag aufgelöst werden konnte. Andernfalls Warnung im `last-run.md`.

**4.4.5 Branch-Strategie für `tracker.json`.** `data`-Branch wird per Force-Push aktualisiert. Jeder Lauf überschreibt den vorherigen Stand. Daten sind aus den GitHub-Issues jederzeit re-generierbar, keine History nötig.

**4.4.6 Token-Pflege.** `GH_PAT_PROJECT_READ` gehört aktuell dem Maintainer (Rico), ohne geplante Rotation. Bei späterem Umzug zu einem DACH-Team-Account einmaliger Wechsel. Details siehe [Operations.md](Operations.md).

**4.4.7 Trigger-Konfiguration.** Drei Auslöser:

- `schedule: cron: "0 */12 * * *"` (12-h-Takt).
- `workflow_dispatch`, manueller Knopf in der GitHub-UI.
- `push` auf `main` mit Filter `paths: ['scope.yml', 'component-templates.yml']`, sofortiges Re-Build nach Konfigurations-Änderungen.

### 4.5 Plugin-Operatives

**4.5.1 Plugin-Quelle.** Plugin-Code lebt im selben Mono-Repo unter `wp-plugin/`. Releases entstehen via `release-plugin.yml` bei Tag-Push (`v*`).

**4.5.2 Settings-Seite.** Eine WordPress-Settings-Page unter **Einstellungen → Translation Tracker** mit drei Feldern:

- URL der `tracker.json` (Default: `data`-Branch im Action-Repo).
- Cache-Dauer in Stunden (Default: 12, Min: 1, Max: 168).
- Button „Cache jetzt leeren" mit AJAX + Nonce + Capability-Check.

**4.5.3 Fehlerverhalten.** Bei API-Fehler (HTTP ≠ 2xx, JSON-Decode-Fehler, Schema-Mismatch) zeigt das Plugin den letzten erfolgreichen Stand (`last_good`-Transient ohne TTL). Admins sehen zusätzlich einen Hinweis-Span „(letzter erfolgreich gespeicherter Stand, aktueller Fetch schlug fehl)".

**4.5.4 Erst-Installation.** Bei Erst-Installation und leeren Caches macht der erste Shortcode-Aufruf einen synchronen `wp_remote_get` gegen die konfigurierte URL und füllt den Cache.

**4.5.5 CSS-Strategie.** Single Source of Truth (seit 0.3.2): Das gesamte Frontend-CSS wird ausschließlich als Inline-`<style id="ttt-inline-critical">`-Block im Shortcode-Output ausgegeben. Keine externe `style.css`, kein `wp_enqueue_style`. Tokens (`--ttt-*`) mit theme.json-Fallbacks für Brand-Farben, Status-Farben sind Plugin-fix.

### 4.6 Inhaltliche Festlegungen

**4.6.1 Locale-Filter im GitHub-Projekt-Board.** Issues werden im DACH-Projekt-Board mit Custom-Field `Locale = German` markiert. Die Action filtert auf diesen Wert.

**4.6.2 Komponenten-Set pro Item-Typ.** Definiert in `action/component-templates.yml`:

- `lesson`: text, thumbnails, video, subtitles, quiz, exercise, audio
- `lesson_plan`: text, thumbnails
- `tutorial`: text, thumbnails, video, subtitles
- `handbook_text`: text
- `handbook_video`: text, thumbnails, video, subtitles

### 4.7 Zeit, Lokalisierung, Pflege

**4.7.1 Sprache der Frontend-Strings.** Englisch als Quellsprache im Code (WP-Konvention), Übersetzungen via `.po`/`.mo` in `wp-plugin/languages/`. Aktuell ausgeliefert: Englisch (Default) und Deutsch (`de_DE`).

**4.7.2 Wartung.** Wartung erfolgt durch das Learn-WP-DACH-Team. Repo-Owner: Rico. GPL-v2, Mitwirkung über Issues / Pull Requests dokumentiert in [CONTRIBUTING.md](../CONTRIBUTING.md).

## 5. Repository-Layout

```
Repo-Root/                       # geklont aus GitHub
├── .github/workflows/
│   ├── build.yml                # baut tracker.json auf data-Branch
│   └── release-plugin.yml       # Plugin-ZIP-Release bei Tag-Push
├── action/                      # GitHub Action (Python)
│   ├── src/
│   │   ├── inventory/           # REST-Module pro Item-Typ + URL-Normalizer
│   │   ├── github/              # GraphQL-Client + Issue-Parser
│   │   ├── builder/             # Joiner, Stats, Output-Writer
│   │   └── build.py             # Einstiegspunkt
│   ├── tests/                   # pytest
│   ├── schemas/                 # JSON-Schemata (Laufzeit-Kopie)
│   ├── scope.yml                # Welche URLs sind im Scope
│   ├── component-templates.yml  # Default-Komponenten pro Item-Typ
│   ├── inventory-cache.json     # Committed Inventory-Snapshot
│   └── requirements.txt
├── wp-plugin/                   # WordPress-Plugin (PHP)
│   ├── training-translation-tracker.php   # Bootstrap, Konstanten
│   ├── uninstall.php
│   ├── includes/
│   │   ├── class-settings.php   # Settings-Seite + AJAX-Clear-Cache
│   │   ├── class-fetcher.php    # wp_remote_get + Transient-Cache
│   │   └── class-renderer.php   # Shortcode + HTML-Output
│   ├── assets/
│   │   ├── tracker.js           # Frontend-JS (Filter/Suche/Collapse)
│   │   └── admin.js             # Settings-Seite-JS
│   ├── languages/               # i18n-Dateien (.pot/.po/.mo)
│   ├── readme.txt               # WordPress-Standard-Readme
│   └── LICENSE
├── docs/                        # Dokumentation (du bist hier)
│   ├── Architektur.md
│   ├── Developer.md
│   ├── Operations.md
│   ├── User-Guide.md
│   └── Issue-Vorlagen-DACH.md
├── build-plugin-zip.sh          # lokales Build-Skript
├── sync-schemas.py              # Schema-Sync-Tool für die Maintenance
├── CONTRIBUTING.md
├── README.md                    # Mono-Repo-Übersicht
└── LICENSE
```

Die JSON-Schemata in `action/schemas/` sind die Laufzeit-Kopien. Contributors editieren sie direkt im Repo.

## 6. Fehler-Toleranz und Failure-Modes

Die Architektur ist bewusst auf Robustheit gegen Einzelfehler ausgelegt.

### Action

- Schlägt ein API-Call fehl, wird die letzte erfolgreiche `tracker.json` **nicht** überschrieben → Webseite zeigt weiter den letzten Stand statt einer leeren Tabelle.
- Pro Run wird `data/last-run.md` committet, das Warnungen, Parse-Fehler und Statistik enthält.
- Issues mit kaputter Status-Tabelle wandern mit `parse_error: true` ins JSON, statt den ganzen Lauf abzubrechen.

### Plugin

- Bei API-Fehler (HTTP ≠ 2xx, JSON-Decode-Fehler, Schema-Mismatch) zeigt das Plugin den letzten erfolgreichen Stand (`last_good`-Transient ohne TTL).
- Admins sehen zusätzlich einen Hinweis-Span „(letzter erfolgreich gespeicherter Stand, aktueller Fetch schlug fehl)".
- Bei Erst-Installation mit leerem Cache macht der erste Shortcode-Aufruf einen synchronen `wp_remote_get` und füllt den Cache.

### Bekannte Risiken

| Risiko | Wahrscheinlichkeit | Auswirkung | Gegenmaßnahme |
|---|---|---|---|
| Markdown-Status-Tabellen in Issues brüchig (Tippfehler) | mittel | mittel | Robuster Parser + `parse_error`-Marker + Hinweis im `last-run.md` |
| Mehrere Issues pro Item in der Praxis | mittel | gering | Frontend-Marker + manuelle Bereinigung durch Team |
| Page-Builder/Cache-Plugin bricht JS-Loading beim End-User | gering | mittel | Inline-`<script src>`-Strategie + Diagnose-Anleitung |
| Plugin-Checker neuer Versionen bringt zusätzliche Regeln | mittel | gering | Bei jedem Release einmal durchlaufen lassen |

## 7. Erweiterungspunkte

### Neue Item-Typen oder Komponenten

In `action/component-templates.yml` ergänzen. Wenn das Plugin ein neues Icon zeigen soll: `COMPONENT_ICONS` in `class-renderer.php` erweitern. Plugin behandelt unbekannte Komponenten defensiv (Icon ausgeblendet, kein Crash), somit funktioniert die Action-Erweiterung auch ohne Plugin-Update.

### Andere Locales

Das Plugin ist locale-agnostisch, es rendert, was in der `tracker.json` steht. Für eine neue Locale:

1. Action-Fork des Repos.
2. `scope.yml` mit der Locale-spezifischen URL-Liste füllen.
3. Im Workflow den Issue-Filter auf das passende GitHub-Projekt + Label ändern.
4. Plugin-Settings: URL auf den neuen `data`-Branch zeigen lassen.

Keine PHP-Änderung nötig.

### Neue Inhaltsquellen

Jede Inhaltsquelle ist als Modul mit Schnittstelle `(scope_entry) → list[InventoryItem]` umgesetzt. Aktuell:

- `learn_wp_lesson` (REST `/wp-json/wp/v2/lessons`)
- `learn_wp_lesson_plan`
- `learn_wp_tutorial` (`wporg_workshop`)
- `handbook_section` (REST `/wp-json/wp/v2/handbook` auf `make.wordpress.org/training/`)

Neue Quelle anschließen = neues Modul + Eintrag in `component-templates.yml`. Keine Plugin-Änderung.

## 8. Glossar

| Begriff | Bedeutung |
|---|---|
| **Inventar** | Vollständige Liste aller Inhalte aus learn.wordpress.org und dem Handbuch, die in Scope sind |
| **Item** | Ein einzelner übersetzbarer Inhalt (Lektion, Lesson Plan, Handbuch-Seite usw.) |
| **Komponente** | Bestandteil eines Items, das einzeln übersetzt wird (text, video, subtitles, …) |
| **Scope** | Manuell gepflegte Auswahl, welche Pathways / Sections der Tracker abdeckt |
| **Status-Tabelle** | Markdown-Tabelle im Issue-Body zwischen `TRANSLATION-STATUS-START`/`-END` |
| **Orphan-Issue** | Issue, dessen Original-Inhalt außerhalb des Scopes liegt (`orphan_reason: outside_scope`) |
| **Verwaiste Übersetzung** | Issue existiert, Original-Inhalt nicht mehr im Inventar (`orphan_reason: missing_in_inventory`) |
| **Duplikat** | Mehrere Issues mit derselben Original-URL und Sprache, sollte nicht vorkommen, wird sichtbar markiert |
| **Action** | GitHub-Actions-Workflow, der `tracker.json` erzeugt |
| **`data`-Branch** | Branch im Repo, auf dem die Action `tracker.json` ablegt, vom Plugin via raw.githubusercontent.com abgerufen |

## Weiterführende Dokumente

- Entwickler-Sicht (Code, Setup, Tests, Erweiterungen): [Developer.md](Developer.md)
- Betrieb (Releases, Token-Pflege, Failure-Recovery): [Operations.md](Operations.md)
- Benutzersicht (Plugin-Settings, Shortcodes, Frontend-Bedienung): [User-Guide.md](User-Guide.md)
- Issue-Vorlagen für DACH-Team: [Issue-Vorlagen-DACH.md](Issue-Vorlagen-DACH.md)
