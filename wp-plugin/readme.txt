=== Training Translation Tracker ===
Contributors: rfluethi
Tags: translation, learn-wordpress, tracker, dashboard
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 2.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Übersetzungs-Dashboard für learn.wordpress.org-Inhalte. Liest eine statische
tracker.json (von einer GitHub Action erzeugt) und zeigt den Fortschritt
pro Pathway → Course → Section → Item, mit Karten-Layout, Filter, Suche
und einklappbaren Sections.

== Description ==

Dieses Plugin ist die schlanke v2-Version des wp-translation-tracker.
Es macht keinerlei eigene API-Calls gegen WordPress/Learn — stattdessen
liest es eine vorgerechnete JSON-Datei, die separat als GitHub-Action
gebaut wird. Dadurch ist die Webseite schnell, die Pflege gering und
das Plugin trotzdem aussagekräftig.

== Installation ==

1. Plugin-Verzeichnis nach wp-content/plugins/ kopieren oder ZIP via
   WordPress-Admin hochladen.
2. Plugin aktivieren.
3. Unter Einstellungen → Translation Tracker die URL der tracker.json
   prüfen und ggf. anpassen.
4. Shortcode `[translation_tracker]` auf einer beliebigen Seite einbauen.

== Changelog ==

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
