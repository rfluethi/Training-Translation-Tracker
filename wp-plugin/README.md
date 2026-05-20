# Training Translation Tracker (v2)

> **Status:** Alpha — schlankes WordPress-Plugin, das die statische
> `tracker.json` aus der Schwester-Action liest und auf einer WordPress-Seite
> als Übersetzungs-Dashboard rendert.

Diese Version ist der Nachfolger des alten `wp-translation-tracker`-Plugins.
Statt selbst GraphQL/REST-Calls gegen WordPress/Learn zu machen, holt sie
einmal eine vorgerechnete JSON-Datei. Die wird von der GitHub Action im
Repo [Training-Translation-Tracker-Inventory-Plugin][action-repo] alle 12 h
gebaut und auf einem `data`-Branch veröffentlicht.

[action-repo]: https://github.com/rfluethi/Training-Translation-Tracker-Inventory-Plugin

---

## Was es kann (Alpha-Stand)

- Shortcode `[translation_tracker]` rendert die Übersicht auf jeder Seite.
- Settings unter **Einstellungen → Translation Tracker**:
  - URL der `tracker.json` (Default zeigt aufs Inventory-Plugin-Repo, `data`-Branch).
  - Cache-Dauer (Default 12 Stunden via WordPress-Transient).
  - Knopf „Cache jetzt leeren".
  - Anzeige des `generated_at`-Zeitstempels aus dem aktuellen Cache.
- `schema_version`-Check (lehnt unbekannte Major-Versionen ab).
- Fehlerpfad: bei Fetch-Fehler wird die letzte erfolgreiche Version weiterhin angezeigt.

## Was es noch nicht kann

- Karten-Layout, Komponenten-Icons, Statusmarker im Frontend (kommt in 2.3).
- Filter, Sortierung, Suche (kommt in 2.3).
- Übersetzungen `de_DE` / `en_US` (Strings sind i18n-fähig, `.po`/`.mo` folgt).
- Distribution per Release-ZIP über GitHub Updater Plugin (Phase 4).

## Anforderungen

| | |
| --- | --- |
| WordPress | 6.0 oder höher |
| PHP | 8.0 oder höher |
| Datenquelle | Eine erreichbare `tracker.json` gemäß `tracker.schema.json` v1 |

## Installation (lokal testen)

```bash
# In der WordPress-Installation
cd wp-content/plugins
# Symlink auf den Workspace-Ordner (komfortabel beim Entwickeln)
ln -s "/.../Training-Translation-Tracker-Inventory-Plugin/wp-plugin" training-translation-tracker
```

Im WP-Admin: **Plugins** → **Training Translation Tracker** aktivieren.
Dann **Einstellungen → Translation Tracker**, evtl. URL ändern, „Cache jetzt
leeren". Auf einer beliebigen Seite den Shortcode einbauen:

```text
[translation_tracker]
```

## Repository-Struktur

```text
.
├── training-translation-tracker.php   Hauptdatei (Header, Constants, Boot)
├── uninstall.php                       Cleanup beim Plugin-Löschen
├── includes/
│   ├── class-settings.php              Settings-Seite + Clear-Cache
│   ├── class-fetcher.php               wp_remote_get + Transient-Cache
│   └── class-renderer.php              Shortcode + HTML-Output
├── assets/
│   └── style.css                       Basic-Styling für die Liste
├── languages/                          .pot / .po / .mo (später)
├── readme.txt                          WordPress-Standard-Readme
├── README.md                           dieses Dokument
└── LICENSE
```

## Lizenz

GPL v2 oder später — siehe `LICENSE`.
