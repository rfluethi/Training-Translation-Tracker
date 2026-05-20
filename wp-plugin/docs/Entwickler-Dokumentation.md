# Entwickler-Dokumentation — Training Translation Tracker

> Architektur, Datenmodell, Code-Struktur und Erweiterungspunkte des
> WordPress-Plugins.
>
> **Zielgruppe:** Entwickler, die das Plugin warten, erweitern oder für
> andere Locales adaptieren wollen.
> **Bezug:** [Benutzerhandbuch.md](Benutzerhandbuch.md) für die End-User-Sicht.

---

## Inhaltsverzeichnis

1. [Architektur-Überblick](#architektur-überblick)
2. [Datenfluss End-to-End](#datenfluss-end-to-end)
3. [Repository-Struktur](#repository-struktur)
4. [Plugin-Komponenten](#plugin-komponenten)
5. [Datenmodell `tracker.json`](#datenmodell-trackerjson)
6. [Frontend-Output](#frontend-output)
7. [JavaScript: Filter, Suche, Collapse](#javascript-filter-suche-collapse)
8. [CSS: Inline-First-Strategie](#css-inline-first-strategie)
9. [Shortcode-API](#shortcode-api)
10. [Erweiterungspunkte](#erweiterungspunkte)
11. [Build und Deployment](#build-und-deployment)
12. [Testing](#testing)
13. [Bekannte technische Schulden](#bekannte-technische-schulden)
14. [Versionshistorie](#versionshistorie)

---

## Architektur-Überblick

Das Gesamtsystem ist eine **Drei-Komponenten-Pipeline**:

```
┌─────────────────────────┐    ┌──────────────────────────┐    ┌─────────────────────────┐
│  GitHub Issues (DACH)   │    │  GitHub Action (Python)  │    │  WordPress-Plugin (PHP) │
│  WP-Translations-DACH   │───►│  build.py auf data-Branch│───►│  liest tracker.json     │
│  Locale=German          │    │  alle 12 h + manuell     │    │  rendert Shortcode      │
└─────────────────────────┘    └──────────────────────────┘    └─────────────────────────┘
       Pflege durch                 Aggregation +                  Anzeige im
       Übersetzer                   Schema-Validierung             Frontend
```

**Entwurfsprinzip:** das Plugin macht **keine** eigenen API-Calls gegen
GitHub oder WordPress/Learn. Die gesamte Logik (Inventory laden,
Issues parsen, Status berechnen) sitzt in der Action. Das Plugin ist
ein dünner Renderer mit Cache.

**Vorteile:**

- Plugin ist klein (~1500 LOC inkl. Tests) und wartbar.
- Frontend-Performance: ein HTTP-Call alle 12 h gegen GitHub-Raw — keine
  Live-Aggregation, keine GraphQL-Queries.
- Schema-Versionierung trennt Plugin- und Action-Releases sauber.
- Andere Locales können dieselbe Action-Logik mit anderer `scope.yml`
  und anderem Issue-Filter wiederverwenden.

---

## Datenfluss End-to-End

### 1. Übersetzer pflegen Issues

Im DACH-Projekt-Board werden Issues mit der DACH-Issue-Vorlage angelegt.
Die Vorlage enthält:

- `Link to original content:` — kanonische URL (lowercase, https, mit
  Trailing-Slash).
- `Link to translated content:` — DE-URL.
- `Link to original/translated WordPress.tv recording:` (optional).
- `Link to original/translated YouTube recording:` (optional).
- Status-Tabelle pro Komponente (text/thumbnails/video/subtitles/quiz/
  exercise/audio).

Format der Status-Tabelle:

```markdown
<!-- TRANSLATION-STATUS-START -->
| Component  | Status | Creator    | Reviewer  |
|------------|--------|------------|-----------|
| text       | done   | @rfluethi  | @Ursha-wp |
| thumbnails | done   | @rfluethi  | @Ursha-wp |
| video      | wip    | @rfluethi  |           |
| subtitles  | open   |            |           |
| quiz       | na     |            |           |
| exercise   | na     |            |           |
| audio      | na     |            |           |
<!-- TRANSLATION-STATUS-END -->
```

Genaues Format siehe [`Issue-Vorlage-DACH.md`](../../Konzept/Issue-Vorlage-DACH.md).

### 2. Action baut `tracker.json`

Im Repo
[`Training-Translation-Tracker-Inventory-Plugin`](https://github.com/rfluethi/Training-Translation-Tracker-Inventory-Plugin)
läuft der Workflow `build.yml` alle 12 Stunden und beim Push auf `main`.

Pipeline-Schritte (gekürzt):

1. **Inventory laden** — entweder aus dem mitcommitteten Cache
   (`inventory-cache.json`) oder via `--refresh-cache` per Live-Fetch
   gegen `learn.wordpress.org/wp-json/wp/v2/lesson` etc.
2. **Issues holen** — GraphQL gegen das DACH-Projekt-Board, filtert auf
   `Locale=German`. Pagination ist Standard.
3. **Issues parsen** — der `parser.py` extrahiert URLs und Status-
   Tabellen aus dem Issue-Body.
4. **Matching** — Inventory-Items werden per kanonischer URL mit
   Issues gematcht. Items ohne Issue bleiben mit Status `open` /
   alle Komponenten `open`.
5. **Gruppierung** — gemäß `scope.yml` werden Items in Pathway → Course
   → Section eingeordnet. Items mit Issue aber ohne Scope-Eintrag
   landen als `orphan` (Grund: `outside_scope` oder
   `missing_in_inventory`).
6. **Schema-Validation** — gegen `schemas/tracker.schema.json` v1.
7. **Commit auf `data`-Branch** als `tracker.json`.

### 3. Plugin lädt `tracker.json`

Beim Aufruf des Shortcodes (oder bei einem expliziten „Cache leeren"):

1. `TTT_Fetcher::get()` prüft den Transient-Cache.
2. Bei Cache-Miss: `wp_remote_get( $tracker_url )` — Default-URL zeigt
   auf den `data`-Branch via `raw.githubusercontent.com`.
3. JSON wird geparst und gegen `TTT_TRACKER_SCHEMA_VERSION` (Konstante
   im Plugin) validiert. Unbekannte Major-Versionen werden abgelehnt.
4. Bei Erfolg: in den Transient-Cache UND in einen separaten
   `last_good`-Transient ohne TTL (Fallback für Fetch-Fehler).
5. Bei Fehler: `last_good` wird zurückgegeben mit einer internen
   Fehlernotiz, die nur Admins zu sehen bekommen.

### 4. Renderer baut HTML

`TTT_Renderer::render_shortcode()`:

1. Inline-`<style>`-Block für die kritischen Layout-Regeln.
2. Tracker-`<div class="ttt-tracker">` mit UUID, damit mehrere Shortcodes
   auf einer Seite voneinander unabhängig bleiben.
3. Stats-Pillen als `<button data-filter-status="…">`.
4. Filter-Bar mit Suchfeld.
5. Pro Gruppe (Pathway/Handbook/Orphan) eine Sektion mit Karten.
6. Inline-`<script src="…tracker.js?ver=…">`-Tag am Ende für die
   JS-Interaktivität.

---

## Repository-Struktur

```
Training-Translation-Tracker-Inventory-Plugin/
├── Konzept/                            # Architektur-Doku, Schemata, Issue-Vorlagen
│   ├── Konzept.md                      # Ursprüngliche Konzeption
│   ├── Arbeitsplan.md                  # Aufgaben + aktueller Stand
│   ├── API-Befunde.md                  # WP-REST-API Befunde
│   ├── Issue-Vorlage-DACH.md           # Issue-Template
│   ├── Validierung-Phase-1.md          # Validierung der Action
│   ├── _attachments/                   # Screenshots, Layout-Mockups
│   └── schemas/                        # JSON-Schemata (Konzept-Original)
├── github/                             # GitHub Action (Python)
│   ├── src/                            # Source: inventory.py, parser.py, builder.py, …
│   ├── tests/                          # pytest-Tests
│   ├── schemas/                        # JSON-Schemata (Action-Kopie, synced mit Konzept/)
│   ├── scope.yml                       # Welche URLs gehören zum DACH-Scope
│   ├── inventory-cache.json            # Committed Inventory-Snapshot
│   ├── component-templates.yml         # Welche Komponenten gibt es pro Item-Typ
│   ├── build.py                        # Entry-Point der Action
│   └── .github/workflows/build.yml     # Workflow-Definition
├── wp-plugin/                          # WordPress-Plugin (PHP)
│   ├── training-translation-tracker.php   # Bootstrap, Konstanten, Activator
│   ├── uninstall.php                       # Cleanup beim Plugin-Löschen
│   ├── includes/
│   │   ├── class-settings.php          # Settings-Seite + Clear-Cache-AJAX
│   │   ├── class-fetcher.php           # wp_remote_get + Transient-Cache
│   │   └── class-renderer.php          # Shortcode + HTML-Output
│   ├── assets/
│   │   ├── style.css                   # Externe CSS (Backup zu Inline)
│   │   ├── tracker.js                  # Frontend-JS (Filter/Suche/Collapse)
│   │   └── admin.js                    # Settings-Seite-JS
│   ├── languages/                      # .pot/.po/.mo (geplant)
│   ├── docs/                           # Diese Dokumentation
│   ├── readme.txt                      # WordPress-Standard-Readme
│   ├── README.md                       # Repo-Übersicht
│   └── LICENSE
├── build-plugin-zip.sh                 # Plugin-Build-Skript
└── README.md                           # Projekt-Readme
```

---

## Plugin-Komponenten

### Bootstrap: `training-translation-tracker.php`

Definiert Konstanten, lädt Klassen, registriert Init-Hooks.

**Wichtige Konstanten:**

| Konstante | Zweck |
|---|---|
| `TTT_VERSION` | Aktuelle Plugin-Version. Wird in URL-Versionierung verwendet. |
| `TTT_PLUGIN_FILE` / `_DIR` / `_URL` | Pfad-Helfer. |
| `TTT_TRACKER_SCHEMA_VERSION` | Erwartete `schema_version` der `tracker.json`. Aktuell `1`. |
| `TTT_DEFAULT_TRACKER_URL` | Default-URL zum `data`-Branch. |
| `TTT_DEFAULT_CACHE_HOURS` | Default-Cache-TTL in Stunden (12). |
| `TTT_OPTION_KEY` | WP-Option-Key für die Settings (`ttt_settings`). |
| `TTT_TRANSIENT_KEY` | Transient-Key für den Live-Cache (`ttt_tracker_payload`). |
| `TTT_LAST_GOOD_KEY` | Transient-Key für den Last-Good-Fallback. |

### `class-settings.php` — Admin-Bereich

**Verantwortlichkeiten:**

- Settings-Seite unter **Einstellungen → Translation Tracker**.
- Felder für URL, Cache-Dauer, Clear-Cache-Knopf.
- AJAX-Endpoint `ttt_clear_cache` zum Leeren der Transients.
- Shortcode-Beispiel-Liste mit Copy-Button (verwendet `admin.js`).

**Settings-API:**

```php
TTT_Settings::get( 'tracker_url' );  // Liefert die konfigurierte URL.
TTT_Settings::get( 'cache_hours' );  // Liefert die Cache-Dauer.
```

Die gesamte Konfiguration liegt in einer einzigen WP-Option (`ttt_settings`)
als assoziatives Array. Vermeidet das Spreading mehrerer Options-Rows
im `wp_options`-Table.

### `class-fetcher.php` — Datenladen

**Statische Klasse**, keine Instanzen.

**Zentraler API-Punkt:**

```php
$result = TTT_Fetcher::get();
// Returns:
// [
//   'payload' => array|null,              // Geparste tracker.json oder null.
//   'source'  => 'cache'|'fresh'|'last_good'|'none',
//   'error'   => string                   // Optionale Fehlernotiz (admin-only).
// ]
```

**Flow:**

```
get()
├── Transient hit?     yes → 'cache' return
├── URL leer?          yes → 'last_good' return mit Fehler
├── HTTP-Fetch         is_wp_error? → 'last_good' return mit Fehler
├── Schema-Validation  fail?        → 'last_good' return mit Fehler
└── Store + 'fresh' return
```

**Schema-Validation:** prüft nur `schema_version === TTT_TRACKER_SCHEMA_VERSION`.
Tiefere Validation ist nicht nötig, weil die Action bereits gegen das
JSON-Schema validiert. Plugin vertraut darauf.

**Last-Good-Pattern:** der zweite Transient `TTT_LAST_GOOD_KEY` hat **kein TTL**
und wird nur bei erfolgreichen Fetches überschrieben. Beim Cache-Ablauf
versucht das Plugin einen frischen Fetch — schlägt der fehl, liefert es
den Last-Good zurück. Admin sieht im Frontend einen Hinweis-Span
„(letzter erfolgreich gespeicherter Stand — aktueller Fetch schlug fehl)".

### `class-renderer.php` — Frontend-Output

Größte Klasse. Verantwortlich für die HTML-Erzeugung.

**Öffentliche Methoden:**

- `render_shortcode( $atts )` — Shortcode-Handler.
- `enqueue_frontend_assets()` — registriert die externe CSS via
  `wp_enqueue_style`. JS wird **nicht** via `wp_enqueue_script` geladen,
  sondern direkt im Shortcode-Output als `<script src>`-Tag (siehe
  [Bekannte technische Schulden](#bekannte-technische-schulden)).

**Private Methoden** (kurzer Überblick):

| Methode | Wofür |
|---|---|
| `render_inline_styles()` | Inline-`<style>`-Block am Anfang. |
| `render_inline_script()` | `<script src="…tracker.js">`-Tag am Ende. |
| `render_empty( $error )` | Fallback wenn `payload === null`. |
| `render_payload(…)` | Header + Gruppen-Schleife. |
| `render_stats(…)` | Stats-Pillen mit `data-filter-status`. |
| `render_filter_bar()` | Suchfeld im Header. |
| `render_group( $group )` | Pathway/Handbook/Orphan-Container. |
| `render_course( $course, $key )` | Course innerhalb Pathway. |
| `render_section( $section, $key )` | Section mit collapse-Toggle. |
| `render_item_list( $items )` | `<div class="ttt-cards">`-Container. |
| `render_item( $item )` | Eine `<article class="ttt-card">`. |
| `render_card_media_row( $wptv, $youtube )` | WP.tv/YouTube-Zeile pro Spalte. |
| `render_component_icon( $name, $comp )` | SVG-Icon für eine Komponente. |
| `collect_markers( $item )` | Orphan/Parse-Error/Duplicate/Draft-Marker. |
| `group_passes_filter(…)` | Shortcode-Attribut-Logik. |

**Konstanten:**

- `self::COMPONENT_ICONS` — Material-Icons-SVG-Paths pro Komponente.
- `self::COMPONENT_ORDER` — kanonische Reihenfolge der Icons im Footer.

---

## Datenmodell `tracker.json`

Vollständiges JSON-Schema: [`schemas/tracker.schema.json`](../../Konzept/schemas/tracker.schema.json).

### Top-Level

```json
{
  "schema_version": 1,
  "generated_at": "2026-05-19T08:00:00Z",
  "stats": {
    "total_items": 29,
    "done": 4,
    "review": 1,
    "wip": 1,
    "open": 23,
    "na": 0
  },
  "groups": [ /* Pathway / Handbook / Orphan-Gruppen */ ]
}
```

### Group-Typen

```json
{
  "type": "pathway",
  "slug": "user",
  "label": "Beginner WordPress User",
  "courses": [ … ]
}
```

```json
{
  "type": "handbook",
  "slug": "handbook",
  "label": "Handbook",
  "sections": [ … ]
}
```

```json
{
  "type": "orphan",
  "slug": "orphan",
  "label": "Sonstige (außerhalb Scope / verwaist)",
  "items": [ … ]
}
```

### Course

```json
{
  "slug": "start-using-wordpress",
  "label": "Start Using WordPress",
  "sections": [ … ]
}
```

### Section

```json
{
  "slug": "get-started-with-wordpress",
  "label": "Get Started With WordPress",
  "items": [ … ]
}
```

### Item (Standard)

```json
{
  "title_en": "Introduction to WordPress",
  "title_de": "Einführung in WordPress",
  "url_en":  "https://learn.wordpress.org/lesson/introduction-to-wordpress/",
  "url_de":  "https://learn.wordpress.org/lesson/einfuhrung-in-wordpress/",
  "url_wptv_en":    "https://wordpress.tv/2024/01/foo-en/",
  "url_wptv_de":    "https://wordpress.tv/2024/01/foo-de/",
  "url_youtube_en": "https://www.youtube.com/watch?v=foo-en",
  "url_youtube_de": "https://www.youtube.com/watch?v=foo-de",
  "overall_status": "wip",
  "components": [
    { "name": "text", "status": "done", "creator": "rfluethi", "reviewer": "Ursha-wp" },
    { "name": "video", "status": "wip", "creator": "rfluethi", "reviewer": "" }
  ],
  "issue": {
    "number": 2952,
    "url": "https://github.com/.../issues/2952",
    "state": "open"
  },
  "draft_original": false,
  "duplicate_issues": null,
  "parse_error": false
}
```

### Orphan-Item

Wie Standard-Item, aber mit zusätzlichem Feld:

```json
{
  "orphan_reason": "outside_scope"  // oder "missing_in_inventory"
}
```

### `overall_status`-Algorithmus

(Implementiert in der Action, das Plugin liest nur das Ergebnis):

```
1. Alle Komponenten = "na"   → "na"
2. Alle Nicht-na    = "done" → "done"
3. Mind. ein "review"        → "review"
4. Mind. ein "wip"           → "wip"
5. Sonst                     → "open"
```

---

## Frontend-Output

### HTML-Hierarchie

```html
<div class="ttt-tracker" id="ttt-{uuid}" data-tracker-id="ttt-{uuid}">
  <header class="ttt-header">
    <div class="ttt-stats">
      <button class="ttt-stat ttt-stat-total" data-filter-status="all">…</button>
      <button class="ttt-stat ttt-stat-done"  data-filter-status="done">…</button>
      …
    </div>
    <div class="ttt-filter-bar">
      <input type="search" class="ttt-search-input">
    </div>
  </header>

  <section class="ttt-group ttt-group-pathway" data-group-key="pathway-user">
    <h2 class="ttt-group-title">Beginner WordPress User</h2>

    <div class="ttt-course" data-course-key="pathway-user-start-using-wordpress">
      <h3 class="ttt-course-title">Start Using WordPress</h3>

      <div class="ttt-section" data-section-key="pathway-user-start-using-wordpress-get-started">
        <h4 class="ttt-section-title" role="button" tabindex="0" aria-expanded="true">
          <span class="ttt-section-toggle">▾</span> Get Started With WordPress
        </h4>
        <div class="ttt-section-body">
          <div class="ttt-cards">
            <article class="ttt-card ttt-overall-done"
                     data-status="done"
                     data-search="introduction to wordpress einführung in wordpress #2952">
              <div class="ttt-card-cols">
                <div class="ttt-card-col ttt-card-col-en">
                  <div class="ttt-card-label">Original</div>
                  <div class="ttt-card-title"><a href="…">Introduction to WordPress</a></div>
                  <div class="ttt-card-media">…</div>
                </div>
                <div class="ttt-card-col ttt-card-col-de">…</div>
              </div>
              <div class="ttt-card-footer">
                <div class="ttt-card-footer-left">
                  <a class="ttt-issue-number" href="…">#2952</a>
                  <span class="ttt-issue-state ttt-issue-state-open">open</span>
                </div>
                <div class="ttt-card-footer-right">
                  <span class="ttt-comp-icon ttt-comp-done" title="text · done …">
                    <svg width="18" height="18" viewBox="0 0 24 24"><path d="…"/></svg>
                  </span>
                  …
                </div>
              </div>
            </article>
            …
          </div>
        </div>
      </div>
      …
    </div>
  </section>

  <div class="ttt-no-results" hidden>Keine Treffer …</div>
</div>

<script src="…assets/tracker.js?ver=…" defer></script>
```

### CSS-Klassen-Konventionen

Alle Klassen sind mit `.ttt-`-Prefix. Innerhalb des Trackers:

- `.ttt-overall-{status}` — Status-Klasse auf der `.ttt-card`.
- `.ttt-comp-{status}` — Status-Klasse auf dem Komponenten-Icon.
- `.ttt-stat-{status}` und `.ttt-stat-active` — Stats-Pille.
- `.ttt-marker-{reason}` — Pill-Markierung für Orphan/Parse-Error/etc.
- `.ttt-section-collapsed` — Section zugeklappt (Body wird verborgen).

### Data-Attribute (vom JS verwendet)

- `.ttt-tracker[data-tracker-id]` — eindeutige Instanz-ID.
- `.ttt-stat[data-filter-status]` — `all|done|review|wip|open|na`.
- `.ttt-card[data-status]` — `overall_status` für JS-Filter.
- `.ttt-card[data-search]` — Lowercase-Suchstring (Titel EN + DE +
  Issue-Nummer).
- `.ttt-section[data-section-key]` — Schlüssel für localStorage
  (Collapse-State).
- `.ttt-course[data-course-key]`, `.ttt-group[data-group-key]` —
  analog für Container-Hierarchie.

---

## JavaScript: Filter, Suche, Collapse

`assets/tracker.js` — Vanilla-ES5+-IIFE, kein jQuery, ~270 Zeilen.

### Initialisierung

```js
if (window.__tttTrackerInitialized) return;
window.__tttTrackerInitialized = true;

function init() {
  var trackers = document.querySelectorAll('.ttt-tracker');
  for (…) setupTracker(trackers[i]);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
```

Die Guard-Variable verhindert Doppel-Init bei mehrfachem Skript-Inkluse.

### Pro Tracker-Container

Eine `setupTracker(root)`-Funktion bindet:

- Click-Handler auf alle `.ttt-stat[data-filter-status]`-Pillen.
- Input-Handler (debounced 150ms) auf das `.ttt-search-input`.
- Click/Keyboard-Handler auf alle Section-Titel.

### State

Pro Tracker-Instanz wird in `localStorage` gespeichert:

```
ttt:<trackerId>:state                 → {"status":"done","query":"wordpress"}
ttt:<trackerId>:collapse:<sectionKey> → "1" | "0"
```

Bei Page-Reload werden Filter-State und Collapse-Zustand wiederhergestellt.

### Filter-Logik

`applyFilters(root, state)` iteriert alle `.ttt-card`-Elemente:

```js
var matchStatus = (state.status === 'all') || (card.dataset.status === state.status);
var matchQuery = (state.query === '') || (card.dataset.search.indexOf(state.query) !== -1);

if (matchStatus && matchQuery) show(card); else hide(card);
```

Danach werden via `hideEmptyContainers()` Sections/Courses/Groups
zugeklappt, in denen keine sichtbare Karte mehr enthalten ist.

### Diagnostik

Beim Laden gibt das JS folgende Konsole-Meldungen aus:

```
[Translation Tracker] tracker.js loaded
[Translation Tracker] init — found N tracker(s)
```

Diese sind nützlich, um zu prüfen, ob das JS überhaupt zur Ausführung
kommt (Debug-Sequenz: F12 → Console).

---

## CSS: Inline-First-Strategie

Lehre aus den Iterationen 2.0.x:

In WordPress-Umgebungen mit Page-Buildern (Elementor, Divi, Beaver Builder)
und/oder Caching-Plugins (WP Rocket, LiteSpeed Cache) lädt eine via
`wp_enqueue_style` registrierte externe CSS-Datei **nicht zuverlässig**.
Der Plugin-Hook `has_shortcode( $post->post_content, … )` versagt
insbesondere, wenn der Shortcode in einem Builder-eigenen Meta-Feld
liegt statt im klassischen `post_content`.

### Lösung

Die kritischen Layout-Styles werden **inline** als `<style id="ttt-inline-critical">`-
Block direkt am Anfang der Shortcode-Ausgabe geschrieben. Die externe
`assets/style.css` wird zusätzlich enqueued, aber als Backup für
„Schöner-Effekte" (Hover-Transitions, Detail-Polishing), nicht für
das Kern-Layout.

### Specificity-Strategie

Alle Inline-Regeln tragen den `.ttt-tracker`-Parent-Prefix für höhere
Specificity gegen Theme-Rules. Wo Themes Standard-`!important`-Regeln
haben (z. B. `svg { max-width: 100% !important }`), gewinnt das Plugin
mit eigenen `!important`-Rules auf den entscheidenden Properties:

- `.ttt-tracker .ttt-card-cols { display: grid !important; grid-template-columns: 1fr 1fr !important; }`
- `.ttt-tracker .ttt-card-footer-right { display: flex !important; flex-direction: row !important; }`
- `.ttt-tracker .ttt-card-footer-right .ttt-comp-icon svg { width: 18px !important; height: 18px !important; }`

Zusätzlich tragen die `<svg>`-Elemente Inline-`style="width:18px;height:18px"`
als letzte Verteidigungslinie.

---

## Shortcode-API

### Basis-Shortcode

```text
[translation_tracker]
```

### Attribute

| Attribut | Default | Werte |
|---|---|---|
| `pathway` | `""` (alle) | Slug, mehrere durch Komma getrennt, case-insensitiv |
| `show_orphans` | `yes` ohne pathway, `no` mit pathway | `yes`/`no`/`true`/`false`/`1`/`0`/`on`/`off` |
| `show_handbook` | `yes` ohne pathway, `no` mit pathway | dito |
| `show_stats` | `yes` | dito |

### Smart-Defaults für `show_orphans` / `show_handbook`

Implementiert in `render_payload()`:

```php
$explicit = $atts['_explicit'];
$default_show_orphans  = $has_pathway ? false : true;
$show_orphans = isset( $explicit['show_orphans'] )
    ? $this->bool_attr( $atts['show_orphans'], $default_show_orphans )
    : $default_show_orphans;
```

Sprich: wenn der User `show_orphans` nicht explizit setzt **und** ein
`pathway`-Attribut hat, wird der Default automatisch auf `false`
gestellt. Wer das überschreiben will, schreibt explizit
`show_orphans="yes"`.

### Pathway-Matching

`group_passes_filter()` matched gegen drei Felder:

1. `group.slug` (lowercase)
2. `sanitize_title( group.label )`
3. Alle Strategien case-insensitiv.

Damit funktioniert `pathway="user"`, `pathway="beginner-wordpress-user"`
und `pathway="Beginner WordPress User"` alles gleich.

---

## Erweiterungspunkte

### Neue Item-Typen / Komponenten

Wenn ein neuer Item-Typ Komponenten haben soll, die das Plugin noch nicht
kennt (z. B. `slides` für Vortrags-Decks):

1. **Action**: in `component-templates.yml` den neuen Typ definieren.
2. **Plugin** `class-renderer.php`:
   - In `COMPONENT_ICONS` einen Material-Icons-SVG-Path ergänzen.
   - In `COMPONENT_ORDER` die Position in der Footer-Zeile festlegen.
3. **Inline-CSS** in `render_inline_styles()`: Farb-Klasse `.ttt-comp-slides`
   ergänzen (falls eigene Farbe gewünscht).

Da das Plugin Komponenten defensiv behandelt (unbekannte Namen werden
übersprungen), funktioniert es auch ohne Plugin-Update — nur eben ohne Icon.

### Andere Locales (z. B. `it_IT`, `fr_FR`)

Das Plugin selbst ist locale-unabhängig — es rendert, was in der
`tracker.json` steht. Für eine neue Locale:

1. **Action-Fork** des Action-Repos.
2. `scope.yml` mit der Locale-spezifischen URL-Liste füllen.
3. Im Workflow den Issue-Filter auf das passende GitHub-Projekt + Label
   ändern (statt `Locale=German` z. B. `Locale=Italian`).
4. Action laufen lassen — neue `tracker.json` auf eigenem `data`-Branch.
5. Plugin-Settings: URL auf den neuen `data`-Branch zeigen lassen.

Keine PHP-Änderung nötig.

### Custom Styling

Wenn ein Theme das Default-Styling überschreiben will, in der Child-Theme-
CSS oder in einem Customizer-Custom-CSS:

```css
.ttt-tracker .ttt-card {
    /* eigene Karten-Hintergrundfarbe etc. */
}
```

Die `.ttt-tracker`-Parent-Specificity ist hoch genug für leichten Override.
Bei Layout-Properties (`display: grid` etc.) muss das Custom-CSS mit
`!important` arbeiten, weil die Inline-Styles `!important` tragen.

### Custom Stats-Header

Wenn statt der Pillen ein eigener Header gewünscht ist:

```text
[translation_tracker show_stats="no"]
```

Dann den eigenen Header in HTML/Gutenberg oberhalb des Shortcodes anlegen.
Die Daten kann man aktuell nur direkt aus `tracker.json` extrahieren —
hier wäre ein zukünftiger Filter `ttt_payload_after_fetch` ein sinnvoller
Erweiterungspunkt (noch nicht implementiert).

---

## Build und Deployment

### Plugin-ZIP bauen

```bash
cd /path/to/Training-Translation-Tracker-Inventory-Plugin
./build-plugin-zip.sh
```

Skript-Logik:

1. `rsync` von `wp-plugin/` in temp-Verzeichnis, exkludiert `.git`,
   `.DS_Store`, `README.md`, `docs/`, `*.zip`.
2. Top-Level-Ordner im ZIP heißt `training-translation-tracker` (= Plugin-Slug).
3. ZIP landet auf `~/Desktop/training-translation-tracker.zip`.

### Installation in WordPress

**Über Admin-UI:**

1. Plugins → Plugin hochladen → ZIP wählen → installieren → aktivieren.

**Per WP-CLI:**

```bash
wp plugin install ~/Desktop/training-translation-tracker.zip --activate
```

**Manueller Symlink** (für Entwicklung):

```bash
cd wp-content/plugins
ln -s /path/to/Training-Translation-Tracker-Inventory-Plugin/wp-plugin training-translation-tracker
```

### Versionierung

Drei Stellen pro Release synchron halten:

| Datei | Wert |
|---|---|
| `wp-plugin/training-translation-tracker.php` Plugin-Header `Version:` | `2.1.1` |
| `wp-plugin/training-translation-tracker.php` Konstante `TTT_VERSION` | `2.1.1` |
| `wp-plugin/readme.txt` `Stable tag:` | `2.1.1` |

Semantic Versioning:

- Patch (`2.1.x`) — Bugfixes, keine Datenmodell-Änderungen.
- Minor (`2.x.0`) — Neue Features, abwärtskompatibel zur Vorgänger-
  `schema_version`.
- Major (`x.0.0`) — Schema-Sprung der `tracker.json`, Plugin lehnt
  alte Daten ab.

---

## Testing

### Action (Python)

```bash
cd github
python -m pytest tests/
```

Tests decken Inventory-Parser, Issue-Parser, Builder-Logik und URL-
Normalisierung ab.

### Plugin (PHP)

Stand 2.1.1: **keine automatisierten Tests**. Plugin wird per
Sichtprüfung in einer lokalen WordPress-Installation getestet.

**Mindest-Smoketest** bei jedem Release:

1. ZIP frisch bauen.
2. In einer Test-Installation altes Plugin löschen, neues hochladen,
   aktivieren.
3. Settings → URL prüfen, Cache leeren.
4. Test-Seite mit `[translation_tracker]` aufrufen.
5. Stats werden angezeigt? Karten-Layout korrekt? Komponenten-Icons da?
6. Browser-Console: keine roten Fehler.
7. Auf einer separaten Seite `[translation_tracker pathway="user"]` —
   zeigt nur diesen Pathway.

### Frontend-JS

Manuell:

1. F12 → Console.
2. `[Translation Tracker] tracker.js loaded` und `init — found N tracker(s)`
   sollten erscheinen.
3. Klick auf Stats-Pille → entsprechende Karten bleiben, andere
   ausgeblendet.
4. Tippe ins Suchfeld → Live-Filter.
5. Klick auf Section-Titel → klappt zu.

---

## Bekannte technische Schulden

### 1. JS-Loading nicht universell

Stand v2.1.1: das `<script src="…tracker.js" defer>`-Tag im Shortcode-
Output funktioniert in lokalen Test-Setups, aber nicht zuverlässig
in produktiven Page-Builder/Cache-Plugin-Kombinationen beim End-User.

**Hypothesen** (noch nicht verifiziert):

- `wpautop` oder ähnliche Content-Filter zerstören das `<script>`-Tag.
- Cache-Plugin liefert alte HTML-Response ohne den neuen Script-Tag.
- Theme strippt `<script>`-Tags aus Content (Sicherheits-Filter).

**Alternative Implementierung** (für nächste Iteration):

- Option A: JS via `wp_footer`-Hook ausgeben — sauber, aber funktioniert
  nur, wenn das Theme `wp_footer()` aufruft.
- Option B: `wp_add_inline_script( 'jquery-core', $js )` — hängt am
  jQuery-Handle, fast immer aktiv.
- Option C: Filter komplett serverseitig über Query-Parameter
  (`?ttt_filter=done`) — keine JS-Abhängigkeit. Verliert Live-Suche
  und Collapse-State, gewinnt Robustheit.

### 2. Keine PHP-Tests

Plugin hat ~600 LOC PHP ohne Tests. Bei größeren Refactorings sollte
zumindest PHPUnit oder Pest mit einem Mock-Fetcher dazukommen.

### 3. i18n unvollständig

Alle Strings sind via `__()`/`_e()`/`esc_html__()` i18n-fähig, aber das
`languages/`-Verzeichnis ist leer (kein `.pot`, kein `de_DE.po/.mo`).
Frontend zeigt deutsche Strings hardcoded — funktioniert solange das
Plugin nur für die DACH-Locale gedacht ist.

### 4. Keine GitHub-Releases automatisiert

`build-plugin-zip.sh` baut lokal. Es gibt keinen GitHub-Action-Workflow,
der bei Tag-Push automatisch ein Release mit ZIP erzeugt. Für Phase 4
(Cutover, GitHub-Updater-Plugin-Distribution) wäre das sinnvoll.

### 5. Komponenten-Icons sind hardcodiert

`COMPONENT_ICONS` ist eine PHP-Konstante. Neue Komponenten brauchen
eine Plugin-Codeänderung. Alternative: Icons in `component-templates.yml`
referenzieren und in der `tracker.json` mitliefern — dann ist das
Plugin komplett konfigurations-getrieben.

---

## Versionshistorie

| Version | Datum | Wesentliche Änderung |
|---|---|---|
| 2.0.0 | 2026-04 | Erste Alpha mit Settings, Fetcher, simpler Listen-Renderer. |
| 2.0.1 – 2.0.7 | 2026-05 | Iterationen am Karten-Layout, Inline-Styles-Strategie. |
| 2.0.8 | 2026-05 | Karten-Layout final stabil. |
| 2.1.0 | 2026-05 | Filter, Suche, Section-Collapse via JS. |
| 2.1.1 | 2026-05 | JS auf `<script src>`-Tag umgestellt, Filter-Button-Reihe entfernt (Stats sind klickbar), `pathway`-Smart-Default, Datum nur für Admins. |

---

## Weiterführende Dokumente

- [Benutzerhandbuch](Benutzerhandbuch.md) — Sicht der End-User.
- [Konzept.md](../../Konzept/Konzept.md) — Ursprungskonzeption des
  Gesamtsystems.
- [Arbeitsplan.md](../../Konzept/Arbeitsplan.md) — aktueller Stand,
  offene Bugs, nächste Schritte.
- [API-Befunde.md](../../Konzept/API-Befunde.md) — wie die WP-REST-API
  von learn.wordpress.org funktioniert.
- [Validierung-Phase-1.md](../../Konzept/Validierung-Phase-1.md) —
  Abnahme der Action-Implementierung.
- [JSON-Schema `tracker.schema.json`](../../Konzept/schemas/tracker.schema.json).
