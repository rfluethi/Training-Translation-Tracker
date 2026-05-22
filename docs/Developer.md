# Developer, Training Translation Tracker

> **Audience:** Developers working on the code, maintaining or extending the action (Python) and/or the plugin (PHP/JS/CSS), or adapting either for other locales.
> **Prerequisite:** A rough understanding of the architecture, see [Architecture.md](Architecture.md).
> **Setup:** Linux/macOS with Python 3.10+, Node optional for JS linting, a local WordPress installation or WP Playground for plugin tests.

## 1. Local development environment

### Action (Python)

```bash
cd action
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

Run tests:

```bash
pytest
```

Local full build (needs a token, reads from the cache):

```bash
export GH_PAT_PROJECT_READ=<your token>
python -m src.build
```

Build without issues (inventory → tracker.json only, no token needed):

```bash
python -m src.build --skip-issues
```

Build or refresh the cache (live against learn.wordpress.org):

```bash
python -m src.build --refresh-cache
```

`--refresh-cache` does **not** fetch issues and does **not** write `tracker.json`. It only updates `inventory-cache.json`. By default it fetches only missing URLs. With `--force` all URLs are refetched.

### Plugin (PHP)

The most elegant approach for local development is a symlink into a WordPress installation:

```bash
cd /path/to/wordpress/wp-content/plugins
ln -s /path/to/Training-Translation-Tracker-Inventory-Plugin/wp-plugin training-translation-tracker
```

Alternatively build a local ZIP and upload via the WP admin UI:

```bash
./build-plugin-zip.sh
# → ~/Desktop/training-translation-tracker.zip
```

PHPUnit tests do not exist yet (as of 0.4.2). The plugin is verified by visual inspection in a local instance or in [WP Playground](https://playground.wordpress.net/).

## 2. Action code (Python)

### Module structure

```
action/src/
├── build.py                # entry point
├── inventory/              # REST modules per item type
│   ├── base.py             # InventorySource base interface
│   ├── lesson.py
│   ├── lesson_plan.py
│   ├── tutorial.py
│   ├── handbook.py
│   ├── url_normalizer.py   # canonical URL form
│   ├── dispatcher.py       # calls the right source per scope.yml entry
│   └── cache.py            # reads / writes inventory-cache.json
├── github/                 # GitHub API
│   ├── client.py           # GraphQL client with pagination + cost logging
│   ├── parser.py           # extracts URL fields + status table
│   └── fetcher.py
└── builder/                # aggregation
    ├── joiner.py           # match inventory and issues by URL
    ├── stats.py            # stats aggregation
    └── writer.py           # writes tracker.json + last-run.md
```

Each module has a clear responsibility, no cycles between sub-modules.

### InventorySource interface

```python
class InventorySource(Protocol):
    def fetch(self, scope_entry: dict) -> list[InventoryItem]:
        ...
```

`scope_entry` is one entry from `scope.yml`. `InventoryItem` is a dataclass with `type`, `slug`, `title_en`, `url_en`, `parent_path`, etc.

To connect a new content source:

1. Add a new module in `src/inventory/` that implements `InventorySource`.
2. Register it in the dispatcher.
3. Add the item type to `component-templates.yml`.

### Issue parser

`src/github/parser.py` is strict: it requires the HTML markers `<!-- TRANSLATION-STATUS-START -->` and `<!-- TRANSLATION-STATUS-END -->`. Issues without markers are accepted with default components and no `parse_error`; the markdown table parsing is simply skipped.

Accepted URL field names (tolerant):

- `Link to original content`, `Link to original`, `Original`
- `German title`, `German lesson name`, `Deutscher Titel`, `Translation title`, `Translated title`
- WP.tv / YouTube: each one is `Link to original/translated WordPress.tv/YouTube recording`. Without `original/translated`, the value is interpreted as the German recording (backwards compat).

Format-agnostic: `- Field: value` and `**Field:** value` are both recognised.

### Tests

```bash
pytest tests/
```

Tests cover:

- `tests/test_github_parser.py`, 8 fixtures (clean, broken, single row, at-prefix, unknown status, …).
- `tests/test_inventory_*.py`, per source module with mocked API.
- `tests/test_builder_joiner.py`, matching logic, duplicate handling, orphan classification.
- `tests/test_url_normalizer.py`, all URL normalization cases.

Linting via Ruff: `ruff check src/ tests/`.

## 3. Plugin code (PHP)

### Bootstrap: `training-translation-tracker.php`

Defines constants, loads classes, registers init hooks.

| Constant | Purpose |
|---|---|
| `TTT_VERSION` | Current plugin version (asset URL versioning) |
| `TTT_PLUGIN_FILE` / `_DIR` / `_URL` | Path helpers |
| `TTT_TRACKER_SCHEMA_VERSION` | Expected `schema_version` of `tracker.json` (`1`) |
| `TTT_DEFAULT_TRACKER_URL` | Default URL to the `data` branch |
| `TTT_DEFAULT_CACHE_HOURS` | Default cache TTL (`12`) |
| `TTT_OPTION_KEY` | WP option key (`ttt_settings`) |
| `TTT_TRANSIENT_KEY` | Transient key for the live cache |
| `TTT_LAST_GOOD_KEY` | Transient key for the last-good fallback (no TTL) |

### `class-settings.php`

- Settings page under **Settings → Translation Tracker**.
- Fields for URL, cache duration, "Clear cache" button.
- AJAX endpoint `ttt_clear_cache` with nonce and capability check (`manage_options`).
- Shortcode example list with copy buttons (via `admin.js`).

Configuration lives in a **single** WP option (`ttt_settings`) as an associative array. Avoids bloating the `wp_options` table.

```php
TTT_Settings::get( 'tracker_url' );
TTT_Settings::get( 'cache_hours' );
```

### `class-fetcher.php`

Static class. Central API point:

```php
$result = TTT_Fetcher::get();
// [
//   'payload' => array|null,
//   'source'  => 'cache'|'fresh'|'last_good'|'none',
//   'error'   => string,  // optional error message (admin-only)
// ]
```

Flow:

```
get()
├── Transient hit?           yes → 'cache' return
├── URL empty?               yes → 'last_good' return with error
├── HTTP fetch is_wp_error?  yes → 'last_good' return with error
├── Schema validation fails? yes → 'last_good' return with error
└── Store + 'fresh' return
```

Schema validation only checks `schema_version === TTT_TRACKER_SCHEMA_VERSION`. Deeper validation is handled by the action; the plugin trusts it.

### `class-renderer.php`

Largest class. Responsible for HTML generation.

| Method | Purpose |
|---|---|
| `render_shortcode( $atts )` | Shortcode handler |
| `render_inline_styles()` | Inline `<style>` block at the top |
| `render_inline_script()` | `<script src=…tracker.js>` tag at the bottom |
| `render_payload(…)` | Header + group loop |
| `render_stats(…)` | Stats pills with `data-filter-status` |
| `render_filter_bar()` | Search field in the header |
| `render_group/_course/_section/_item_list/_item(…)` | Pathway/handbook hierarchy + cards |
| `render_card_media_row(…)` | WP.tv/YouTube row |
| `render_component_icon(…)` | SVG icon for a component |
| `collect_markers(…)` | Orphan/parse-error/duplicate/draft markers |
| `group_passes_filter(…)` | Shortcode attribute logic |

Constants:

- `COMPONENT_ICONS`: Material Icons SVG paths per component (default; can be overridden via `tracker.json` `component_icons` or the `ttt_component_icons` filter).
- `COMPONENT_ORDER`: order of icons in the footer.

### Frontend HTML hierarchy

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
        <h4 class="ttt-section-heading">
          <button type="button" class="ttt-section-title" aria-expanded="true">…</button>
        </h4>
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

  <div class="ttt-no-results" hidden>No results …</div>
</div>

<script src="…assets/tracker.js?ver=…" defer></script>
```

### CSS class conventions

All classes use the `.ttt-` prefix. Important status modifiers:

- `.ttt-overall-{status}` on the `.ttt-card`.
- `.ttt-comp-{status}` on the component icon.
- `.ttt-stat-{status}` + `.ttt-stat-active` on the stats pill.
- `.ttt-marker-{reason}` for orphan/parse-error/duplicate markers.
- `.ttt-section-collapsed` for collapsed sections.

### Data attributes (used by JS)

- `.ttt-tracker[data-tracker-id]`, unique instance ID.
- `.ttt-stat[data-filter-status]`, one of `all|done|review|wip|open|na`.
- `.ttt-card[data-status]`, `overall_status` for JS filter.
- `.ttt-card[data-search]`, lowercase search string (EN title + DE title + issue number).
- `.ttt-section[data-section-key]`, key for localStorage (collapse state).

## 4. JavaScript

`assets/tracker.js`, vanilla ES5+ as an IIFE, no jQuery, around 640 lines.

### Initialization

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

The guard variable prevents double initialization on accidental multiple script inclusion.

### Per tracker container

`setupTracker(root)` binds:

- Click handlers on all `.ttt-stat[data-filter-status]` pills.
- Input handler (debounced 150 ms) on `.ttt-search-input`.
- Click/keyboard handler on section titles.

### State

Per tracker instance in `localStorage`:

```
ttt:<trackerId>:state                 → {"status":"done","query":"wordpress"}
ttt:<trackerId>:collapse:<sectionKey> → "1" | "0"
```

Filter state and collapse state survive a page reload.

### Filter logic

```js
var matchStatus = (state.status === 'all') || (card.dataset.status === state.status);
var matchQuery  = (state.query === '') || (card.dataset.search.indexOf(state.query) !== -1);

if (matchStatus && matchQuery) show(card); else hide(card);
```

Afterwards `hideEmptyContainers()` collapses sections, courses, and groups without visible cards. Stats pills are recomputed live from the filtered cards (stats live update).

## 5. CSS strategy

### Single source of truth (since 0.3.2)

The entire frontend CSS lives in the inline `<style id="ttt-inline-critical">` block emitted by `TTT_Renderer::render_inline_styles()`. There is no external `assets/style.css` anymore, no `wp_enqueue_style`, no dual maintenance.

Reason for inline over external: in WordPress environments with page builders (Elementor, Divi, Beaver Builder) and/or caching plugins (WP Rocket, LiteSpeed Cache), an external CSS file registered via `wp_enqueue_style` does not load reliably. `has_shortcode( $post->post_content, … )` fails when the shortcode lives in a builder-specific meta field. Inline styles in the shortcode output sidestep that entirely.

Because of the deliberate deviation from `wp_enqueue_style()`, the `<style>` tag is wrapped in a `phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet` block.

### Design tokens

Colours, spacings, font sizes, borders, and icon sizes are defined as CSS custom properties on the `.ttt-tracker` root:

```css
.ttt-tracker {
    /* Brand colours, overridable via theme.json */
    --ttt-color-primary: var(--wp--preset--color--primary, #2271b1);
    --ttt-color-text:    var(--wp--preset--color--foreground, #222);
    --ttt-color-bg:      var(--wp--preset--color--base, #fff);

    /* Status semantics, plugin-fixed, NOT overridable */
    --ttt-color-done:    #28a745;
    --ttt-color-review:  #d4a017;
    --ttt-color-wip:     #1c7ed6;

    /* Spacing, typography, borders, icons */
    --ttt-space-md:      0.6rem;
    --ttt-font-size-sm:  0.85rem;
    --ttt-radius-md:     6px;
    --ttt-icon-svg:      18px;
}
```

**Theme overrides:** set your own token values on the `.ttt-tracker` selector in a child theme or customizer CSS. Status colours stay plugin-fixed for semantic consistency.

### Specificity

All plugin rules carry the `.ttt-tracker` parent prefix for higher specificity against theme rules. Where themes have standard `!important` rules (e.g. `svg { max-width: 100% !important }`), the plugin wins with its own `!important` rules on the critical properties, e.g. `.ttt-tracker .ttt-card-cols { display: grid !important; }`.

## 6. Accessibility (A11y)

As of 0.4.2: no formal audit, but the semantic foundation and all quick wins are in place.

### What is implemented

- Stats filters are real `<button>` elements.
- **Section toggles are real `<button>` elements inside an `<h4>`**, semantically correct, natively keyboard-accessible (`Enter`/`Space`), `aria-expanded` reflects state.
- **Component icon triggers carry `aria-haspopup="dialog"` + `aria-expanded`**; the JS keeps the state in sync on open/close.
- The component popover is click/tap-capable in addition to hover; `Enter`/`Space` opens, `Esc` closes.
- The search input is semantically `<input type="search">` with `aria-label`.
- SVG icons carry `aria-hidden="true"` + `focusable="false"`; the semantic info sits on the wrapper `aria-label`.
- Status is encoded multiple ways: colour + icon + text pill.
- `defer` attribute on the tracker script.

### What is still open

1. **Contrast `--ttt-color-review`.** `#d4a017` (amber) on white gives about 2.4:1, below the WCAG AA threshold for icons (3:1). Brand design decision; on a formal audit, adjust (for example to `#b8860b` / DarkGoldenrod → about 3.3:1).
2. **Audit run.** Run Lighthouse-A11y and axe-core on a test page, work through the findings. So far only manual visual inspection.

### Test guide

Manual smoke test:

- **Tab navigation:** Use `Tab` and `Shift+Tab` through the page. Every interactive element must be reachable, with a visible focus ring.
- **Keyboard:** Stats filter, section collapse, search input — all operable via `Enter`/`Space`.
- **Screen reader sample:** Read a card with VoiceOver (macOS) or NVDA (Windows). Are the component statuses clearly recognisable?
- **Browser tool:** F12 → Lighthouse tab → "Accessibility" run.

## 7. Extension points

### Override component icons (filter hook)

Available since 0.3.0. In `class-renderer.php` the icon table is filtered:

```php
$icons = apply_filters( 'ttt_component_icons', self::COMPONENT_ICONS );
```

Themes or a small companion plugin can override icons without changing plugin code:

```php
add_filter( 'ttt_component_icons', function( $icons ) {
    $icons['text']  = 'M3 5h18v2H3V5z...'; // your own SVG path-d
    $icons['video'] = 'M8 5v14l11-7L8 5z...';
    return $icons;
} );
```

What gets passed is the SVG path `d` attribute (not a full SVG tag), because the plugin wraps it in `<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">`.

Since 0.3.3, icons can also be defined centrally in `action/component-templates.yml` and shipped via `tracker.json` as a top-level `component_icons` map. The priority order is: hardcoded `COMPONENT_ICONS` (fallback) < `component_icons` from `tracker.json` < `ttt_component_icons` filter (final override).

### New item types or components

1. **Action**: define the new type in `component-templates.yml`.
2. **Plugin** `class-renderer.php`:
   - Add a Material Icons SVG path to `COMPONENT_ICONS`.
   - Add the position to `COMPONENT_ORDER` (footer row).
3. **Inline CSS** in `render_inline_styles()`: add a colour class `.ttt-comp-newtype` if you want a dedicated colour.

The plugin treats unknown components defensively; it works even without a plugin update, just without an icon.

### Other locales (e.g. `it_IT`, `fr_FR`)

The plugin is locale-agnostic. For a new locale:

1. Fork the action repo.
2. Fill `scope.yml` with the locale-specific URL list.
3. In the workflow, point the issue filter at the appropriate GitHub project and label (`Locale=Italian` instead of `German`).
4. Run the action → new `tracker.json` on a separate `data` branch.
5. Plugin settings: point the URL at the new `data` branch.

No PHP changes required.

### Custom styling

In a child theme or customizer CSS:

```css
.ttt-tracker {
    --ttt-color-primary: #8e44ad;
    --ttt-radius-md: 12px;
    --ttt-font-size-base: 1.05rem;
}
```

This automatically overrides every spot reading `var(--ttt-color-primary)` etc., without an `!important` war.

For layout properties (`display: grid`, etc.) custom CSS must use `!important`, because the inline styles already carry `!important`.

## 8. Versioning

Keep three places in sync per release (example `0.4.2`):

| File | Value |
|---|---|
| `wp-plugin/training-translation-tracker.php` plugin header `Version:` | `0.4.2` |
| `wp-plugin/training-translation-tracker.php` constant `TTT_VERSION` | `0.4.2` |
| `wp-plugin/readme.txt` `Stable tag:` | `0.4.2` |

The CI workflow `release-plugin.yml` verifies this consistency on tag push and aborts if the values diverge.

Beta scheme `0.x.y`:

- `0.2.x`, ongoing beta iteration, same `schema_version=1`.
- `0.3.0`, next minor with new features.
- `1.0.0`, first stable release, when the plugin is production-ready.

Data model schema versioning: a `tracker.json schema_version` bump → the plugin rejects old data.

## 9. Known technical debt

| # | Topic | Status |
|---|---|---|
| 1 | No PHPUnit tests for the plugin | open, address before larger refactorings |
| 2 | Settings status notice uses fixed hex values | consistent token usage here too would be nicer |
| 3 | A11y never formally audited | run Lighthouse + axe-core pre-1.0, address findings (see § 6) |
| 4 | Component icons override only via filter hook / tracker.json | longer term: full plugin-side decoupling |

## 10. Sanity check for CSS token consistency

All `var(--ttt-*)` tokens used in the inline block must also be defined there, otherwise the layout breaks.

```bash
python3 - <<'EOF'
import re

with open('wp-plugin/includes/class-renderer.php') as f:
    m = re.search(r'<style id="ttt-inline-critical">(.*?)</style>', f.read(), re.DOTALL)
if not m:
    raise SystemExit('no inline style block found')
c = m.group(1)
used = set(re.findall(r'var\(--ttt-[\w-]+', c))
defined = set(f'var({n}' for n in re.findall(r'(--ttt-[\w-]+):', c))
print(f'used={len(used)} defined={len(defined)} '
      f'missing={sorted(used - defined)} unused={sorted(defined - used)}')
EOF
```

Expected: no `missing` and no `unused` tokens.

## Related documents

- System architecture: [Architecture.md](Architecture.md)
- Operations (releases, token maintenance, failure recovery): [Operations.md](Operations.md)
- User view: [User-Guide.md](User-Guide.md)
- JSON schemas: [`action/schemas/`](../action/schemas/)
- Contributing guide: [CONTRIBUTING.md](../CONTRIBUTING.md)
