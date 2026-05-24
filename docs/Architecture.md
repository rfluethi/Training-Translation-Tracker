# Architecture, Training Translation Tracker

> **Audience:** Tech leads, new contributors, other locale teams, anyone who wants to understand the system before working on it.
> **Status:** Architecture is frozen at schema version `1`. Last polish via plugin version `0.4.2` (2026-05).

## 1. Problem and solution

### What is the requirement?

The DACH translation team needs to see, at any time on a WordPress page, which content from `learn.wordpress.org` and the training handbook has already been translated into German, which is in progress, and, crucially, which has **not been started at all**. For each piece of content, the individual components (text, subtitles, quiz, video, etc.) must be visible, along with the creator and reviewer.

### What was wrong with the previous solution?

The earlier plugin read GitHub issues *live* from WordPress and parsed markdown status tables there. Three consequences:

1. **High maintenance per issue:** original URL, translation URL, both titles, WP.tv, YouTube, an `Order:` value, and the status table. A typo silently broke the display.
2. **Slow page:** per request, one GraphQL call against GitHub plus up to four calls to learn.wordpress.org per lesson, followed by PHP markdown parsing.
3. **Structural gap:** content without an issue was invisible, exactly the items the team most wants to know about (the ones still to do).

### The solution

**Split into three components:**

```
┌──────────────────────────┐   ┌──────────────────────────┐   ┌──────────────────────────┐
│  GitHub issues           │   │  GitHub Action (Python)  │   │  WordPress plugin (PHP)  │
│  WordPress/Learn         │──►│  builds tracker.json on  │──►│  reads tracker.json,     │
│  Project V2 #104         │   │  data branch every 12 h  │   │  renders shortcode       │
│  Locale = German         │   │                          │   │                          │
└──────────────────────────┘   └──────────────────────────┘   └──────────────────────────┘
       maintained by                  aggregation                     rendered in
       the team                       and schema validation            the frontend
```

- **Issues remain the data model.** The markdown status table is not replaced. What changes: the parsing moves into the action.
- **The action freezes the state every 12 hours,** writing a static JSON file on a branch.
- **The plugin makes no external API calls** beyond a static JSON fetch.

Result: fast website, less maintenance per issue, complete overview including gaps, and easy extension to new pathways or locales.

## 2. The three-component pipeline in detail

### 2.1 GitHub issues, the data model

Every translation effort has an issue in the `WordPress/Learn` repository. Issues must meet three requirements to be picked up by the tracker:

1. Marked in the DACH project board with the custom field `Locale = German`.
2. Original URL in canonical form (`https://`, lowercase, trailing slash, no query or fragment, no `www.`).
3. Status table between `<!-- TRANSLATION-STATUS-START -->` and `<!-- TRANSLATION-STATUS-END -->` with the defined status values.

Templates are in [Issue-Templates-DACH.md](Issue-Templates-DACH.md).

The issue itself remains the single point of truth for status changes. Re-translations happen in the same issue. Issues are never definitively closed and reopened.

### 2.2 GitHub Action, aggregation

The action in the `Training-Translation-Tracker` repository runs on three triggers:

- **Schedule:** `cron: "0 */12 * * *"` (every 12 hours).
- **`workflow_dispatch`:** manual button in the GitHub UI.
- **`push`** on `main` with a path filter on `scope.yml` and `component-templates.yml`, immediate rebuild after configuration changes.

Pipeline steps:

1. **Load inventory** from the committed cache (`action/inventory-cache.json`), refreshed locally via `--refresh-cache`.
2. **Fetch issues** via GraphQL against the DACH project board, filtered on `Locale = German`. Pagination is standard.
3. **Parse issues** with a strict parser that requires the HTML markers around the status table.
4. **Matching:** inventory items are matched with issues via canonical URL. Items without an issue stay at status `open`.
5. **Grouping** according to `scope.yml`: items are placed into pathway → course → section. Items with an issue but no scope entry land as `orphan`.
6. **Schema validation** against `tracker.schema.json` v1.
7. **Commit** to the `data` branch as `tracker.json` (force push, no history).

Output: `tracker.json` + `last-run.md` (a human-readable run report).

### 2.3 WordPress plugin, display

The plugin lives in the same mono-repo under `wp-plugin/`. Architectural principles:

- **No outbound API calls** other than one `wp_remote_get` against the `tracker.json` URL.
- **Transient cache** for the default duration of 12 hours, configurable 1 to 168 hours.
- **Last-good fallback:** a separate transient with no TTL, overwritten on every successful fetch. On errors, the last successful state is shown with an admin-only notice.
- **Lightweight renderer:** shortcode `[translation_tracker]` with attributes for pathway filter, stats header, orphan/handbook visibility.
- **Inline-first CSS:** all styles are written into the shortcode output as a `<style id="ttt-inline-critical">` block, so page builders and cache plugins cannot strip the layout.

## 3. Data model `tracker.json`

Full JSON schema in [`action/schemas/tracker.schema.json`](../action/schemas/tracker.schema.json).

### Top level

```json
{
  "schema_version": 1,
  "generated_at": "2026-05-22T08:00:00Z",
  "stats": {
    "total_items": 29, "done": 4, "review": 1, "wip": 1, "open": 23, "na": 0
  },
  "groups": [ /* pathway / handbook / orphan groups */ ]
}
```

`schema_version` is hard-coded on the plugin side (the constant `TTT_TRACKER_SCHEMA_VERSION`). The plugin rejects unknown major versions, protecting against schema drift.

### Group types

- **`pathway`:** a learning path with courses → sections → items.
- **`handbook`:** training handbook pages, grouped by top-level section slug.
- **`orphan`:** items with an issue but no scope entry (`outside_scope`) or no inventory match (`missing_in_inventory`).

### Items

Each item carries:

- Metadata: EN and DE titles and URLs, optionally WP.tv and YouTube (per original and translation).
- A components array with `name`, `status`, `creator`, `reviewer` per component.
- `overall_status` derived from the status aggregation.
- An `issue` object (`number`, `url`, `state`), the reference to the GitHub issue.
- Markers: `draft_original`, `duplicate_issues`, `parse_error`, `orphan_reason`.

### `overall_status` algorithm

Implemented in the action:

```
1. All components = "na"   → "na"
2. All non-na     = "done" → "done"
3. At least one "review"   → "review"
4. At least one "wip"      → "wip"
5. Otherwise               → "open"
```

Stats are computed at the item level from `overall_status`. Component statuses are shown in the frontend but do not feed into the stats aggregation.

## 4. Established decisions

These decisions are binding for the implementation. They come from the original design discussion and have been stable across several phases.

### 4.1 Data model and matching

**4.1.1 URL normalization.** Canonical form for every URL match: lowercase, `https`, with trailing slash, no query parameters, no fragment, no `www` subdomain. Normalization is applied centrally in the action, both for inventory URLs and for URLs extracted from issues.

**4.1.2 Items in multiple pathways.** A lesson that appears in multiple learning paths is shown multiple times, once per pathway. Stats count each appearance separately.

**4.1.3 Orphaned translations.** If a DACH issue exists for content that is no longer found in the inventory, the item is still displayed and flagged in the frontend as "orphaned". In `tracker.json` it carries `orphan_reason: missing_in_inventory`.

**4.1.4 Multiple issues per item.** Per item (URL) and language, GitHub may contain only **one** issue. If this is violated, all detected issues are carried in `tracker.json` and marked in the frontend with a "duplicate" warning symbol. Cleanup is manual, done by the team.

**4.1.5 Originals in `draft` status.** Lessons and pages whose English original is still in draft are included in the inventory and marked in the frontend with "original not yet published". In practice invisible today, since anonymous requests against `wp/v2/lessons` do not see drafts, so `draft_original` always stays `false`. With authenticated requests the marker logic would be active.

**4.1.6 `scope.yml` format.** Plain URL list. Items to be translated are entered individually per URL. Anything not in `scope.yml` and without a DACH issue does not appear in the tracker. Issues without a scope entry land under "Other" (`orphan_reason: outside_scope`). Later extensions (e.g. "take all lessons from this course automatically") are deliberately deferred to a v2.

### 4.2 Status calculation

**4.2.1 `overall_status` algorithm** (see also section 3):

```
1. All components = "na"   → na
2. All non-na     = "done" → done
3. At least one "review"   → review
4. At least one "wip"      → wip
5. Otherwise               → open
```

`overall_status` is used only for stats aggregation and filtering.

**4.2.2 Stats and component display.** Stats in the header are computed at the **item level** based on `overall_status`. Independently, **all component statuses are shown in full** on each item card. These do not feed into the stats aggregation.

### 4.3 Schema versioning and item-type fields

**4.3.1 Schema version + item-type-specific fields.** `tracker.json` carries `schema_version: 1` at the top level. The plugin rejects unknown major versions.

The set of metadata fields differs per item type. The JSON schema documents per `type` which fields are allowed. The plugin treats missing or unknown fields defensively (empty column, hidden symbol).

**4.3.2 Migration strategy on schema bumps.** On a major schema bump, the action temporarily produces both the old and new version side by side as `tracker.json` and, for example, `tracker.v2.json`. Once the plugin has migrated, the old file is removed.

### 4.4 Action operations

**4.4.1 Repository visibility.** Action repo is public. Benefits: free action minutes, `tracker.json` is public anyway, transparency for other locales.

**4.4.2 Action identity.** No separate bot account. The action commits as `github-actions[bot]`. The workflow gets `contents: write` permission on the output branch.

**4.4.3 Failure notification.** Standard GitHub notification is enough for now. A Slack webhook can be added later if desired.

**4.4.4 `scope.yml` validation.** The action validates after every run that every entry could be resolved. Otherwise a warning is recorded in `last-run.md`.

**4.4.5 Branch strategy for `tracker.json`.** The `data` branch is force-pushed. Each run overwrites the previous state. Data is regeneratable from GitHub issues at any time, no history needed.

**4.4.6 Token maintenance.** `GH_PAT_PROJECT_READ` currently belongs to the maintainer (Rico), with no planned rotation. When migrating to a DACH team account later, a one-time switch is needed. See [Operations.md](Operations.md) for details.

**4.4.7 Trigger configuration.** Three triggers:

- `schedule: cron: "0 */12 * * *"` (every 12 hours).
- `workflow_dispatch`, manual button in the GitHub UI.
- `push` on `main` with `paths: ['scope.yml', 'component-templates.yml']` filter, immediate rebuild after configuration changes.

### 4.5 Plugin operations

**4.5.1 Plugin source.** Plugin code lives in the same mono-repo under `wp-plugin/`. Releases are produced via `release-plugin.yml` on tag push (`v*`).

**4.5.2 Settings page.** A WordPress settings page under **Settings → Translation Tracker** with three fields:

- URL of `tracker.json` (default: `data` branch of the action repo).
- Cache duration in hours (default 12, min 1, max 168).
- Button "Clear cache now" with AJAX + nonce + capability check.

**4.5.3 Error behaviour.** On API error (HTTP ≠ 2xx, JSON decode failure, schema mismatch), the plugin shows the last successful state (`last_good` transient with no TTL). Admins additionally see an inline hint "(last successfully cached state, current fetch failed)".

**4.5.4 First-time installation.** On first installation with empty caches, the first shortcode call performs a synchronous `wp_remote_get` against the configured URL and fills the cache.

**4.5.5 CSS strategy.** Single source of truth (since 0.3.2): the entire frontend CSS is written exclusively as an inline `<style id="ttt-inline-critical">` block in the shortcode output. No external `style.css`, no `wp_enqueue_style`. Tokens (`--ttt-*`) with theme.json fallbacks for brand colours; status colours are plugin-fixed.

### 4.6 Content decisions

**4.6.1 Locale filter in the GitHub project board.** Issues are marked in the DACH project board with the custom field `Locale = German`. The action filters on that value.

**4.6.2 Component set per item type.** Defined in `action/component-templates.yml`:

- `lesson`: text, thumbnails, video, subtitles, quiz, exercise, audio
- `lesson_plan`: text, thumbnails
- `tutorial`: text, thumbnails, video, subtitles
- `handbook_text`: text
- `handbook_video`: text, thumbnails, video, subtitles

### 4.7 Localization and maintenance

**4.7.1 Frontend string language.** English as the source language in the code (WP convention), translations via `.po`/`.mo` in `wp-plugin/languages/`. Currently shipped: English (default) and German (`de_DE`).

**4.7.2 Maintenance.** Maintenance is done by the Learn WP DACH team. Repo owner: Rico. GPL v2 or later. Contributions via issues and pull requests are documented in [CONTRIBUTING.md](../CONTRIBUTING.md).

## 5. Repository layout

```
Repo-Root/                       # cloned from GitHub
├── .github/workflows/
│   ├── build.yml                # builds tracker.json on the data branch
│   └── release-plugin.yml       # plugin ZIP release on tag push
├── action/                      # GitHub Action (Python)
│   ├── src/
│   │   ├── inventory/           # REST modules per item type + URL normalizer
│   │   ├── github/              # GraphQL client + issue parser
│   │   ├── builder/             # joiner, stats, output writer
│   │   └── build.py             # entry point
│   ├── tests/                   # pytest
│   ├── schemas/                 # JSON schemas (runtime copy)
│   ├── scope.yml                # which URLs are in scope
│   ├── component-templates.yml  # default components per item type
│   ├── inventory-cache.json     # committed inventory snapshot
│   └── requirements.txt
├── wp-plugin/                   # WordPress plugin (PHP)
│   ├── training-translation-tracker.php   # bootstrap, constants
│   ├── uninstall.php
│   ├── includes/
│   │   ├── class-settings.php   # settings page + AJAX clear-cache
│   │   ├── class-fetcher.php    # wp_remote_get + transient cache
│   │   └── class-renderer.php   # shortcode + HTML output
│   ├── assets/
│   │   ├── tracker.js           # frontend JS (filter/search/collapse)
│   │   └── admin.js             # settings page JS
│   ├── languages/               # i18n files (.pot/.po/.mo)
│   ├── readme.txt               # WordPress standard readme
│   └── LICENSE
├── docs/                        # documentation (you are here)
│   ├── Architecture.md
│   ├── Developer.md
│   ├── Operations.md
│   ├── User-Guide.md
│   └── Issue-Templates-DACH.md
├── build-plugin-zip.sh          # local build script
├── sync-schemas.py              # schema sync tool for maintenance
├── CONTRIBUTING.md
├── README.md                    # mono-repo overview
└── LICENSE
```

The JSON schemas in `action/schemas/` are the runtime copies. Contributors edit them directly in the repo.

## 6. Error tolerance and failure modes

The architecture is deliberately built to be robust against single failures.

### Action

- If an API call fails, the last successful `tracker.json` is **not** overwritten → the website continues to show the previous state instead of an empty table.
- Each run commits `data/last-run.md` containing warnings, parse errors, and statistics.
- Issues with a broken status table are written to JSON with `parse_error: true` rather than aborting the whole run.

### Plugin

- On API error (HTTP ≠ 2xx, JSON decode failure, schema mismatch), the plugin shows the last successful state (`last_good` transient with no TTL).
- Admins additionally see an inline hint "(last successfully cached state, current fetch failed)".
- On first install with an empty cache, the first shortcode call performs a synchronous `wp_remote_get` and fills the cache.

### Known risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Markdown status tables in issues fragile (typos) | medium | medium | Robust parser + `parse_error` marker + note in `last-run.md` |
| Multiple issues per item in practice | medium | low | Frontend marker + manual cleanup by the team |
| Page builder or cache plugin breaks JS loading for end users | low | medium | Inline `<script src>` strategy + diagnostic guidance |
| Plugin Check bringing new rules in future versions | medium | low | Run once per release, treat findings as polish iteration |

## 7. Extension points

### New item types or components

Add them to `action/component-templates.yml`. If the plugin should display a new icon, extend the `icons:` block in the same file. The plugin treats unknown components defensively (icon hidden, no crash), so an action-side extension works even without a plugin update.

### Other locales

The plugin is locale-agnostic; it renders what is in `tracker.json`. For a new locale:

1. Fork the action repository.
2. Populate `scope.yml` with the locale-specific URL list.
3. In the workflow, point the issue filter at the appropriate GitHub project and label.
4. Plugin settings: point the URL at the new `data` branch.

No PHP changes required.

### New content sources

Each content source is a module with the interface `(scope_entry) → list[InventoryItem]`. Currently:

- `learn_wp_lesson` (REST `/wp-json/wp/v2/lessons`)
- `learn_wp_lesson_plan`
- `learn_wp_tutorial` (`wporg_workshop`)
- `handbook_section` (REST `/wp-json/wp/v2/handbook` on `make.wordpress.org/training/`)

Connecting a new source = new module + entry in `component-templates.yml`. No plugin change required.

## 8. Glossary

| Term | Meaning |
|---|---|
| **Inventory** | Complete list of content from learn.wordpress.org and the handbook that is in scope |
| **Item** | A single translatable piece of content (lesson, lesson plan, handbook page, etc.) |
| **Component** | A subpart of an item translated individually (text, video, subtitles, etc.) |
| **Scope** | Manually maintained selection of which pathways and sections the tracker covers |
| **Status table** | Markdown table in the issue body between `TRANSLATION-STATUS-START`/`-END` |
| **Orphan issue** | Issue whose original content is outside the scope (`orphan_reason: outside_scope`) |
| **Orphaned translation** | Issue exists, original content no longer in the inventory (`orphan_reason: missing_in_inventory`) |
| **Duplicate** | Multiple issues with the same original URL and language. Should not occur, marked visibly |
| **Action** | GitHub Actions workflow that produces `tracker.json` |
| **`data` branch** | Branch in the repo where the action writes `tracker.json`. Fetched by the plugin via raw.githubusercontent.com |

## Related documents

- Developer view (code, setup, tests, extensions): [Developer.md](Developer.md)
- Operations (releases, token maintenance, failure recovery): [Operations.md](Operations.md)
- User view (plugin settings, shortcodes, frontend usage): [User-Guide.md](User-Guide.md)
- Issue templates for the DACH team: [Issue-Templates-DACH.md](Issue-Templates-DACH.md)
