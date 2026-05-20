# Plugin-Dokumentation

Dokumentation zum WordPress-Plugin **Training Translation Tracker**.

## Welches Dokument willst du?

| Wenn du… | Dann lies… |
|---|---|
| das Plugin installieren oder Shortcodes auf einer Seite einbauen willst | [Benutzerhandbuch.md](Benutzerhandbuch.md) |
| das Plugin warten, debuggen oder erweitern willst | [Entwickler-Dokumentation.md](Entwickler-Dokumentation.md) |
| die Mono-Repo-Übersicht haben willst | [../../README.md](../../README.md) |
| die Action-Pipeline verstehen willst | [../../action/README.md](../../action/README.md) |

## Über das Plugin

Das Plugin liest eine vorbereitete `tracker.json` (gebaut von einer
GitHub Action alle 12 Stunden) und rendert daraus ein Übersetzungs-Dashboard
des Learn WP DACH Teams. Es macht keine eigenen API-Calls und ist daher
schlank und performant.

Aktuelle Version: **0.2.3 Beta**.

## Schnellstart

1. ZIP aus dem Release-Tab des Repos herunterladen.
2. WP-Admin → Plugins → Plugin hochladen → ZIP wählen → installieren → aktivieren.
3. Einstellungen → Translation Tracker — Default-URL übernehmen, „Cache leeren".
4. Auf einer Seite einfügen: `[translation_tracker]`.

## Mehr Detail

- Voller Bedienungs-Walkthrough mit allen Shortcode-Attributen, Beispielen
  und Troubleshooting: [Benutzerhandbuch.md](Benutzerhandbuch.md)
- Architektur, Datenmodell, Klassen-Struktur, Erweiterungspunkte:
  [Entwickler-Dokumentation.md](Entwickler-Dokumentation.md)
