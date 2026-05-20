# Benutzerhandbuch — Training Translation Tracker

> Für Mitglieder des DACH-Übersetzungsteams. Erklärt, wie das Plugin
> installiert, konfiguriert und auf einer WordPress-Seite verwendet wird.
>
> **Zielgruppe:** WordPress-Site-Admins ohne PHP-Kenntnisse.
> **Bezug:** [Entwickler-Dokumentation.md](Entwickler-Dokumentation.md) für die technische Sicht.

---

## Inhaltsverzeichnis

1. [Was macht das Plugin?](#was-macht-das-plugin)
2. [Installation](#installation)
3. [Plugin-Einstellungen](#plugin-einstellungen)
4. [Den Tracker auf einer Seite einbauen](#den-tracker-auf-einer-seite-einbauen)
5. [Shortcode-Attribute](#shortcode-attribute)
6. [Beispiele für typische Anwendungsfälle](#beispiele-für-typische-anwendungsfälle)
7. [Den Tracker im Frontend bedienen](#den-tracker-im-frontend-bedienen)
8. [Daten aktualisieren](#daten-aktualisieren)
9. [Issues für neue Übersetzungen anlegen](#issues-für-neue-übersetzungen-anlegen)
10. [Häufige Probleme](#häufige-probleme)
11. [Einen Bug melden](#einen-bug-melden)

---

## Was macht das Plugin?

Der Translation Tracker ist ein Dashboard für den Übersetzungsfortschritt der
WordPress-Lerninhalte auf `learn.wordpress.org`. Auf einer WordPress-Seite
zeigt er, welche Inhalte schon übersetzt sind, welche gerade bearbeitet
werden und welche noch offen sind. Pro Inhalt sieht man, in welchem Status
die einzelnen Komponenten (Text, Untertitel, Quiz, Video, …) sind.

Die Daten kommen nicht aus dieser WordPress-Site direkt, sondern aus einer
**GitHub Action**, die alle 12 Stunden eine JSON-Datei auf einen `data`-
Branch eines GitHub-Repos veröffentlicht. Das Plugin lädt diese Datei
einmal pro Cache-Zyklus und stellt sie als Übersicht dar. Diese Trennung
hält die Site schnell und das Plugin schlank.

**Drei Bestandteile des Gesamtsystems:**

| Komponente | Wo? | Was? |
|---|---|---|
| GitHub Issues | `WP-Translations-DACH`-Projekt | Übersetzer pflegen Status pro Inhalt |
| GitHub Action | `Training-Translation-Tracker-Inventory-Plugin` | Baut `tracker.json` alle 12 h |
| WordPress-Plugin | Diese Site | Liest JSON, zeigt Dashboard |

Du als DACH-Team-Mitglied arbeitest hauptsächlich mit den GitHub-Issues
(Status pflegen) und siehst das Ergebnis im Dashboard.

---

## Installation

### Voraussetzungen

| | |
| --- | --- |
| WordPress | 6.0 oder höher |
| PHP | 8.0 oder höher |
| Internet-Zugriff | Die Site muss `raw.githubusercontent.com` erreichen können |

### Installations-Schritte

1. **ZIP herunterladen**: Aktuelles `training-translation-tracker.zip`
   (Plugin-Datei) vom Maintainer oder aus dem Release-Tab des GitHub-Repos
   besorgen.
2. **Im WP-Admin**: links im Menü auf **Plugins → Installieren** klicken.
3. Oben auf **Plugin hochladen**, dann die ZIP-Datei auswählen.
4. Auf **Jetzt installieren** klicken.
5. Nach erfolgreichem Upload auf **Plugin aktivieren**.

Falls bereits eine ältere Version installiert ist, **zuerst die alte
Version deaktivieren und löschen** (rote „Löschen"-Aktion in der Plugin-
Liste). Erst dann die neue ZIP hochladen. Einstellungen und Cache bleiben
dabei erhalten.

### Verifikation

Nach der Installation steht in der Plugin-Liste ein Eintrag „**Training
Translation Tracker**" mit Versionsnummer. Im Menü **Einstellungen** gibt
es einen neuen Unterpunkt „**Translation Tracker**".

---

## Plugin-Einstellungen

Erreichbar unter **WP-Admin → Einstellungen → Translation Tracker**.

### URL der `tracker.json`

Die Adresse, von der das Plugin die JSON-Datei lädt. Default zeigt auf
den `data`-Branch des Inventory-Plugin-Repos:

```text
https://raw.githubusercontent.com/rfluethi/Training-Translation-Tracker-Inventory-Plugin/data/tracker.json
```

Diese URL nur ändern, wenn das Action-Repo umzieht oder eine andere
Datenquelle verwendet werden soll. Für 99 % der Fälle bleibt sie wie
voreingestellt.

### Cache-Dauer (Stunden)

Wie lange die geladene JSON im WordPress-Transient-Cache liegt, bevor neu
geladen wird. Default ist 12 Stunden — passend zum 12-h-Rhythmus der
Action.

- **Kürzer setzen** (z. B. 1 Stunde) während Test-Phasen oder wenn man
  schnelle Updates braucht.
- **Länger setzen** (z. B. 24 Stunden) wenn die Datenquelle stabil ist
  und der HTTP-Traffic minimiert werden soll.

Erlaubter Bereich: 1 – 168 Stunden (also bis zu eine Woche).

### Knopf „Cache jetzt leeren"

Erzwingt einen frischen Fetch beim nächsten Seitenaufruf. Nützlich,
wenn gerade eine Aktualisierung auf GitHub gemacht wurde und du sie
sofort sehen willst, ohne den Cache-Ablauf abzuwarten.

### Anzeige „Stand: …"

Zeigt den `generated_at`-Zeitstempel aus dem aktuellen Cache. Daran
sieht man, wann die Action den jetzt gecachten Datenstand zuletzt
gebaut hat. Steht dort ein älteres Datum als erwartet, ist der Cache
veraltet — entweder „Cache leeren" drücken oder die Action manuell
triggern (siehe [Daten aktualisieren](#daten-aktualisieren)).

### Shortcode-Beispiele

Direkt unter den Settings-Feldern listet das Plugin Shortcode-Beispiele
mit „Kopieren"-Buttons. Damit kann man sich häufig benutzte Varianten
direkt mitnehmen, ohne sie aus diesem Dokument abtippen zu müssen.

---

## Den Tracker auf einer Seite einbauen

Im WordPress-Editor (Gutenberg, Classic, oder ein Page-Builder):

1. Eine **Seite** öffnen oder neu anlegen, auf der das Dashboard erscheinen
   soll. Üblich ist eine Seite mit dem Titel „Translation Tracker".
2. **Shortcode-Block** einfügen (in Gutenberg: „/" → „Shortcode").
3. In den Block-Inhalt schreiben:

   ```text
   [translation_tracker]
   ```

4. Seite **speichern und veröffentlichen**.
5. **Seite öffnen** (Vorschau oder Live-URL). Das Dashboard erscheint
   an der Stelle, wo der Shortcode steht.

In Page-Buildern (Elementor, Divi, …) gibt es meistens ebenfalls einen
Shortcode-Block oder ein Text-Widget, das Shortcodes ausführt. Dort
einfach `[translation_tracker]` einfügen.

---

## Shortcode-Attribute

Über Attribute steuert man, was das Dashboard zeigt. Mehrere Attribute
können kombiniert werden:

| Attribut | Werte | Wirkung |
|---|---|---|
| `pathway` | Slug einer Pathway, mehrere durch Komma getrennt | Zeigt nur die genannten Lernpfade |
| `show_pathways` | `yes`/`no` | Alle Pathway-Gruppen zeigen oder verstecken (Default `yes`) |
| `show_orphans` | `yes`/`no` | „Sonstige (außerhalb Scope)" zeigen oder verstecken |
| `show_handbook` | `yes`/`no` | Training-Handbook-Gruppe zeigen oder verstecken |
| `show_stats` | `yes`/`no` | Stats-Header oben mit den Pillen zeigen oder verstecken |

### Smart-Defaults

- Wenn `pathway` gesetzt ist, blendet das Plugin **automatisch**
  Orphan- und Handbook-Gruppen aus (vermutete Intention: „ich will nur
  diese Pathway-Übersicht"). Wer sie trotzdem will, gibt explizit
  `show_orphans="yes"` an.
- Wenn `pathway` **nicht** gesetzt ist, werden Orphan und Handbook
  standardmäßig mitgezeigt.

### Pathway-Slug-Matching

Das `pathway`-Attribut akzeptiert mehrere Schreibweisen für denselben
Pathway:

- Kurz-Slug: `pathway="user"`
- Voller Label-Slug: `pathway="beginner-wordpress-user"`
- Original-Label: `pathway="Beginner WordPress User"`

Alle drei matchen dieselbe Pathway-Gruppe. Groß-/Kleinschreibung ist
egal.

---

## Beispiele für typische Anwendungsfälle

### Komplette Übersicht

```text
[translation_tracker]
```

Zeigt alles: alle Pathways, Handbook und Orphans, mit Stats-Header.

### Eine Seite pro Pathway

Auf der „User"-Pathway-Seite:

```text
[translation_tracker pathway="user"]
```

Auf der „Lesson Plans"-Seite:

```text
[translation_tracker pathway="lesson-plans"]
```

So bekommt jede Pathway-Seite eine fokussierte Übersicht.

### Mehrere Pathways auf einer Seite

```text
[translation_tracker pathway="user, contributor"]
```

Zeigt die User- und Contributor-Pathway, nichts anderes.

### Stats verstecken (z. B. wenn die Seite schon einen eigenen Header hat)

```text
[translation_tracker show_stats="no"]
```

### Verwaiste Übersetzungen ausblenden

```text
[translation_tracker show_orphans="no"]
```

Sinnvoll, wenn man nur den „offiziellen" Scope sehen will, ohne die
Übersetzungen außerhalb des Scopes.

### Nur das Training-Handbook anzeigen

```text
[translation_tracker show_pathways="no" show_orphans="no"]
```

Blendet alle Pathway-Gruppen sowie die „Sonstige"-Gruppe aus —
übrig bleibt nur das Training Handbook. Typisch für eine eigene
Handbook-Übersichtsseite.

### Pathway mit Handbook aber ohne Orphans

```text
[translation_tracker pathway="user" show_handbook="yes" show_orphans="no"]
```

Smart-Default-Verhalten („wenn Pathway, dann auch kein Handbook")
wird durch das explizite `show_handbook="yes"` überschrieben.

---

## Den Tracker im Frontend bedienen

> **Hinweis:** Die interaktiven Funktionen (Filter, Suche, Section-
> Einklappen) waren in der Pause am 2026-05-19 noch nicht stabil und
> werden in einer nachfolgenden Version repariert. Die statische
> Anzeige der Karten funktioniert bereits einwandfrei.

### Die Stats-Pillen oben

Zeigen die Gesamtzahlen pro Status:

- **Items** — alle Items zusammen
- **fertig** (grün) — `overall_status = done`
- **Review** (gelb) — `overall_status = review`
- **in Arbeit** (blau) — `overall_status = wip`
- **offen** (grau) — `overall_status = open`
- **n/a** (hellgrau) — `overall_status = na`

Klick auf eine Pille filtert die Karten auf diesen Status. Erneuter
Klick auf „Items" setzt den Filter zurück.

### Die Karten

Jede Karte zeigt einen Inhalt:

- **Status-Balken links** in der Farbe des `overall_status`.
- **Original-Spalte** (links): Titel und Link auf den englischen Inhalt.
  Wenn es eine WordPress.tv-Aufnahme oder ein YouTube-Video gibt,
  erscheinen sie als kleine Links unter dem Titel.
- **Translation-Spalte** (rechts): Analog für die deutsche Übersetzung.
  Steht dort der englische Titel in Grau-Kursiv, gibt es noch keine
  deutsche Übersetzung.
- **Footer-Zeile**:
  - Links: Issue-Nummer (z. B. `#2952`) verlinkt auf das GitHub-Issue,
    daneben der Issue-Status `open`/`closed` und ggf. Marker
    („Verwaist", „Doppelt", „Original Entwurf", „Außerhalb Scope").
  - Rechts: bis zu sieben kleine farbige Icons für die Komponenten:
    Thumbnails, Text, Subtitles, Exercise, Quiz, Audio, Video. Hover
    über ein Icon zeigt einen Tooltip mit Status, Creator und Reviewer.

### Suchfeld

Live-Suche im Header. Tippen filtert die Karten, deren Titel (deutsch
oder englisch) oder Issue-Nummer den eingegebenen Text enthalten.

### Sections ein-/ausklappen

Klick auf den Titel einer Section (z. B. „Get Started With WordPress")
klappt die Karten darunter zu. Der ▾-Pfeil wird zu ▸. Erneuter Klick
klappt sie wieder auf. Der Zustand wird im Browser gespeichert — beim
nächsten Seitenaufruf bleiben sie wie zuletzt.

---

## Daten aktualisieren

Drei Wege:

### 1. Automatisch alle 12 Stunden

Die GitHub Action läuft per Cron und veröffentlicht eine neue
`tracker.json`. Das Plugin lädt sie spätestens nach Ablauf der
Cache-Dauer (Default 12 h).

### 2. „Cache jetzt leeren" im Plugin

In den Plugin-Einstellungen den Knopf drücken. Beim nächsten Seitenaufruf
holt das Plugin frische Daten — sofern die Action zwischenzeitlich
neu gebaut hat.

### 3. Action manuell triggern

Wer Schreibrechte am Action-Repo hat, kann den Workflow „Build tracker
data" über die GitHub-Web-Oberfläche manuell auslösen. Danach:

1. Etwa 2 Minuten warten, bis die Action durchgelaufen ist.
2. Im Plugin „Cache jetzt leeren" drücken.
3. Seite neu laden.

---

## Issues für neue Übersetzungen anlegen

> Diese Vorlage erweitert die offizielle WordPress/Learn-Translation-Vorlage um
> die Felder, die der DACH-Tracker braucht. Sie wird beim Anlegen eines neuen
> Issues im `WordPress/Learn`-Repo verwendet.
>
> Die Action liest **englische Bezeichner** und die **Status-Tabelle**. Deutsche
> Kommentare und Freitexte sind nur für Menschen.
>
> Diese Anleitung ist die Quelle für alle DACH-Team-Mitglieder, die ein
> Übersetzungs-Issue im `WordPress/Learn`-Repo anlegen.

### Ausgangspunkt: offizielle WordPress/Learn-Vorlage

So sieht die vorgegebene Vorlage aus, die `WordPress/Learn` beim Issue-Anlegen einsetzt:

```markdown
<!--
The steps to translating content on Learn WordPress can be found at
https://make.wordpress.org/training/handbook/content-localization/.
Remember to update the title of this issue by replacing the capitalized words.
Example: Greek translation for Lesson Plan "Introduction To Common Plugins"
-->
# Details
- Link to original content:
- Link to original content's GitHub issue (optional):
- Language you'll be translating to:
- Have you arranged for someone to review this translation?: Yes or No
- Reviewer's GitHub username:
- Other info:

# Next Steps
Once translated, please link or upload your translated files in a comment on this
issue, and request a [translation review](https://make.wordpress.org/training/handbook/content-localization/#translation-review).
```

Diese Vorlage ist absichtlich generisch — sie deckt alle Sprachen und Inhalts-Typen ab und kennt **keine** Felder für Komponenten-Status, Übersetzungs-URL oder Aufnahmen. Genau diese Felder ergänzt der DACH-Tracker.

### Ergänzte DACH-Vorlage (zum Kopieren)

Die folgende Variante übernimmt den offiziellen Block 1:1 und ergänzt darunter den Bereich, den unser Tracker braucht:

```markdown
<!--
The steps to translating content on Learn WordPress can be found at
https://make.wordpress.org/training/handbook/content-localization/.
Remember to update the title of this issue by replacing the capitalized words.
Example: Greek translation for Lesson Plan "Introduction To Common Plugins"
-->
# Details
- Link to original content: <URL>
- Link to original content's GitHub issue (optional):
- Language you'll be translating to: German
- Have you arranged for someone to review this translation?: Yes or No
- Reviewer's GitHub username:
- Other info:

# Translation Details
- German title: <Deutscher Lesson-Titel>
- Link to translated content: <URL or leave empty>
- Link to original WordPress.tv recording: <URL or leave empty>
- Link to translated WordPress.tv recording: <URL or leave empty>
- Link to original YouTube recording: <URL or leave empty>
- Link to translated YouTube recording: <URL or leave empty>

## Progress of the translation

<!-- TRANSLATION-STATUS-START -->
| Component  | Status | Creator | Reviewer |
|------------|--------|---------|----------|
| thumbnails | open   |         |          |
| text       | open   |         |          |
| subtitles  | open   |         |          |
| exercise   | open   |         |          |
| quiz       | open   |         |          |
| audio      | open   |         |          |
| video      | open   |         |          |
<!-- TRANSLATION-STATUS-END -->

# Next Steps
Once translated, please link or upload your translated files in a comment on this
issue, and request a [translation review](https://make.wordpress.org/training/handbook/content-localization/#translation-review).
```

**Drei Blöcke** also: `# Details` (offiziell, unverändert), `# Translation Details` (DACH-Erweiterung) und die **Status-Tabelle**.

### Was die offizielle Vorlage liefert — und was unser Tracker davon braucht

| Offizielles Feld | Wofür offiziell | Nutzt der DACH-Tracker? |
|---|---|---|
| `Link to original content:` | Verweis auf die englische Lesson | ✅ Pflicht — Matching gegen `scope.yml` |
| `Link to original content's GitHub issue (optional):` | Cross-Reference auf upstream-Issue | ❌ Ignoriert |
| `Language you'll be translating to:` | Sprache des Übersetzers | ❌ Ignoriert — kommt aus dem Project-V2-Feld `Locale=German` |
| `Have you arranged for someone to review this translation?:` | Social-Check | ❌ Ignoriert |
| `Reviewer's GitHub username:` | Haupt-Reviewer | ❌ Ignoriert — pro Komponente steht der Reviewer in der Status-Tabelle |
| `Other info:` | Freitext | ❌ Ignoriert |

Der Tracker greift also nur auf **ein** offizielles Feld zu (`Link to original content`). Der Rest ist Mensch-zu-Mensch-Information.

### Was wir im `# Translation Details`-Block ergänzen

| Feld | Pflicht? | Wirkung im Tracker |
|---|---|---|
| `German title:` | Empfohlen | Wird in der Übersetzungs-Spalte der Karte angezeigt. Wenn leer → englischer Titel grau-kursiv (Placeholder). |
| `Link to translated content:` | Optional | Wenn vorhanden: Link unter dem deutschen Titel. |
| `Link to original WordPress.tv recording:` | Optional | EN-Aufnahme als Link unter dem englischen Titel. |
| `Link to translated WordPress.tv recording:` | Optional | DE-Aufnahme als Link unter dem deutschen Titel. |
| `Link to original YouTube recording:` | Optional | Analog. |
| `Link to translated YouTube recording:` | Optional | Analog. |

Der Parser ist tolerant gegen Schreibvarianten:

- `German title` ↔ `German lesson name` ↔ `Deutscher Titel` ↔ `Translation title` ↔ `Translated title`
- `Link to WordPress.tv recording:` (ohne original/translated) → wird als **deutsche** Aufnahme interpretiert (Backwards-Compat mit älteren Issues)
- Format-egal: sowohl `- Field: value` (offizieller WordPress/Learn-Stil) als auch `**Field:** value` (DACH-Bold-Stil) werden erkannt.

### Status-Tabelle (Pflicht)

Diese Tabelle ist **das Herz des Trackers** — ohne sie kennt das Plugin keinen Komponenten-Status.

```markdown
## Progress of the translation

<!-- TRANSLATION-STATUS-START -->
| Component  | Status | Creator   | Reviewer  |
|------------|--------|-----------|-----------|
| thumbnails | done   | rfluethi  | Ursha-wp  |
| text       | done   | rfluethi  | Ursha-wp  |
| subtitles  | open   |           |           |
| exercise   | na     |           |           |
| quiz       | na     |           |           |
| audio      | na     |           |           |
| video      | wip    | rfluethi  |           |
<!-- TRANSLATION-STATUS-END -->
```

**Regeln:**

- Marker `<!-- TRANSLATION-STATUS-START -->` und `<!-- TRANSLATION-STATUS-END -->` müssen genau so stehen, nicht entfernen.
- Header-Zeile und Trennzeile bleiben drin.
- **Status-Werte:** `open` · `wip` · `review` · `done` · `na`
- **Creator / Reviewer:** GitHub-Benutzername **ohne** `@`-Präfix (z. B. `rfluethi`, nicht `@rfluethi`). Mehrere durch Komma trennen.
- Nicht zutreffende Komponenten als `na` markieren oder die Zeile weglassen.

### Zeilen je Item-Typ

Die Komponenten-Tabelle braucht nur die Zeilen, die für den Item-Typ Sinn ergeben:

| Item-Typ | Komponenten in der Tabelle (Reihenfolge wie im Tracker) |
|---|---|
| `lesson` | thumbnails, text, subtitles, exercise, quiz, audio, video |
| `lesson_plan` | thumbnails, text |
| `tutorial` | thumbnails, text, subtitles, video |
| `handbook_text` | text |
| `handbook_video` | thumbnails, text, subtitles, video |

Für Handbook-Inhalte (auf `make.wordpress.org/training/handbook/`) gilt die obige Vorlage analog — die Komponenten-Tabelle wird kürzer, weil Handbook-Seiten kein Video/Quiz/Exercise/Audio haben (siehe Item-Typen-Tabelle oben: `handbook_text` bzw. `handbook_video`).

### Felder, die *nicht* mehr ins Issue gehören

Diese Felder waren in älteren Versionen drin, sind aber nicht mehr nötig — sie kommen jetzt aus dem Inventory bzw. ergeben sich automatisch:

- **Original-Titel** (`Original title:`) — wird automatisch aus `learn.wordpress.org` gezogen.
- **Sortierung** (`Order:`) — Reihenfolge ergibt sich aus `scope.yml`.

Wer ein altes Issue migriert: die Felder dürfen drinbleiben, der Parser ignoriert sie still.

### URL-Form

Die `Link to original content`-URL muss **kanonisch** sein, sonst matcht der Tracker das Issue nicht mit dem Inventory:

- `https://` (nicht `http://`)
- lowercase
- mit Trailing-Slash (`/` am Ende)
- ohne Query-Parameter (`?foo=bar` weglassen)
- ohne Fragment (`#section` weglassen)
- kein `www.`-Subdomain

**Beispiel korrekt:**

```text
https://learn.wordpress.org/lesson/wordpress-essentials-domains-and-hosting/
```

**Beispiel falsch (würde nicht matchen):**

```text
http://learn.wordpress.org/lesson/WordPress-Essentials
https://learn.wordpress.org/lesson/wordpress-essentials?ref=email
```

### Vollständiges Beispiel für ein gepflegtes Issue

```markdown
<!--
The steps to translating content on Learn WordPress can be found at
https://make.wordpress.org/training/handbook/content-localization/.
Remember to update the title of this issue by replacing the capitalized words.
Example: Greek translation for Lesson Plan "Introduction To Common Plugins"
-->
# Details
- Link to original content: https://learn.wordpress.org/lesson/wordpress-essentials-domains-and-hosting/
- Link to original content's GitHub issue (optional):
- Language you'll be translating to: German
- Have you arranged for someone to review this translation?: Yes
- Reviewer's GitHub username: Ursha-wp
- Other info:

# Translation Details
- German title: WordPress-Grundlagen: Domains und Hosting
- Link to translated content: https://learn.wordpress.org/lesson/wordpress-grundlagen-domains-und-hosting/
- Link to original WordPress.tv recording: https://wordpress.tv/2024/01/foo-en/
- Link to translated WordPress.tv recording: https://wordpress.tv/2025/11/18/wordpress-grundlagen-domains-und-hosting/
- Link to original YouTube recording: https://www.youtube.com/watch?v=AAA
- Link to translated YouTube recording: https://www.youtube.com/watch?v=Vj3pFHoFSTY

## Progress of the translation

<!-- TRANSLATION-STATUS-START -->
| Component  | Status | Creator   | Reviewer  |
|------------|--------|-----------|-----------|
| thumbnails | done   | rfluethi  | Ursha-wp  |
| text       | done   | rfluethi  | Ursha-wp  |
| subtitles  | done   | rfluethi  | Ursha-wp  |
| exercise   | done   | rfluethi  | Ursha-wp  |
| quiz       | done   | rfluethi  | Ursha-wp  |
| audio      | done   | Ursha-wp  | rfluethi  |
| video      | done   | rfluethi  | Ursha-wp  |
<!-- TRANSLATION-STATUS-END -->

# Next Steps
Once translated, please link or upload your translated files in a comment on this
issue, and request a [translation review](https://make.wordpress.org/training/handbook/content-localization/#translation-review).
```

---

## Häufige Probleme

### Das Dashboard ist leer / zeigt „Tracker-Daten werden vorbereitet"

- Die JSON-Datei wurde noch nie erfolgreich geladen — das passiert beim
  allerersten Mal, bevor die erste Action durchgelaufen ist.
- Die JSON-URL in den Settings ist falsch oder nicht erreichbar.
- Die Site hat keinen Internet-Zugriff auf `raw.githubusercontent.com`.

**Lösung:** in den Plugin-Einstellungen die URL prüfen und „Cache leeren"
drücken. Falls trotzdem leer, einen Site-Admin bitten, das Action-Repo
zu prüfen.

### Es steht ein älteres Datum unter „Stand: …"

Der Cache ist noch nicht abgelaufen. Entweder warten oder „Cache leeren"
drücken.

### Eine Übersetzung fehlt im Dashboard

- Issue-Nummer im DACH-GitHub-Projekt prüfen — gibt es das Issue
  überhaupt?
- Issue muss das Label `Locale=German` haben.
- Der Original-URL im Issue muss exakt der Inventory-URL entsprechen
  (lowercase, mit Trailing-Slash, ohne Query-Parameter).
- Falls alles passt, läuft die nächste Action und das Item erscheint
  beim nächsten Cache-Update.

### Eine Karte zeigt „Verwaist" oder „Außerhalb Scope"

- **Verwaist**: das Issue verweist auf eine URL, die im Inventory
  (learn.wordpress.org) nicht mehr existiert (umbenannt, gelöscht).
- **Außerhalb Scope**: das Issue ist zwar gültig, aber die URL steht
  nicht in der `scope.yml` der Action — bewusste Auswahl welcher
  Inhalte ins Dashboard kommen.

In beiden Fällen ist Handlungsbedarf entweder am Issue (URL korrigieren)
oder am Scope (Item in `scope.yml` aufnehmen) — siehe Entwickler-
Dokumentation.

### Status-Filter und Suche reagieren nicht

Bekannter Bug in v2.1.1 (Mai 2026). Das JavaScript für die Interaktion
lädt in bestimmten Theme/Page-Builder-Kombinationen nicht. Wird in einer
nachfolgenden Version behoben. **Workaround:** statt Filter zu klicken,
einen Shortcode mit `pathway=`-Attribut auf einer eigenen Seite
verwenden — das filtert serverseitig und funktioniert immer.

---

## Einen Bug melden

Bevor du einen Bug meldest, bitte folgendes notieren:

1. **Plugin-Version** (steht in der Plugin-Liste und in den Einstellungen).
2. **WordPress-Version** und ggf. **Page-Builder/Theme**.
3. **Welcher Shortcode** auf der Seite verwendet wurde.
4. **Was du erwartet hast** und **was tatsächlich passiert ist**.
5. **Screenshot** der Seite — am besten Browser-Vollbild.
6. **Browser-Konsole** (F12 → Console-Tab) — kopiere alle roten Fehler.

Issue im Action-Repo öffnen:
[github.com/rfluethi/Training-Translation-Tracker-Inventory-Plugin/issues](https://github.com/rfluethi/Training-Translation-Tracker-Inventory-Plugin/issues)

Oder direkt per E-Mail an den Maintainer (siehe Plugin-Header).

---

## Weiterführende Dokumente

- [Entwickler-Dokumentation](Entwickler-Dokumentation.md) — Architektur,
  Datenmodell, Code-Struktur.
- [Top-Level-README](../../README.md) — Mono-Repo-Übersicht mit Action und Plugin.
