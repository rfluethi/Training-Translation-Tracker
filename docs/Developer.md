# Developer — Training Translation Tracker

> **Zielgruppe:** Entwickler:innen, die am Code arbeiten — Action (Python) und/oder Plugin (PHP/JS/CSS) warten, erweitern oder für andere Locales adaptieren.
> **Voraussetzung:** Architektur grob verstanden — siehe [Architektur.md](Architektur.md).
> **Setup:** Linux/macOS mit Python 3.10+, Node optional für JS-Linting, lokale WordPress-Installation oder WP-Playground für Plugin-Tests.

---

## 1. Lokale Entwicklungs-Umgebung

### Action (Python)

```bash
cd action
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

Tests laufen lassen:

```bash
pytest
```

Lokaler Voll-Build (braucht Token, liest aus Cache):

```bash
export GH_PAT_PROJECT_READ=<your token>
python -m src.build
```

Build ohne Issues (nur Inventory → tracker.json, kein Token nötig):

```bash
python -m src.build --skip-issues
```

Cache aufbauen / aktualisieren (live gegen learn.wordpress.org):

```bash
python -m src.build --refresh-cache
```

`--refresh-cache` macht **keinen** Issue-Fetch und schreibt **kein** `tracker.json` — es aktualisiert nur `inventory-cache.json`. Default: nur fehlende URLs werden geholt. Mit `--force` werden alle neu gefetcht.

### Plugin (PHP)

Für lokale Entwicklung am elegantesten via Symlink in der WordPress-Installation:

```bash
cd /path/to/wordpress/wp-content/plugins
ln -s /path/to/Training-Translation-Tracker-Inventory-Plugin/wp-plugin training-translation-tracker
```

Alternativ ein lokales ZIP bauen und über die WP-Admin-UI hochladen:

```bash
./build-plugin-zip.sh
# → ~/Desktop/training-translation-tracker.zip
```

PHPUnit-Tests existieren aktuell nicht (Stand 0.3.0). Plugin wird per Sichtprüfung in einer lokalen Instanz oder im [WP-Playground](https://playground.wordpress.net/) verifiziert.

---

## 2. Action-Code (Python)

### Modul-Struktur

```
action/src/
├── build.py                # Einstiegspunkt
├── inventory/              # REST-Module pro Item-Typ
│   ├── base.py             # InventorySource-Basis-Interface
│   ├── lesson.py
│   ├── lesson_plan.py
│   ├── tutorial.py
│   ├── handbook.py
│   ├── url_normalizer.py   # kanonische URL-Form
│   ├── dispatcher.py       # ruft die passende Source pro scope.yml-Eintrag
│   └── cache.py            # liest/schreibt inventory-cache.json
├── github/                 # GitHub-API
│   ├── client.py           # GraphQL-Client mit Pagination + Cost-Logging
│   ├── parser.py           # extrahiert URL-Felder + Status-Tabelle
│   └── fetcher.py
└── builder/                # Aggregation
    ├── joiner.py           # Inventory ↔ Issues per URL matchen
    ├── stats.py            # Stats-Aggregation
    └── writer.py           # tracker.json + last-run.md schreiben
```

Jedes Modul hat eine klare Verantwortung — keine Zyklen zwischen den Untermodulen.

### InventorySource-Schnittstelle

```python
class InventorySource(Protocol):
    def fetch(self, scope_entry: dict) -> list[InventoryItem]:
        ...
```

`scope_entry` ist ein Eintrag aus `scope.yml`. `InventoryItem` ist ein Dataclass mit `type`, `slug`, `title_en`, `url_en`, `parent_path` etc.

Neue Inhaltsquelle anschließen:

1. Neues Modul in `src/inventory/`, das `InventorySource` implementiert.
2. Registrierung im Dispatcher.
3. Item-Typ in `component-templates.yml` aufnehmen.

### Issue-Parser

`src/github/parser.py` ist strikt: er verlangt die HTML-Marker `<!-- TRANSLATION-STATUS-START -->` / `<!-- TRANSLATION-STATUS-END -->`. Issues ohne Marker werden mit Default-Komponenten und ohne `parse_error` aufgenommen — das Markdown-Tabellen-Parsing wird einfach übersprungen.

Akzeptierte URL-Field-Namen (tolerant):

- `Link to original content`, `Link to original`, `Original`
- `German title`, `German lesson name`, `Deutscher Titel`, `Translation title`, `Translated title`
- WP.tv / YouTube: jeweils `Link to original/translated WordPress.tv/YouTube recording`. Ohne `original/translated` → als deutsche Aufnahme interpretiert (Backwards-Compat).

Format-egal: `- Field: value` und `**Field:** value` werden beide erkannt.

### Tests

```bash
pytest tests/
```

Tests decken ab:

- `tests/test_github_parser.py` — 8 Fixtures (sauber, broken, single row, at-prefix, unknown status, …).
- `tests/test_inventory_*.py` — pro Source-Modul mit gemockter API.
- `tests/test_builder_joiner.py` — Matching-Logik, Duplicate-Handling, Orphan-Klassifikation.
- `tests/test_url_normalizer.py` — alle Varianten der URL-Normalisierung.

Linting via Ruff: `ruff check src/ tests/`.

---

## 3. Plugin-Code (PHP)

### Bootstrap: `training-translation-tracker.php`

Definiert Konstanten, lädt Klassen, registriert Init-Hooks.

| Konstante | Zweck |
|---|---|
| `TTT_VERSION` | Aktuelle Plugin-Version (URL-Versionierung der Assets) |
| `TTT_PLUGIN_FILE` / `_DIR` / `_URL` | Pfad-Helfer |
| `TTT_TRACKER_SCHEMA_VERSION` | Erwartete `schema_version` der `tracker.json` (`1`) |
| `TTT_DEFAULT_TRACKER_URL` | Default-URL zum `data`-Branch |
| `TTT_DEFAULT_CACHE_HOURS` | Default-Cache-TTL (`12`) |
| `TTT_OPTION_KEY` | WP-Option-Key (`ttt_settings`) |
| `TTT_TRANSIENT_KEY` | Transient-Key für den Live-Cache |
| `TTT_LAST_GOOD_KEY` | Transient-Key für den Last-Good-Fallback (ohne TTL) |

### `class-settings.php`

- Settings-Seite unter **Einstellungen → Translation Tracker**.
- Felder für URL, Cache-Dauer, Clear-Cache-Knopf.
- AJAX-Endpoint `ttt_clear_cache` mit Nonce + Capability-Check (`manage_options`).
- Shortcode-Beispiel-Liste mit Copy-Button (via `admin.js`).

Konfiguration liegt in **einer** WP-Option (`ttt_settings`) als assoziatives Array — vermeidet das Aufblähen der `wp_options`-Tabelle.

```php
TTT_Settings::get( 'tracker_url' );
TTT_Settings::get( 'cache_hours' );
```

### `class-fetcher.php`

Statische Klasse. Zentraler API-Punkt:

```php
$result = TTT_Fetcher::get();
// [
//   'payload' => array|null,
//   'source'  => 'cache'|'fresh'|'last_good'|'none',
//   'error'   => string,  // Optionale Fehlernotiz (admin-only)
// ]
```

Ablauf:

```
get()
├── Transient hit?           yes → 'cache' return
├── URL leer?                yes → 'last_good' return mit Fehler
├── HTTP-Fetch is_wp_error?  yes → 'last_good' return mit Fehler
├── Schema-Validation fail?  yes → 'last_good' return mit Fehler
└── Store + 'fresh' return
```

Schema-Validation prüft nur `schema_version === TTT_TRACKER_SCHEMA_VERSION`. Tiefere Validation übernimmt die Action — Plugin vertraut darauf.

### `class-renderer.php`

Größte Klasse. Verantwortlich für die HTML-Erzeugung.

| Methode | Wofür |
|---|---|
| `render_shortcode( $atts )` | Shortcode-Handler |
| `render_inline_styles()` | Inline-`<style>`-Block am Anfang |
| `render_inline_script()` | `<script src=…tracker.js>`-Tag am Ende |
| `render_payload(…)` | Header + Gruppen-Schleife |
| `render_stats(…)` | Stats-Pillen mit `data-filter-status` |
| `render_filter_bar()` | Suchfeld im Header |
| `render_group/_course/_section/_item_list/_item(…)` | Pathway/Handbook-Hierarchie + Karten |
| `render_card_media_row(…)` | WP.tv/YouTube-Zeile |
| `render_component_icon(…)` | SVG-Icon für eine Komponente |
| `collect_markers(…)` | Orphan/Parse-Error/Duplicate/Draft-Marker |
| `group_passes_filter(…)` | Shortcode-Attribut-Logik |

Konstanten:

- `COMPONENT_ICONS` — Material-Icons-SVG-Paths pro Komponente.
- `COMPONENT_ORDER` — Reihenfolge der Icons im Footer.

### Frontend-HTML-Hierarchie

```html
<div class="ttt-tracker" id="ttt-{uuid}" data-tracker-id="ttt-{uuid}">
  <header class="ttt-header">
    <div class="ttt-stats">
      <button class="ttt-stat ttt-stat-total" data-filter-status="all">…</button>
      …
    </div>
    <div class="ttt-filter-bar">
      <input type="search" class="ttt-search-input">
    </div>
  </header>

  <section class="ttt-group ttt-group-pathway" data-group-key="pathway-user">
    <div class="ttt-course" data-course-key="…">
      <div class="ttt-section" data-section-key="…">
        <h4 class="ttt-section-title" role="button" tabindex="0" aria-expanded="true">…</h4>
        <div class="ttt-section-body">
          <div class="ttt-cards">
            <article class="ttt-card ttt-overall-done"
                     data-status="done"
                     data-search="introduction to wordpress …">
              …
            </article>
          </div>
        </div>
      </div>
    </div>
  </section>

  <div class="ttt-no-results" hidden>Keine Treffer …</div>
</div>

<script src="…assets/tracker.js?ver=…" defer></script>
```

### CSS-Klassen-Konventionen

Alle Klassen tragen `.ttt-`-Prefix. Wichtige Status-Modifier:

- `.ttt-overall-{status}` auf der `.ttt-card`.
- `.ttt-comp-{status}` auf dem Komponenten-Icon.
- `.ttt-stat-{status}` + `.ttt-stat-active` auf der Stats-Pille.
- `.ttt-marker-{reason}` für Orphan/Parse-Error/Duplicate-Markierungen.
- `.ttt-section-collapsed` für eingeklappte Section.

### Data-Attribute (vom JS verwendet)

- `.ttt-tracker[data-tracker-id]` — eindeutige Instanz-ID.
- `.ttt-stat[data-filter-status]` — `all|done|review|wip|open|na`.
- `.ttt-card[data-status]` — `overall_status` für JS-Filter.
- `.ttt-card[data-search]` — Lowercase-Suchstring (EN-Titel + DE-Titel + Issue-Nummer).
- `.ttt-section[data-section-key]` — Schlüssel für localStorage (Collapse-State).

---

## 4. JavaScript

`assets/tracker.js` — Vanilla ES5+ als IIFE, kein jQuery, ~270 Zeilen.

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

Guard-Variable verhindert Doppel-Init bei mehrfacher Skript-Inklusion.

### Pro Tracker-Container

`setupTracker(root)` bindet:

- Click-Handler auf alle `.ttt-stat[data-filter-status]`-Pillen.
- Input-Handler (debounced 150 ms) auf `.ttt-search-input`.
- Click/Keyboard-Handler auf Section-Titel.

### State

Pro Tracker-Instanz in `localStorage`:

```
ttt:<trackerId>:state                 → {"status":"done","query":"wordpress"}
ttt:<trackerId>:collapse:<sectionKey> → "1" | "0"
```

Filter-State und Collapse-Zustand überleben Page-Reload.

### Filter-Logik

```js
var matchStatus = (state.status === 'all') || (card.dataset.status === state.status);
var matchQuery  = (state.query === '') || (card.dataset.search.indexOf(state.query) !== -1);

if (matchStatus && matchQuery) show(card); else hide(card);
```

Danach `hideEmptyContainers()` — Sections/Courses/Groups ohne sichtbare Karten werden zugeklappt. Stats-Pillen werden aus den gefilterten Karten live neu berechnet (Stats-Live-Update).

---

## 5. CSS-Strategie

### Inline-First

In WordPress-Umgebungen mit Page-Buildern (Elementor, Divi, Beaver Builder) und/oder Caching-Plugins (WP Rocket, LiteSpeed Cache) lädt eine via `wp_enqueue_style` registrierte externe CSS-Datei **nicht zuverlässig**. `has_shortcode( $post->post_content, … )` versagt, wenn der Shortcode in einem Builder-eigenen Meta-Feld liegt statt im klassischen `post_content`.

Lösung: alle Styles werden inline als `<style id="ttt-inline-critical">`-Block direkt am Anfang der Shortcode-Ausgabe geschrieben (siehe `TTT_Renderer::render_inline_styles()`). Die externe `assets/style.css` wird zusätzlich enqueued — sie deckt Edge-Cases ab.

Wegen der bewussten Abweichung von `wp_enqueue_style()` umklammern beide `<style>`-Tags einen `phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet`-Block.

### Design-Tokens (ab v0.2.4)

Farben, Spacings, Schriftgrößen, Borders und Icon-Größen sind als CSS-Custom-Properties am `.ttt-tracker`-Root definiert. `assets/style.css` und `render_inline_styles()` deklarieren das gleiche Token-Set.

```css
.ttt-tracker {
    /* Brand-Farben — via theme.json überschreibbar */
    --ttt-color-primary: var(--wp--preset--color--primary, #2271b1);
    --ttt-color-text:    var(--wp--preset--color--foreground, #222);
    --ttt-color-bg:      var(--wp--preset--color--base, #fff);

    /* Status-Semantik — Plugin-fix, NICHT überschreibbar */
    --ttt-color-done:    #28a745;
    --ttt-color-review:  #d4a017;
    --ttt-color-wip:     #1c7ed6;

    /* Spacing, Typografie, Borders, Icons */
    --ttt-space-md:      0.6rem;
    --ttt-font-size-sm:  0.85rem;
    --ttt-radius-md:     6px;
    --ttt-icon-svg:      18px;
}
```

**Override am Theme** — eigene Token-Werte am `.ttt-tracker`-Selektor im Child-Theme oder Customizer-CSS setzen. Status-Farben bleiben Plugin-fix (semantische Konsistenz).

### Specificity

Alle Plugin-Regeln tragen den `.ttt-tracker`-Parent-Prefix für höhere Specificity gegen Theme-Rules. Wo Themes Standard-`!important`-Regeln haben (z. B. `svg { max-width: 100% !important }`), gewinnt das Plugin mit eigenen `!important`-Rules auf den entscheidenden Properties — z. B. `.ttt-tracker .ttt-card-cols { display: grid !important; }`.

### Doppelpflege (heute akzeptiert)

Solange CSS-Stufe 3 (gemeinsame Token-Quelle aus PHP-Array generieren) nicht implementiert ist, müssen Änderungen am Token-Set in **beiden** Quellen gespiegelt werden. Sanity-Check als Python-Snippet, siehe Sektion 10.

---

## 6. Barrierefreiheit (A11y)

Stand 0.3.0: keine formaler Audit, aber semantische Grundstruktur und alle Quick-Wins umgesetzt.

### Was umgesetzt ist

- Stats-Filter sind echte `<button>`-Elemente.
- **Section-Toggles sind echte `<button>` innerhalb eines `<h4>`** — semantisch korrekt, native Tastatur-Bedienung (`Enter`/`Leertaste`), `aria-expanded` reflektiert den Zustand. (Refactor in 0.3.0.)
- **Komponenten-Icon-Trigger tragen `aria-haspopup="dialog"` + `aria-expanded`** — JS pflegt den Zustand beim Öffnen/Schließen. (Neu in 0.3.0.)
- Komponenten-Popover ist click/tap-tauglich (zusätzlich zu hover), `Enter`/`Leertaste` öffnet, `Esc` schließt.
- Such-Input ist semantisch `<input type="search">` mit `aria-label`.
- SVG-Icons tragen `aria-hidden="true"` + `focusable="false"`; die semantische Info sitzt im Wrapper-`aria-label`.
- Status wird mehrfach kodiert: Farbe + Icon + Text-Pille.
- `defer`-Attribut auf dem Tracker-Script.

### Was noch offen ist

1. **Kontrast `--ttt-color-review`.** `#d4a017` (amber) auf weiß ergibt ~2.4:1 — unter WCAG-AA-Schwelle für Icons (3:1). Brand-Designentscheidung; bei einem formalen Audit nachjustieren (z. B. auf `#b8860b` / DarkGoldenrod → ~3.3:1).
2. **Audit-Lauf.** Lighthouse-A11y + axe-core auf einer Test-Seite laufen lassen, Findings als Iteration nachziehen. Bislang nur manuelle Sichtprüfung.

### Test-Anleitung

Manueller Smoketest:

- **Tab-Navigation:** Mit `Tab`/`Shift+Tab` durch die Seite. Jedes interaktive Element muss erreichbar sein, mit sichtbarem Focus-Ring.
- **Keyboard-Bedienung:** Stats-Filter, Section-Collapse, Such-Input — alles mit `Enter`/`Leertaste` bedienbar.
- **Screenreader-Stichprobe:** VoiceOver (macOS) oder NVDA (Windows) eine Karte vorlesen lassen. Komponenten-Status klar erkennbar?
- **Browser-Tool:** F12 → Lighthouse-Tab → „Accessibility"-Run.

---

## 7. Erweiterungspunkte

### Komponenten-Icons austauschen (Filter-Hook)

Verfügbar seit 0.3.0. In `class-renderer.php` filtern wir die Icon-Tabelle:

```php
$icons = apply_filters( 'ttt_component_icons', self::COMPONENT_ICONS );
```

Themes oder ein kleines Companion-Plugin können Icons ohne Plugin-Code-Änderung überschreiben:

```php
add_filter( 'ttt_component_icons', function( $icons ) {
    $icons['text']  = 'M3 5h18v2H3V5z...'; // eigener SVG path-d
    $icons['video'] = 'M8 5v14l11-7L8 5z...';
    return $icons;
} );
```

Was übergeben wird ist das `d`-Attribut des SVG-Pfads (kein vollständiger SVG-Tag), weil das Plugin um die Pfad-Daten den `<svg>`-Wrapper baut (`viewBox="0 0 24 24"`, `fill="currentColor"`, `aria-hidden="true"`).

Mittelfristige Alternative: Icons direkt in `component-templates.yml` definieren und in `tracker.json` mitliefern. Dann ist das Plugin komplett konfigurations-getrieben — sinnvoll erst, wenn mehrere Locales eigene Icon-Sätze brauchen.

### Neue Item-Typen / Komponenten

1. **Action** — neuen Typ in `component-templates.yml` definieren.
2. **Plugin** `class-renderer.php`:
   - In `COMPONENT_ICONS` einen Material-Icons-SVG-Path ergänzen.
   - In `COMPONENT_ORDER` die Position in der Footer-Zeile festlegen.
3. **Inline-CSS** in `render_inline_styles()`: Farb-Klasse `.ttt-comp-newtype` ergänzen (falls eigene Farbe gewünscht).

Plugin behandelt unbekannte Komponenten defensiv — funktioniert auch ohne Plugin-Update, nur ohne Icon.

### Andere Locales (z. B. `it_IT`, `fr_FR`)

Plugin ist locale-agnostisch. Für neue Locale:

1. Action-Fork des Repos.
2. `scope.yml` mit Locale-spezifischer URL-Liste füllen.
3. Workflow: Issue-Filter auf passendes GitHub-Projekt + Label (`Locale=Italian` statt `German`).
4. Action laufen lassen → neue `tracker.json` auf eigenem `data`-Branch.
5. Plugin-Settings: URL auf neuen `data`-Branch zeigen lassen.

Keine PHP-Änderung nötig.

### Custom Styling

Im Child-Theme oder Customizer-Custom-CSS:

```css
.ttt-tracker {
    --ttt-color-primary: #8e44ad;
    --ttt-radius-md: 12px;
    --ttt-font-size-base: 1.05rem;
}
```

Das überschreibt automatisch alle Stellen, die `var(--ttt-color-primary)` etc. lesen — ohne `!important`-Krieg.

Bei Layout-Properties (`display: grid` etc.) muss Custom-CSS mit `!important` arbeiten, weil die Inline-Styles `!important` tragen.

---

## 8. Versionierung


Drei Stellen pro Release synchron halten (Beispiel `0.3.0`):

| Datei | Wert |
|---|---|
| `wp-plugin/training-translation-tracker.php` Plugin-Header `Version:` | `0.3.0` |
| `wp-plugin/training-translation-tracker.php` Konstante `TTT_VERSION` | `0.3.0` |
| `wp-plugin/readme.txt` `Stable tag:` | `0.3.0` |

Der CI-Workflow `release-plugin.yml` verifiziert die Konsistenz beim Tag-Push und bricht ab, wenn sie auseinanderlaufen.

Beta-Schema `0.x.y`:

- `0.2.x` — laufende Beta-Iteration, gleiches `schema_version=1`.
- `0.3.0` — geplanter nächster Minor mit neuen Features.
- `1.0.0` — erstes stabiles Release, wenn Plugin produktiv-ready ist.

Schema-Versionierung des Datenmodells: `tracker.json schema_version`-Sprung → Plugin lehnt alte Daten ab.

---

## 9. Bekannte technische Schulden

| # | Thema | Status |
|---|---|---|
| 1 | Keine PHPUnit-Tests fürs Plugin | offen — bei größeren Refactorings nachziehen |
| 2 | CSS-Doppelpflege `style.css` ↔ Inline-Block | Stufe 3 (gemeinsame Quelle aus PHP-Array) noch nicht umgesetzt — akzeptable Doppelpflege |
| 3 | Settings-Status-Hinweis nutzt fixe Hex-Werte | konsistenter wäre auch hier Token-System |
| 4 | A11y nie formal auditiert | Pre-1.0 Lighthouse + axe-core durchlaufen, Findings nachziehen (siehe § 6) |
| 5 | Komponenten-Icons nur per Filter-Hook überschreibbar | längerfristig: Icons aus `component-templates.yml` in `tracker.json` mitliefern |

Priorisierte Roadmap liegt im Arbeitsplan beim Maintainer (Teil C, außerhalb des Repos).

---

## 10. Sanity-Check für CSS-Token-Sync

```bash
python3 - <<'EOF'
import re

def check(label, c):
    used = set(re.findall(r'var\(--ttt-[\w-]+', c))
    defined = set(f'var({n}' for n in re.findall(r'(--ttt-[\w-]+):', c))
    print(f'{label}: used={len(used)} defined={len(defined)} '
          f'missing={sorted(used - defined)} unused={sorted(defined - used)}')

with open('wp-plugin/assets/style.css') as f:
    check('style.css', f.read())

with open('wp-plugin/includes/class-renderer.php') as f:
    m = re.search(r'<style id="ttt-inline-critical">(.*?)</style>', f.read(), re.DOTALL)
    if m:
        check('inline   ', m.group(1))
EOF
```

Erwartet: keine `missing` und keine `unused` Tokens in beiden Quellen.

---

## Weiterführende Dokumente

- System-Architektur: [Architektur.md](Architektur.md)
- Betrieb (Releases, Token-Pflege, Failure-Recovery): [Operations.md](Operations.md)
- Benutzersicht: [User-Guide.md](User-Guide.md)
- JSON-Schemata: [`action/schemas/`](../action/schemas/)
- Contributing-Leitfaden: [CONTRIBUTING.md](../CONTRIBUTING.md)
