# Plugin-Dokumentation

Dokumentation zum WordPress-Plugin **Training Translation Tracker**.

## Welches Dokument willst du?

| Wenn du… | Dann lies… |
|---|---|
| das Plugin installieren oder Shortcodes auf einer Seite einbauen willst | [Benutzerhandbuch.md](Benutzerhandbuch.md) |
| das Plugin warten, debuggen oder erweitern willst | [Entwickler-Dokumentation.md](Entwickler-Dokumentation.md) |
| die ursprüngliche Konzeption nachvollziehen willst | [../../Konzept/Konzept.md](../../Konzept/Konzept.md) |
| den aktuellen Projekt-Stand und offene Aufgaben einsehen willst | [../../Konzept/Arbeitsplan.md](../../Konzept/Arbeitsplan.md) |

## Über das Plugin

Das Plugin liest eine vorbereitete `tracker.json` (gebaut von einer
GitHub Action alle 12 Stunden) und rendert daraus ein
Übersetzungs-Dashboard für die DACH-Inhalte auf `learn.wordpress.org`.
Es macht keine eigenen API-Calls und ist daher schlank und performant.

Aktuelle Version: **2.1.1** (Mai 2026 — Alpha-Phase).

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
