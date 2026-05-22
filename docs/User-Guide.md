# User-Guide — Training Translation Tracker

> **Zielgruppe:** WordPress-Site-Admins, die den Tracker auf einer Seite einbinden, sowie Übersetzer:innen, die das Dashboard nutzen.
> **Voraussetzung:** Keine PHP-Kenntnisse nötig. Du brauchst Schreibrechte am WordPress-Admin und (für Issue-Pflege) einen GitHub-Account.

---

## 1. Was macht der Tracker?

Der Translation Tracker ist ein Dashboard für den Übersetzungsfortschritt der WordPress-Lerninhalte auf `learn.wordpress.org` und im Training-Handbook. Auf einer WordPress-Seite zeigt er, welche Inhalte schon übersetzt sind, welche gerade bearbeitet werden und welche noch offen sind. Pro Inhalt sieht man, in welchem Status die einzelnen Komponenten (Text, Untertitel, Quiz, Video …) sind und wer daran arbeitet.

Die Daten kommen nicht aus der WordPress-Site selbst, sondern aus einer **GitHub Action**, die alle 12 Stunden eine JSON-Datei aktualisiert. Das Plugin liest diese Datei pro Cache-Zyklus und rendert daraus die Übersicht. Diese Trennung hält die Site schnell und das Plugin schlank.

**Drei Bestandteile des Gesamtsystems:**

| Komponente | Wo? | Was? |
|---|---|---|
| GitHub-Issues | `WordPress/Learn`-Repo, DACH-Projekt-Board | Übersetzer:innen pflegen Status pro Inhalt |
| GitHub Action | `Training-Translation-Tracker-Inventory-Plugin` | Baut `tracker.json` alle 12 h |
| WordPress-Plugin | Diese Site | Liest JSON, zeigt Dashboard |

Du arbeitest hauptsächlich mit den GitHub-Issues (Status pflegen) und siehst das Ergebnis im Dashboard.

---

## 2. Installation

### Voraussetzungen

| | |
| --- | --- |
| WordPress | 6.0 oder höher |
| PHP | 8.0 oder höher |
| Internet-Zugriff | Die Site muss `raw.githubusercontent.com` erreichen können |

### Installations-Schritte

1. **ZIP herunterladen** — aktuelles `training-translation-tracker.zip` aus dem [Release-Tab des GitHub-Repos](https://github.com/rfluethi/Training-Translation-Tracker-Inventory-Plugin/releases) holen.
2. Im WP-Admin **Plugins → Installieren** klicken.
3. Oben auf **Plugin hochladen**, dann die ZIP-Datei auswählen.
4. **Jetzt installieren** klicken.
5. Nach erfolgreichem Upload auf **Plugin aktivieren**.

Falls bereits eine ältere Version installiert ist, **zuerst die alte Version deaktivieren und löschen** (rote „Löschen"-Aktion in der Plugin-Liste). Erst dann die neue ZIP hochladen. Einstellungen und Cache bleiben dabei erhalten.

### Verifikation

Nach der Installation steht in der Plugin-Liste ein Eintrag „**Training Translation Tracker**" mit Versionsnummer. Im Menü **Einstellungen** gibt es einen neuen Unterpunkt „**Translation Tracker**".

---

## 3. Plugin-Einstellungen

Erreichbar unter **WP-Admin → Einstellungen → Translation Tracker**.

### URL der `tracker.json`

Die Adresse, von der das Plugin die JSON-Datei lädt. Default zeigt auf den `data`-Branch des Inventory-Plugin-Repos:

```text
https://raw.githubusercontent.com/rfluethi/Training-Translation-Tracker-Inventory-Plugin/data/tracker.json
```

Diese URL nur ändern, wenn das Action-Repo umzieht oder eine andere Datenquelle verwendet werden soll. Für 99 % der Fälle bleibt sie wie voreingestellt.

### Cache-Dauer (Stunden)

Wie lange die geladene JSON im WordPress-Transient-Cache liegt, bevor neu geladen wird. Default ist 12 Stunden — passend zum 12-h-Rhythmus der Action.

- **Kürzer setzen** (z. B. 1 Stunde) während Test-Phasen oder wenn schnelle Updates gebraucht werden.
- **Länger setzen** (z. B. 24 Stunden) wenn die Datenquelle stabil ist und der HTTP-Traffic minimiert werden soll.

Erlaubter Bereich: 1 – 168 Stunden (also bis zu eine Woche).

### Knopf „Cache jetzt leeren"

Erzwingt einen frischen Fetch beim nächsten Seitenaufruf. Nützlich, wenn gerade eine Aktualisierung auf GitHub gemacht wurde und du sie sofort sehen willst, ohne den Cache-Ablauf abzuwarten.

### Anzeige „Stand: …"

Zeigt den `generated_at`-Zeitstempel aus dem aktuellen Cache. Daran sieht man, wann die Action den jetzt gecachten Datenstand zuletzt gebaut hat. Steht dort ein älteres Datum als erwartet, ist der Cache veraltet — entweder „Cache leeren" drücken oder die Action manuell triggern (siehe Abschnitt 6).

### Shortcode-Beispiele

Direkt unter den Settings-Feldern listet das Plugin Shortcode-Beispiele mit „Kopieren"-Buttons. Damit kann man sich häufig benutzte Varianten direkt mitnehmen, ohne sie aus diesem Dokument abzutippen.

---

## 4. Den Tracker auf einer Seite einbauen

Im WordPress-Editor (Gutenberg, Classic oder Page-Builder):

1. Eine **Seite** öffnen oder neu anlegen, auf der das Dashboard erscheinen soll. Üblich ist eine Seite mit dem Titel „Translation Tracker".
2. **Shortcode-Block** einfügen (in Gutenberg: „/" → „Shortcode").
3. In den Block-Inhalt schreiben:

   ```text
   [translation_tracker]
   ```

4. Seite **speichern und veröffentlichen**.
5. **Seite öffnen** (Vorschau oder Live-URL). Das Dashboard erscheint an der Stelle, wo der Shortcode steht.

In Page-Buildern (Elementor, Divi …) gibt es ebenfalls einen Shortcode-Block oder ein Text-Widget, das Shortcodes ausführt. Dort `[translation_tracker]` einfügen.

### Shortcode-Attribute

Über Attribute steuert man, was das Dashboard zeigt. Mehrere können kombiniert werden:

| Attribut | Werte | Wirkung |
|---|---|---|
| `pathway` | Slug einer Pathway, mehrere durch Komma getrennt | Zeigt nur die genannten Lernpfade |
| `show_pathways` | `yes`/`no` | Alle Pathway-Gruppen zeigen oder verstecken (Default `yes`) |
| `show_orphans` | `yes`/`no` | „Sonstige (außerhalb Scope)" zeigen oder verstecken |
| `show_handbook` | `yes`/`no` | Training-Handbook-Gruppe zeigen oder verstecken |
| `show_stats` | `yes`/`no` | Stats-Header oben mit den Pillen zeigen oder verstecken |

### Smart-Defaults

- Wenn `pathway` gesetzt ist, blendet das Plugin **automatisch** Orphan- und Handbook-Gruppen aus (vermutete Intention: „ich will nur diese Pathway-Übersicht"). Wer sie trotzdem will, gibt explizit `show_orphans="yes"` an.
- Wenn `pathway` **nicht** gesetzt ist, werden Orphan und Handbook standardmäßig mitgezeigt.

### Pathway-Slug-Matching

Das `pathway`-Attribut akzeptiert mehrere Schreibweisen für denselben Pathway:

- Kurz-Slug: `pathway="user"`
- Voller Label-Slug: `pathway="beginner-wordpress-user"`
- Original-Label: `pathway="Beginner WordPress User"`

Alle drei matchen dieselbe Pathway-Gruppe. Groß-/Kleinschreibung ist egal.

### Beispiele

**Komplette Übersicht:**

```text
[translation_tracker]
```

**Eine Seite pro Pathway:**

```text
[translation_tracker pathway="user"]
[translation_tracker pathway="lesson-plans"]
```

**Mehrere Pathways:**

```text
[translation_tracker pathway="user, contributor"]
```

**Stats verstecken (eigener Header):**

```text
[translation_tracker show_stats="no"]
```

**Nur das Training-Handbook:**

```text
[translation_tracker show_pathways="no" show_orphans="no"]
```

**Pathway plus Handbook, ohne Orphans:**

```text
[translation_tracker pathway="user" show_handbook="yes" show_orphans="no"]
```

Das explizite `show_handbook="yes"` überschreibt das Smart-Default-Verhalten.

---

## 5. Den Tracker im Frontend bedienen

### Stats-Pillen oben

Zeigen die Gesamtzahlen pro Status:

- **Items** — alle Items zusammen
- **fertig** (grün) — `overall_status = done`
- **Review** (gelb) — `overall_status = review`
- **in Arbeit** (blau) — `overall_status = wip`
- **offen** (grau) — `overall_status = open`
- **n/a** (hellgrau) — `overall_status = na`

Klick auf eine Pille filtert die Karten auf diesen Status. Erneuter Klick auf „Items" setzt den Filter zurück.

### Die Karten

Jede Karte zeigt einen Inhalt:

- **Status-Balken links** in der Farbe des `overall_status`.
- **Original-Spalte** (links): Titel und Link auf den englischen Inhalt. Wenn es eine WordPress.tv-Aufnahme oder ein YouTube-Video gibt, erscheinen sie als kleine Links unter dem Titel.
- **Translation-Spalte** (rechts): Analog für die deutsche Übersetzung. Steht dort der englische Titel in Grau-Kursiv, gibt es noch keine deutsche Übersetzung.
- **Footer-Zeile:**
  - Links: Issue-Nummer (z. B. `#2952`) verlinkt auf das GitHub-Issue, daneben der Issue-Status `open`/`closed` und ggf. Marker („Verwaist", „Doppelt", „Original Entwurf", „Außerhalb Scope").
  - Rechts: bis zu sieben kleine farbige Icons für die Komponenten (Thumbnails, Text, Subtitles, Exercise, Quiz, Audio, Video). Hover über ein Icon öffnet ein Popover mit Status, Creator + Avatar und Reviewer + Avatar.

### Suchfeld

Live-Suche im Header. Tippen filtert die Karten, deren Titel (deutsch oder englisch) oder Issue-Nummer den eingegebenen Text enthalten.

### Sections ein-/ausklappen

Klick auf den Titel einer Section (z. B. „Get Started With WordPress") klappt die Karten darunter zu. Der ▾-Pfeil wird zu ▸. Erneuter Klick klappt sie wieder auf. Der Zustand wird im Browser gespeichert — beim nächsten Seitenaufruf bleiben sie wie zuletzt.

---

## 6. Daten aktualisieren

Drei Wege:

### 1. Automatisch alle 12 Stunden

Die GitHub Action läuft per Cron und veröffentlicht eine neue `tracker.json`. Das Plugin lädt sie spätestens nach Ablauf der Cache-Dauer (Default 12 h).

### 2. „Cache jetzt leeren" im Plugin

In den Plugin-Einstellungen den Knopf drücken. Beim nächsten Seitenaufruf holt das Plugin frische Daten — sofern die Action zwischenzeitlich neu gebaut hat.

### 3. Action manuell triggern

Wer Schreibrechte am Action-Repo hat, kann den Workflow „Build tracker.json" über die GitHub-Web-Oberfläche manuell auslösen. Danach:

1. Etwa 2 Minuten warten, bis die Action durchgelaufen ist.
2. Im Plugin „Cache jetzt leeren" drücken.
3. Seite neu laden.

---

## 7. Issues für neue Übersetzungen anlegen

Die vollständige Vorlage steht in [Issue-Vorlagen-DACH.md](Issue-Vorlagen-DACH.md). Hier nur die wichtigsten Punkte:

### Wo anlegen

Im Repo **`WordPress/Learn`** — nicht im Inventory-Plugin-Repo. Das Issue muss im DACH-Projekt-Board mit Custom-Field `Locale = German` markiert sein.

### Drei Pflicht-Punkte

1. **Original-URL kanonisch** — `https://`, lowercase, Trailing-Slash, ohne Query/Fragment, ohne `www.`.

   Korrekt: `https://learn.wordpress.org/lesson/wordpress-essentials-domains-and-hosting/`

   Falsch: `http://learn.wordpress.org/lesson/WordPress-Essentials` oder `…?ref=email`

2. **Locale-Markierung** im DACH-Projekt-Board (`Locale = German`).

3. **Status-Tabelle mit HTML-Markern** — Markdown-Tabelle zwischen `<!-- TRANSLATION-STATUS-START -->` und `<!-- TRANSLATION-STATUS-END -->`. 1:1 aus der Vorlage übernehmen.

### Statuswerte

`open` · `wip` · `review` · `done` · `na`

### Creator / Reviewer

GitHub-Benutzername **ohne** `@`-Präfix (also `rfluethi`, nicht `@rfluethi`). Mehrere durch Komma trennen. Leer lassen, wenn noch niemand fest zugewiesen ist.

### Pro Inhalt und Sprache nur ein Issue

Wenn aus Versehen zwei Issues zur gleichen URL angelegt werden, zeigt der Tracker beide mit einem Warnsymbol „mehrfaches Issue". Bereinigung manuell — eines schließen oder umwidmen.

---

## 8. Häufige Probleme

### Das Dashboard ist leer / zeigt „Tracker-Daten werden vorbereitet"

Ursachen:

- Die JSON-Datei wurde noch nie erfolgreich geladen — passiert beim allerersten Mal, bevor die erste Action durchgelaufen ist.
- Die JSON-URL in den Settings ist falsch oder nicht erreichbar.
- Die Site hat keinen Internet-Zugriff auf `raw.githubusercontent.com`.

**Lösung:** in den Plugin-Einstellungen die URL prüfen und „Cache leeren" drücken. Falls trotzdem leer, einen Site-Admin bitten, das Action-Repo zu prüfen.

### Es steht ein älteres Datum unter „Stand: …"

Der Cache ist noch nicht abgelaufen. Entweder warten oder „Cache leeren" drücken.

### Eine Übersetzung fehlt im Dashboard

- Issue-Nummer im DACH-GitHub-Projekt prüfen — gibt es das Issue überhaupt?
- Issue muss das Label `Locale=German` haben.
- Der Original-URL im Issue muss exakt der Inventory-URL entsprechen (lowercase, mit Trailing-Slash, ohne Query-Parameter).
- Falls alles passt, läuft die nächste Action und das Item erscheint beim nächsten Cache-Update.

### Eine Karte zeigt „Verwaist" oder „Außerhalb Scope"

- **Verwaist:** das Issue verweist auf eine URL, die im Inventory (learn.wordpress.org) nicht mehr existiert (umbenannt, gelöscht).
- **Außerhalb Scope:** das Issue ist zwar gültig, aber die URL steht nicht in der `scope.yml` der Action — bewusste Auswahl, welche Inhalte ins Dashboard kommen.

In beiden Fällen ist Handlungsbedarf entweder am Issue (URL korrigieren) oder am Scope (Item in `scope.yml` aufnehmen, siehe Developer-Doku).

### Status-Filter und Suche reagieren nicht

In bestimmten Theme/Page-Builder-Kombinationen lädt das JavaScript für die Interaktion nicht zuverlässig. **Workaround:** statt Filter zu klicken, einen Shortcode mit `pathway=`-Attribut auf einer eigenen Seite verwenden — das filtert serverseitig und funktioniert immer.

Diagnose: Browser-Konsole öffnen (F12 → Console + Network) und prüfen, ob `tracker.js` mit HTTP 200 geladen wird. Wenn nicht, hat der Page-Builder den `<script src>`-Tag aus dem Output entfernt.

---

## 9. Einen Bug melden

Bevor du einen Bug meldest, bitte folgendes notieren:

1. **Plugin-Version** (steht in der Plugin-Liste und in den Einstellungen).
2. **WordPress-Version** und ggf. **Page-Builder / Theme**.
3. **Welcher Shortcode** auf der Seite verwendet wurde.
4. **Was du erwartet hast** und **was tatsächlich passiert ist**.
5. **Screenshot** der Seite — am besten Browser-Vollbild.
6. **Browser-Konsole** (F12 → Console-Tab) — alle roten Fehler kopieren.

Issue im Action-Repo öffnen: <https://github.com/rfluethi/Training-Translation-Tracker-Inventory-Plugin/issues>

Oder direkt per E-Mail an den Maintainer (siehe Plugin-Header).

---

## Weiterführende Dokumente

- 1-Seiten-Demo fürs Team: `Team-Uebersicht.md` (liegt lokal beim Maintainer, außerhalb des Repos)
- System-Architektur: [Architektur.md](Architektur.md)
- Betrieb (Releases, Token, Recovery): [Operations.md](Operations.md)
- Code & Erweiterungen: [Developer.md](Developer.md)
