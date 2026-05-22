=== Training Translation Tracker ===
Contributors: learnwpdach, rfluethi
Tags: translation, learn-wordpress, tracker, dashboard
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.4.2
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
* Erstes Beta-Release mit komplettem Feature-Set.
* Karten-Layout: zwei Spalten Original/Translation, farbiger Status-Balken,
  7 Komponenten-Icons mit Status-Farben.
* Komponenten-Popover bei Hover/Klick mit GitHub-Avataren und Profil-Links
  für Creator und Reviewer.
* Stats-Pillen oben (klickbar als Status-Filter) — Counts werden live
  aktualisiert basierend auf Suche und Filtern.
* Live-Suche im Header (Titel EN/DE, Issue-Nummer, Project-Status).
* Project-V2-Status-Pill in der Karte plus Dropdown-Filter in der Filter-Bar.
* Section-Collapse mit localStorage-Persistierung über Reloads.
* "Alle einklappen / ausklappen"-Toggle-Button.
* Shortcode-Attribute: pathway, show_pathways, show_orphans, show_handbook,
  show_stats für flexible Filterung pro Seite.
* Smart-Defaults: pathway="..." blendet Orphan/Handbook automatisch aus.
* Robuste Inline-Styles-Strategie gegen Theme-Konflikte und Page-Builder.

= Legacy pre-beta releases (internal 2.x numbering, kept for reference) =

= 2.1.5 =
* Bugfix: Leerer Collapse-Alle-Button beim ersten Seitenaufruf — JS-Labels
  werden jetzt vor `init()` definiert, nicht danach.
* Bugfix: Collapse- und Filter-State über Page-Reloads erhalten —
  tracker_id verwendet jetzt Post-ID + Counter statt UUID, damit
  localStorage-Keys über Reloads hinweg stabil bleiben.

= 2.1.4 =
* Stand-Datum komplett aus dem Frontend entfernt (Zeitstempel steht
  weiterhin in den Plugin-Einstellungen).
* Neuer Toggle-Button "Alle einklappen / Alle ausklappen" in der
  Filter-Bar — klappt alle Sections gleichzeitig ein bzw. wieder auf.
* Last-Good-Fallback-Hinweis bleibt für Admins sichtbar (wichtig für
  Diagnose bei API-Ausfall).

= 2.1.3 =
* Polish-Release: Debug-Logs aus dem JS entfernt, Stable-Tag-Bump.

= 2.1.2 =
* CSS-Specificity-Fix für JS-Hide-Mechanismus. Status-Filter, Live-Suche
  und Section-Collapse funktional bestätigt.

= 2.1.1 =
* JS auf externe Datei via `<script src>` umgestellt (robust gegen wpautop).
* Filter-Buttons-Reihe entfernt, Stats-Pillen sind jetzt klickbare Filter.
* `pathway`-Attribut: case-insensitiv + Label-Slug-Match + smarte Defaults
  (blendet Orphan/Handbook bei gesetztem Pathway automatisch aus).
* Datum unter dem Header nur noch für Admins sichtbar.

= 2.1.0 =
* Filter-Bar, Live-Suche, Section-Collapse mit localStorage.

= 2.0.8 =
* Karten-Layout final stabil (Inline-CSS-Strategie gegen Theme-Resets).

= 2.0.0 =
* Erste schlanke Variante: Settings-Seite, Fetch + Cache, simpler Renderer.
