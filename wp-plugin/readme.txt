=== Training Translation Tracker ===
Contributors: learnwpdach, rfluethi
Tags: translation, learn-wordpress, tracker, dashboard
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.4.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Dashboard for the translation progress of the Learn WP DACH Team.

== Description ==

Dashboard for the translation progress of the Learn WP DACH Team. Shows
progress per Pathway → Course → Section → Item with a card layout, filters,
search and collapsible sections.

The plugin does not make any API calls against WordPress.org or learn.wordpress.org
itself. Instead it reads a pre-built JSON file (`tracker.json`) that is
generated separately by a GitHub Action. This keeps the website fast,
maintenance low, and the plugin lightweight.

This plugin is currently in beta (0.x.y) and is maintained by the Learn WP
DACH Team for translating learn.wordpress.org content into German.

== Installation ==

1. Copy the plugin directory to wp-content/plugins/ or upload the ZIP via
   the WordPress admin.
2. Activate the plugin.
3. Under Settings → Translation Tracker, check the tracker.json URL and
   adjust if needed.
4. Embed the shortcode `[translation_tracker]` on any page.

== Changelog ==

= 0.4.8 =
* Security polish: tracker URL setting is now validated against an
  allow-list of hosts at save time. Default allow-list contains only
  `raw.githubusercontent.com`; admins can extend via the new filter
  hook `ttt_tracker_url_allowed_hosts`. URLs that are not HTTPS or
  whose host is not on the list are rejected and the previous saved
  value is kept (with an admin notice). Mitigates accidental
  misconfiguration and an SSRF-style risk vector.
* Docs (User-Guide, EN + DE): combined component+status filter and
  the "unspecified" pill (introduced in 0.4.4 / 0.4.5) are now
  documented; a CSP hint for `avatars.githubusercontent.com` was
  added to the troubleshooting section.
* Docs (Developer.md, EN + DE): describe the new filter hook
  `ttt_tracker_url_allowed_hosts`.
* i18n: `languages/training-translation-tracker.pot` regenerated from
  scratch out of the current PHP sources. The old file still
  contained extractor comments left over from earlier German
  docblocks; the new file has 87 entries with clean file:line
  references. The German `.mo` was rebuilt against the new `.po`
  (translates "unspecified" -> "keine Angabe").

= 0.4.7 =
* Accessibility: WCAG-AA contrast for status icons. The icon foreground
  tokens `--ttt-color-open` and `--ttt-color-review` were darkened
  (open: #facc15 -> #ca8a04, 3.8:1; review: #d4a017 -> #b45309, 5.2:1)
  so the component icons meet the 3:1 graphics contrast threshold
  against a white card. Pill backgrounds and other token uses remain
  unchanged.
* Polish: removed redundant `title=` attribute on component icons
  (kept only `aria-label=`). Screen readers no longer double-announce
  the same string. The popover remains the primary affordance for
  sighted users.
* Polish: `class-fetcher.php` now rejects JSON top-level arrays as
  well as non-array values via `array_is_list()`. Previously a JSON
  list slipped through to the deeper schema validation; now it is
  caught at the first guard.
* Polish: explicit `return;` after the capability-check failure in
  `handle_clear_cache()` to make the early-out obvious (no functional
  change because `wp_send_json_*` already calls `wp_die()`).
* Credits: README.md and CONTRIBUTING.md now mention Andy Rudorfer
  (@Bigod) as the source of the frontend UI design concept (card
  layout, status pills, component icons, filter bar).
* Maintainer rule: documentation must be maintained in both English
  (GitHub repo) and German (Konzept/ mirror). Documented in
  Arbeitsplan.md, no impact on shipped plugin.

= 0.4.6 =
* UX polish on the combined component+status filter introduced in 0.4.4:
  the two dependent dropdowns ("Filter by component" and "Filter
  component by status") are now visually grouped inside a single
  bordered container with a divider between them, so it is clear at
  a glance that they belong together. The status dropdown is disabled
  while no component is selected, and resets its value when the
  component is cleared.
* Stats pill and filter option renamed from "untouched" to
  "unspecified" (German: "keine Angabe"). The underlying data status
  value remains `unset`; only the visible labels changed.
* i18n: new strings added to languages/training-translation-tracker.pot
  and languages/training-translation-tracker-de_DE.po:
  "All components", "Any status", "Filter by component",
  "Filter component by status", "Show only items whose status table
  is empty", "unspecified", "missing", "Component filter".
  Maintainers running a release: regenerate the .mo via
  `msgfmt languages/training-translation-tracker-de_DE.po -o ...mo`.

= 0.4.5 =
* Bugfix: components from issues that have no status table (template
  not filled in yet) are no longer rendered as bright yellow "open"
  icons. They now use a new internal status `unset` (neutral gray
  icons) so the eye can distinguish them from components that are
  explicitly set to `open` in the status table. The 0.4.4 yellow
  highlight remains on real, explicit open components.
* New stats pill "Untouched" between "open" and "n/a" shows the count
  of items whose status table is completely empty (every component has
  `unset`). These items are still counted in the existing "open" pill
  (their overall_status rolls up to `open`), so the new pill is a
  sub-count that surfaces "how many of the open items still need their
  status table filled in".
* New filter dropdown option "untouched" for the component status
  selector (combine with "All components" to find untouched cards, or
  with a specific component to find items where that component has not
  been recorded yet).
* Action / schema: introduces `unset` to the per-component status enum
  (`ComponentStatus`). The overall_status enum is unchanged; items with
  all-unset components still roll up to `overall_status="open"`. The
  Stats object gains an optional `untouched` integer field.
* Docs (English and German Konzept mirror): describe the `unset` status
  in User-Guide and Issue-Templates files.

= 0.4.4 =
* Open component icons are now highlighted yellow (`#facc15` on a pale
  yellow pill background) instead of muted gray, so that components still
  needing work jump out visually. The icon opacity dampening on open is
  removed. Other status colors are unchanged (green for done, gold for
  review, blue for wip, light gray for n/a).
* New combined filter in the filter bar: two dropdowns "Filter by
  component" and "Filter component by status". Selecting only a component
  shows cards that contain that component. Selecting both shows cards
  where the chosen component is in the chosen status. Example: pick
  `text` + `open` to see every item whose text translation is still
  open. The component status dropdown is ignored when no component is
  selected (overall-status filtering remains on the stats pills above).
  The new dropdown choices are persisted via localStorage so they
  survive page reloads.
* Stats pill counts now also reflect the combined component filter, so
  the pill numbers always match the cards visible below.
* Docs (`docs/User-Guide.md`, `docs/Issue-Templates-DACH.md`): the
  parser has always accepted GitHub usernames both with and without the
  `@` prefix (it strips a leading `@`); the documentation now says so
  explicitly. Both `rfluethi` and `@rfluethi` are valid in the status
  table.

= 0.4.3 =
* Maintenance release. Full English translation of the entire repository:
  Markdown documentation (docs/Architecture.md, Developer.md, Operations.md,
  User-Guide.md, Issue-Templates-DACH.md), README and CONTRIBUTING files,
  PHP code comments (training-translation-tracker.php, includes/*.php,
  uninstall.php), JavaScript comments (assets/tracker.js, assets/admin.js),
  Python code comments and docstrings across action/src and action/tests
  (76/76 tests still green), GitHub workflow YAMLs, issue templates, the
  .gitignore and the build-plugin-zip.sh helper.
* Two documentation files were renamed for consistency: docs/Architektur.md
  -> docs/Architecture.md, docs/Issue-Vorlagen-DACH.md ->
  docs/Issue-Templates-DACH.md. German originals kept under the maintainer's
  local Konzept/docs-de-backup/ folder.
* Legacy changelog entries (0.2.3 and the 2.x pre-beta series) translated to
  English to match the rest of readme.txt.
* WordPress plugin audit: PASS with 0 CRITICAL / HIGH / MEDIUM findings.
  No code changes were required, the plugin is Plugin-Check clean.
* GitHub repo audit: no blocking findings. Minor housekeeping items (stale
  action/.github duplicate, empty Konzept/_archiv-css folder) are not in
  this release and will be cleaned up on the next pass.
* Tracker-data, frontend behavior and feature set are unchanged from 0.4.2.
  No user action required when upgrading.

= 0.4.2 =
* The auto-update mechanism introduced in 0.4.0 (`plugin-update-checker`
  by Yahnis Elsts) has been removed. Rationale: the library is itself an
  update mechanism, which Plugin Check forbids by design ("Plugin updater
  detected"). Bundling it produced ~80 noise findings that drowned out
  real review feedback on our own code, making the QA workflow worse.
  The DACH team is small enough that manual updates (download new ZIP
  from the GitHub release tab, replace plugin) are no real burden.
* Fix: escape JSON output for the inline i18n bundle with `JSON_HEX_TAG`
  so Plugin Check no longer flags `EscapeOutput.OutputNotEscaped`.
* Fix: `Tested up to: 7.0` so Plugin Check no longer flags outdated.
* Fix: explicit `phpcs:ignore` annotation on `load_plugin_textdomain()`,
  with comment explaining why a GitHub-distributed plugin needs the
  manual call (wp.org auto-load convention does not apply).
* Fix: release-plugin.yml `unzip | awk | head` chain now disables
  `pipefail` for that one diagnostic line, so SIGPIPE from head doesn't
  fail the whole release workflow.

= 0.4.1, 0.4.0 =
* Internal iteration on a GitHub-based auto-updater. Removed in 0.4.2,
  see above. If you installed 0.4.0 or 0.4.1, install 0.4.2 manually
  (download the ZIP from the GitHub release, deactivate the old plugin,
  upload the new one). After 0.4.2 the manual flow is the supported one
  for all future releases.

= 0.3.3 =
* Component icons are now data-driven: the SVG paths live in
  `action/component-templates.yml` (`icons:` block) and are delivered with
  the tracker.json as an optional top-level `component_icons` field. The
  plugin reads them, falls back to its hardcoded defaults if not present,
  and the `ttt_component_icons` filter remains the final override. Adding
  a new icon to the Action no longer requires a plugin code change.

= 0.3.2 =
* CSS architecture, single source of truth: the inline `<style>` block in
  `class-renderer.php` now contains the complete frontend CSS. The external
  `assets/style.css` has been removed, along with the `wp_enqueue_style`
  call. No more dual maintenance; one location, one set of rules.
* Frontend i18n complete: all visible labels (collapse all/expand all,
  popover headings for component names and status tokens, Creator/Reviewer,
  not-yet-assigned hint) now go through the translation bundle.
* Card labels: `Original` and `Translation` are correctly translated to
  German now (`Original` and `Übersetzung`).
* Search-field placeholder is locale-neutral: `Search titles…` in English,
  `Titel suchen…` in German (the previous `(EN or DE)` suffix is gone).
* Accessibility: keyboard users can now tab through the component popovers
  cleanly. Tab from the last profile-link in a popover advances to the next
  component icon. Shift+Tab from the first link closes the popover and
  returns focus to the icon. Esc closes from any point.

= 0.3.1 =
* i18n: source language switched from German to English (WordPress convention).
  Plugin shows English by default; German is delivered as a translation via
  `languages/training-translation-tracker-de_DE.mo`. WP installations with
  `WPLANG=de_DE` continue to show German.
* `Domain Path: /languages` re-added to the plugin header and
  `load_plugin_textdomain()` re-enabled so the bundled `.mo` is found in
  GitHub-distributed builds (where wp.org auto-load doesn't apply).

= 0.3.0 =
* Accessibility: section toggles are now real `<button>` elements (instead
  of `<h4 role="button">`) — semantically correct and natively
  keyboard-friendly. Component icons gained `aria-haspopup="dialog"` and
  `aria-expanded` state, kept in sync by the JS when the popover opens/closes.
* New filter hook `ttt_component_icons` — themes and companion plugins can
  override SVG icon paths per component without modifying plugin code. See
  developer docs.
* i18n: full `.pot` file shipped in `languages/training-translation-tracker.pot`
  (70 strings, 6 translator comments). Ready for `de_DE`, `de_CH` and any
  other locale.
* Documentation: top-level `docs/`-suite (Architecture, Developer, Operations,
  User Guide, Issue Templates) — absorbed the previous `wp-plugin/docs/` and
  centralized everything for both Action and Plugin.

= 0.2.4 =
* Plugin Check compliance: added missing translators comments, fixed unescaped
  output in settings field, removed legacy load_plugin_textdomain() call,
  removed non-existent Domain Path header, updated Tested up to header.
* readme.txt converted to English (per wp.org Plugin Directory requirements).
* CSS architecture refactor: design tokens via `--ttt-*` custom properties,
  theme.json fallbacks for brand colors (`--wp--preset--color--primary` etc.),
  inline style block and external stylesheet share the same token set.
* Polish: removed duplicate translators comment in fetcher, removed debug
  console.log calls from tracker.js, moved inline `style="color:..."` from
  the settings page to dedicated CSS classes, parameterised the component-icon
  SVG inline size via the new `--ttt-icon-svg` token.

= 0.2.3 =
* First beta release with the complete feature set.
* Card layout: two columns (Original / Translation), colored status bar,
  seven component icons with status colors.
* Component popover on hover/click with GitHub avatars and profile links
  for creator and reviewer.
* Stats pills at the top (clickable as a status filter), counts update
  live based on search and filters.
* Live search in the header (titles EN/DE, issue number, project status).
* Project V2 status pill on the card plus a dropdown filter in the filter bar.
* Section collapse with localStorage persistence across reloads.
* "Collapse all / expand all" toggle button.
* Shortcode attributes: pathway, show_pathways, show_orphans, show_handbook,
  show_stats for flexible per-page filtering.
* Smart defaults: pathway="..." hides orphan / handbook automatically.
* Robust inline-styles strategy against theme conflicts and page builders.

= Legacy pre-beta releases (internal 2.x numbering, kept for reference) =

= 2.1.5 =
* Bugfix: empty "Collapse all" button on first page load, the JS labels
  are now defined before `init()` rather than after.
* Bugfix: collapse and filter state preserved across page reloads,
  tracker_id now uses Post ID + counter instead of UUID, so that
  localStorage keys stay stable across reloads.

= 2.1.4 =
* "Last updated" date removed from the frontend entirely (the timestamp
  remains visible in the plugin settings).
* New toggle button "Collapse all / Expand all" in the filter bar,
  collapses or expands all sections at once.
* Last-good fallback hint remains visible to admins (important for
  diagnosing API outages).

= 2.1.3 =
* Polish release: debug logs removed from the JS, stable tag bump.

= 2.1.2 =
* CSS specificity fix for the JS hide mechanism. Status filter, live
  search and section collapse confirmed working.

= 2.1.1 =
* JS moved to an external file via `<script src>` (robust against wpautop).
* Row of filter buttons removed, stats pills are now clickable filters.
* `pathway` attribute: case-insensitive + label-slug match + smart defaults
  (hides orphan / handbook automatically when a pathway is set).
* "Last updated" date below the header now only visible to admins.

= 2.1.0 =
* Filter bar, live search, section collapse with localStorage.

= 2.0.8 =
* Card layout finally stable (inline-CSS strategy against theme resets).

= 2.0.0 =
* First lean variant: settings page, fetch + cache, simple renderer.
