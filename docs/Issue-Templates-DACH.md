# Issue templates for DACH translations

> This file contains all the templates used by the DACH team when creating a
> translation issue. There are two kinds:
>
> - **Lessons / Lesson Plans / Tutorials** on `learn.wordpress.org`
> - **Handbook pages** (text or with video) on `make.wordpress.org/training/handbook/`
>
> The shared rules sit at the top, the type-specific templates below.

## Shared rules (apply to ALL templates)

### Mandatory requirements

For an issue to show up correctly in the tracker, three things must be right:

1. **Original URL in canonical form**, see next section.
2. **Locale marking on the project board**, tag the issue on the DACH project board with `Locale = German`. Otherwise the action does not see the issue.
3. **Status table with HTML markers**, a Markdown table between `<!-- TRANSLATION-STATUS-START -->` and `<!-- TRANSLATION-STATUS-END -->`. Copy from the template verbatim, otherwise no status parsing.

### URL form (canonical)

```
https://, lowercase, with a trailing slash, no query (?…), no fragment (#…), no www.
```

| Correct | Incorrect |
|---|---|
| `https://learn.wordpress.org/lesson/wordpress-essentials-domains-and-hosting/` | `http://learn.wordpress.org/lesson/WordPress-Essentials` |
| `https://make.wordpress.org/training/handbook/about/team-values/` | `https://learn.wordpress.org/lesson/wordpress-essentials?ref=email` |

### Status values

`open` · `wip` · `review` · `done` · `na`

Other values are ignored by the parser.

### Creator / reviewer

GitHub username, with or without the `@` prefix. Both `rfluethi` and `@rfluethi` work, the parser strips the leading `@` automatically. Separate multiple people with a comma: `rfluethi, Ursha-wp` or `@rfluethi, @Ursha-wp`. Leave empty if nobody is assigned yet.

### One issue per content item and language

Per original URL and language only **one** issue should exist. If two end up created by accident, the tracker marks both with a warning icon "multiple issues", cleanup happens manually by the team.

### Fields that *no longer* belong in the issue

These fields are now pulled automatically from the inventory or the Project V2 board:

- **Original title** (`Original title:`), pulled from learn.wordpress.org / the handbook.
- **Sort order** (`Order:`), derived from `scope.yml`.
- **Pathway / Course / Section**, mapped automatically.

For old issues being migrated: the fields may stay, the parser silently ignores them.

### Component set per item type

The status table only needs the rows that are relevant for the type. Defined in `action/component-templates.yml`:

| Item type | Components (order in the tracker) |
|---|---|
| `lesson` | thumbnails, text, subtitles, exercise, quiz, audio, video |
| `lesson_plan` | thumbnails, text |
| `tutorial` | thumbnails, text, subtitles, video |
| `handbook_text` | text |
| `handbook_video` | thumbnails, text, subtitles, video |

Mark non-applicable components as `na` or leave the row out.

## Template 1, Lessons / Lesson Plans / Tutorials

This template extends the official `WordPress/Learn` translation template. The official block (`# Details`) is copied verbatim, the DACH-specific fields follow below it.

### Template to copy

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

### What is official vs. DACH extension

Of the official `# Details` block the tracker uses only **one** field: `Link to original content` (mandatory, matches against `scope.yml`). The rest is human-to-human information.

The `# Translation Details` block is the DACH extension. All fields are optional except the format itself:

| Field | Effect in the tracker |
|---|---|
| `German title:` | Translation column of the card. Empty means the English title is shown in gray italics as a placeholder. |
| `Link to translated content:` | Link under the German title. |
| `Link to original WordPress.tv recording:` | EN recording as a link under the English title. |
| `Link to translated WordPress.tv recording:` | DE recording as a link under the German title. |
| `Link to original YouTube recording:` | Same. |
| `Link to translated YouTube recording:` | Same. |

### Tolerant field recognition

The parser accepts several spelling variants:

- `German title` ↔ `German lesson name` ↔ `Deutscher Titel` ↔ `Translation title` ↔ `Translated title`
- `Link to WordPress.tv recording:` (without *original/translated*) is interpreted as the **German** recording (backwards compatibility).
- Format is irrelevant: both `- Field: value` (official) **and** `**Field:** value` (DACH bold style) are recognized.

## Template 2, Handbook (`handbook_text`, plain text page)

For handbook content under `https://make.wordpress.org/training/handbook/...` that **does not** have a video recording.

### Template to copy

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

This is the minimum. `Link to translated content` and `German title` may be left empty, they get filled in once a German version exists.

## Template 3, Handbook (`handbook_video`, with video recording)

For handbook pages with an embedded video.

### Template to copy

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

## What is different for Handbook compared to Lessons

**No pathway / course mapping.** Handbook items are shown automatically in a separate top-level group "Training Handbook", subdivided by their top-level section slug (`about`, `getting-started`, etc.). The action figures out this hierarchy itself via the `parent` field of the handbook REST API.

**No `quiz`/`exercise`/`audio` components.** If you do need one of those component types for a handbook page, just add it to the table, the action accepts arbitrary component names (the frontend only renders known icons, unknown ones are ignored).

**More compact format.** Instead of `# Details` / `# Translation Details` blocks, the `**Field:** value` syntax is enough. The parser recognizes both styles.

## Full example, maintained Lesson issue

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

## Full example, maintained Handbook issue

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

## Related documents

- Architecture and decisions: [Architecture.md](Architecture.md)
- Plugin usage and issue workflow: [User-Guide.md](User-Guide.md)
- Component templates (source of truth): `action/component-templates.yml`
- Scope configuration: `action/scope.yml`
