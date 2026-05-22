# Issue-Vorlagen für DACH-Übersetzungen

> Diese Datei enthält alle Vorlagen, die im DACH-Team beim Anlegen eines Übersetzungs-Issues
> verwendet werden. Es gibt zwei Sorten:
>
> - **Lessons / Lesson-Plans / Tutorials** auf `learn.wordpress.org`
> - **Handbook-Seiten** (Text oder mit Video) auf `make.wordpress.org/training/handbook/`
>
> Die gemeinsamen Regeln stehen oben, die typ-spezifischen Vorlagen unten.

---

## Gemeinsame Regeln (gelten für ALLE Vorlagen)

### Pflicht-Anforderungen

Damit ein Issue korrekt im Tracker erscheint, müssen drei Dinge stimmen:

1. **Original-URL in kanonischer Form** — siehe nächster Abschnitt.
2. **Locale-Markierung im Projekt-Board** — Issue im DACH-Projekt-Board mit `Locale = German` taggen. Sonst sieht die Action das Issue nicht.
3. **Status-Tabelle mit HTML-Markern** — Markdown-Tabelle zwischen `<!-- TRANSLATION-STATUS-START -->` und `<!-- TRANSLATION-STATUS-END -->`. 1:1 aus der Vorlage übernehmen, sonst kein Status-Parsing.

### URL-Form (kanonisch)

```
https://, lowercase, mit Trailing-Slash, ohne Query (?…), ohne Fragment (#…), kein www.
```

| Korrekt | Falsch |
|---|---|
| `https://learn.wordpress.org/lesson/wordpress-essentials-domains-and-hosting/` | `http://learn.wordpress.org/lesson/WordPress-Essentials` |
| `https://make.wordpress.org/training/handbook/about/team-values/` | `https://learn.wordpress.org/lesson/wordpress-essentials?ref=email` |

### Status-Werte

`open` · `wip` · `review` · `done` · `na`

Andere Werte werden vom Parser ignoriert.

### Creator / Reviewer

- GitHub-Benutzername **ohne** `@`-Präfix (also `rfluethi`, nicht `@rfluethi`).
- Mehrere Personen durch Komma trennen: `rfluethi, Ursha-wp`.
- Leer lassen, wenn noch niemand fest zugewiesen ist.

### Ein Issue pro Inhalt und Sprache

Pro Original-URL und Sprache existiert nur **ein** Issue. Wenn aus Versehen zwei entstehen, markiert der Tracker beide mit einem Warnsymbol „mehrfaches Issue" — die Bereinigung erfolgt manuell durch das Team.

### Felder, die *nicht* mehr ins Issue gehören

Diese Felder kommen heute automatisch aus dem Inventar bzw. dem Project-V2-Board:

- **Original-Titel** (`Original title:`) — wird aus learn.wordpress.org / dem Handbook gezogen.
- **Sortier-Reihenfolge** (`Order:`) — ergibt sich aus `scope.yml`.
- **Pathway / Course / Section** — wird automatisch zugeordnet.

Wer alte Issues migriert: die Felder dürfen drinbleiben, der Parser ignoriert sie still.

### Komponenten-Set je Inhalts-Typ

Die Status-Tabelle braucht nur die Zeilen, die für den Typ relevant sind. Definiert in `action/component-templates.yml`:

| Item-Typ | Komponenten (Reihenfolge im Tracker) |
|---|---|
| `lesson` | thumbnails, text, subtitles, exercise, quiz, audio, video |
| `lesson_plan` | thumbnails, text |
| `tutorial` | thumbnails, text, subtitles, video |
| `handbook_text` | text |
| `handbook_video` | thumbnails, text, subtitles, video |

Nicht zutreffende Komponenten als `na` markieren oder die Zeile weglassen.

---

## Vorlage 1 — Lessons / Lesson-Plans / Tutorials

Diese Vorlage erweitert die offizielle `WordPress/Learn`-Translation-Vorlage. Der offizielle Block (`# Details`) wird 1:1 übernommen, darunter folgen die DACH-spezifischen Felder.

### Vorlage zum Kopieren

```markdown
<!--
The steps to translating content on Learn WordPress can be found at
https://make.wordpress.org/training/handbook/content-localization/.
Remember to update the title of this issue by replacing the capitalized words.
Example: German translation for Lesson "What is WordPress"
-->
# Details
- Link to original content: <URL>
- Link to original content's GitHub issue (optional):
- Language you'll be translating to: German
- Have you arranged for someone to review this translation?: Yes or No
- Reviewer's GitHub username:
- Other info:

# Translation Details
- German title: <Deutscher Titel>
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

### Was offiziell vs. DACH-Erweiterung ist

Vom offiziellen `# Details`-Block nutzt der Tracker nur **ein** Feld: `Link to original content` (Pflicht — Matching gegen `scope.yml`). Der Rest ist Mensch-zu-Mensch-Information.

Der `# Translation Details`-Block ist die DACH-Erweiterung. Alle Felder sind optional außer dem Format selbst:

| Feld | Wirkung im Tracker |
|---|---|
| `German title:` | Übersetzungs-Spalte der Karte. Leer → englischer Titel grau-kursiv als Placeholder. |
| `Link to translated content:` | Link unter dem deutschen Titel. |
| `Link to original WordPress.tv recording:` | EN-Aufnahme als Link unter dem englischen Titel. |
| `Link to translated WordPress.tv recording:` | DE-Aufnahme als Link unter dem deutschen Titel. |
| `Link to original YouTube recording:` | Analog. |
| `Link to translated YouTube recording:` | Analog. |

### Tolerante Feld-Erkennung

Der Parser akzeptiert mehrere Schreibvarianten:

- `German title` ↔ `German lesson name` ↔ `Deutscher Titel` ↔ `Translation title` ↔ `Translated title`
- `Link to WordPress.tv recording:` (ohne *original/translated*) → wird als **deutsche** Aufnahme interpretiert (Backwards-Compat).
- Format-egal: `- Field: value` (offiziell) **und** `**Field:** value` (DACH-Bold-Stil) werden erkannt.

---

## Vorlage 2 — Handbook (`handbook_text`, reine Text-Seite)

Für Handbook-Inhalte unter `https://make.wordpress.org/training/handbook/...`, die **keine** Video-Aufnahme haben.

### Vorlage zum Kopieren

```markdown
**Link to original content:** https://make.wordpress.org/training/handbook/...
**Link to translated content:**
**German title:**

## Progress of the translation

<!-- TRANSLATION-STATUS-START -->
| Component | Status | Creator | Reviewer |
|-----------|--------|---------|----------|
| text      | open   |         |          |
<!-- TRANSLATION-STATUS-END -->
```

Das ist das Minimum. `Link to translated content` und `German title` dürfen leer bleiben — werden ergänzt, sobald eine deutsche Version steht.

---

## Vorlage 3 — Handbook (`handbook_video`, mit Video-Aufnahme)

Für Handbook-Seiten mit eingebettetem Video.

### Vorlage zum Kopieren

```markdown
**Link to original content:** https://make.wordpress.org/training/handbook/...
**Link to translated content:**
**German title:**
**Link to original WordPress.tv recording:**
**Link to translated WordPress.tv recording:**
**Link to original YouTube recording:**
**Link to translated YouTube recording:**

## Progress of the translation

<!-- TRANSLATION-STATUS-START -->
| Component  | Status | Creator | Reviewer |
|------------|--------|---------|----------|
| thumbnails | open   |         |          |
| text       | open   |         |          |
| subtitles  | open   |         |          |
| video      | open   |         |          |
<!-- TRANSLATION-STATUS-END -->
```

---

## Was bei Handbook anders ist als bei Lessons

- **Keine Pathway/Course-Zuordnung.** Handbook-Items werden automatisch in einer eigenen Top-Level-Gruppe „Training Handbook" angezeigt, unterteilt nach ihrem Top-Level-Section-Slug (`about`, `getting-started`, …). Die Action ermittelt diese Hierarchie selbst über den `parent`-Field der Handbook-REST-API.
- **Keine `quiz`/`exercise`/`audio`-Komponenten.** Wer doch eines dieser Komponenten-Typen für Handbook braucht, fügt sie einfach in die Tabelle ein — die Action akzeptiert beliebige Komponenten-Namen (das Frontend rendert nur bekannte Icons, unbekannte ignoriert).
- **Kompakteres Format.** Statt `# Details` / `# Translation Details`-Blöcken reicht `**Field:** value`-Syntax. Der Parser erkennt beide Stile.

---

## Vollständiges Beispiel — gepflegtes Lesson-Issue

```markdown
<!--
The steps to translating content on Learn WordPress can be found at
https://make.wordpress.org/training/handbook/content-localization/.
Remember to update the title of this issue by replacing the capitalized words.
Example: German translation for Lesson "What is WordPress"
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

## Vollständiges Beispiel — gepflegtes Handbook-Issue

```markdown
**Link to original content:** https://make.wordpress.org/training/handbook/about/team-values/
**Link to translated content:**
**German title:** Team-Werte

## Progress of the translation

<!-- TRANSLATION-STATUS-START -->
| Component | Status | Creator   | Reviewer |
|-----------|--------|-----------|----------|
| text      | wip    | rfluethi  |          |
<!-- TRANSLATION-STATUS-END -->
```

---

## Bezug zu anderen Dokumenten

- Architektur und Entscheidungen: [Architektur.md](Architektur.md)
- Plugin-Bedienung und Issue-Workflow: [User-Guide.md](User-Guide.md)
- Kompakte Team-Demo (1 A4, beim Maintainer außerhalb des Repos): `../Konzept/Team-Uebersicht.md`
- Komponenten-Templates (Source-of-Truth): `action/component-templates.yml`
- Scope-Konfiguration: `action/scope.yml`
