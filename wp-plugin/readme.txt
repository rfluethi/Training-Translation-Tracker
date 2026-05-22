=== Training Translation Tracker ===
Contributors: learnwpdach, rfluethi
Tags: translation, learn-wordpress, tracker, dashboard
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.3.0
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
