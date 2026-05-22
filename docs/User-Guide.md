# User Guide, Training Translation Tracker

> **Audience:** WordPress site admins who embed the tracker on a page, plus translators who use the dashboard.
> **Prerequisites:** No PHP knowledge required. You need write access to the WordPress admin and (for issue maintenance) a GitHub account.

## 1. What does the tracker do?

The Translation Tracker is a dashboard for the translation progress of the WordPress learning content on `learn.wordpress.org` and in the Training Handbook. On a WordPress page it shows which content is already translated, which is currently being worked on, and which is still open. Per content item you see the status of each component (text, subtitles, quiz, video, etc.) and who is working on it.

The data does not come from the WordPress site itself but from a **GitHub Action** that refreshes a JSON file every 12 hours. The plugin reads this file once per cache cycle and renders the overview from it. This separation keeps the site fast and the plugin lean.

**Three parts of the overall system:**

| Component | Where? | What? |
|---|---|---|
| GitHub issues | `WordPress/Learn` repo, DACH project board | Translators maintain the status per item |
| GitHub Action | `Training-Translation-Tracker-Inventory-Plugin` | Builds `tracker.json` every 12 h |
| WordPress plugin | This site | Reads the JSON, renders the dashboard |

You mostly work with the GitHub issues (maintaining status) and see the result in the dashboard.

## 2. Installation

### Requirements

| | |
| --- | --- |
| WordPress | 6.0 or higher |
| PHP | 8.0 or higher |
| Internet access | The site must be able to reach `raw.githubusercontent.com` |

### Installation steps

1. **Download the ZIP**, get the current `training-translation-tracker.zip` from the [Releases tab of the GitHub repo](https://github.com/rfluethi/Training-Translation-Tracker-Inventory-Plugin/releases).
2. In the WP admin, click **Plugins → Add New**.
3. Click **Upload Plugin** at the top, then pick the ZIP file.
4. Click **Install Now**.
5. After a successful upload, click **Activate Plugin**.

If an older version is already installed, **first deactivate and delete the old version** (red "Delete" action in the plugin list). Only then upload the new ZIP. Settings and cache are preserved.

### Verification

After installation, the plugin list contains an entry "**Training Translation Tracker**" with a version number. In the **Settings** menu there is a new submenu "**Translation Tracker**".

## 3. Plugin settings

Available at **WP Admin → Settings → Translation Tracker**.

### URL of `tracker.json`

The address from which the plugin loads the JSON file. By default it points at the `data` branch of the inventory plugin repo:

```text
https://raw.githubusercontent.com/rfluethi/Training-Translation-Tracker-Inventory-Plugin/data/tracker.json
```

Only change this URL if the action repo moves or if a different data source should be used. In 99 % of cases it stays at the default.

### Cache duration (hours)

How long the loaded JSON sits in the WordPress transient cache before it is reloaded. Default is 12 hours, matching the 12 h cadence of the action.

Set it shorter (e.g. 1 hour) during test phases or when fast updates are needed. Set it longer (e.g. 24 hours) when the data source is stable and HTTP traffic should be minimized.

Allowed range: 1 to 168 hours (up to one week).

### Button "Clear cache now"

Forces a fresh fetch on the next page load. Useful when an update has just been pushed on GitHub and you want to see it immediately without waiting for the cache to expire.

### "Generated at:" display

Shows the `generated_at` timestamp from the current cache. From this you see when the action last built the currently cached state. If the date is older than expected, the cache is stale, either press "Clear cache" or trigger the action manually (see section 6).

### Shortcode examples

Directly below the settings fields the plugin lists shortcode examples with "Copy" buttons. This lets you grab common variants without retyping them from this document.

## 4. Embedding the tracker on a page

In the WordPress editor (Gutenberg, Classic or Page Builder):

1. Open or create a **page** where the dashboard should appear. Usual title: "Translation Tracker".
2. Insert a **Shortcode block** (in Gutenberg: "/" → "Shortcode").
3. Write into the block content:

   ```text
   [translation_tracker]
   ```

4. **Save and publish** the page.
5. **Open the page** (preview or live URL). The dashboard appears where the shortcode sits.

Page builders (Elementor, Divi, etc.) also have a shortcode block or a text widget that runs shortcodes. Insert `[translation_tracker]` there.

### Shortcode attributes

Attributes control what the dashboard shows. Several can be combined:

| Attribute | Values | Effect |
|---|---|---|
| `pathway` | Slug of a pathway, multiple separated by comma | Shows only the named learning paths |
| `show_pathways` | `yes`/`no` | Show or hide all pathway groups (default `yes`) |
| `show_orphans` | `yes`/`no` | Show or hide "Other (outside scope)" |
| `show_handbook` | `yes`/`no` | Show or hide the Training Handbook group |
| `show_stats` | `yes`/`no` | Show or hide the stats header at the top |

### Smart defaults

When `pathway` is set, the plugin **automatically** hides orphan and handbook groups (assumed intent: "I only want this pathway overview"). If you still want them, explicitly add `show_orphans="yes"`.

When `pathway` is **not** set, orphan and handbook groups are shown by default.

### Pathway slug matching

The `pathway` attribute accepts several spellings for the same pathway:

- Short slug: `pathway="user"`
- Full label slug: `pathway="beginner-wordpress-user"`
- Original label: `pathway="Beginner WordPress User"`

All three match the same pathway group. Case is ignored.

### Examples

**Complete overview:**

```text
[translation_tracker]
```

**One page per pathway:**

```text
[translation_tracker pathway="user"]
[translation_tracker pathway="lesson-plans"]
```

**Multiple pathways:**

```text
[translation_tracker pathway="user, contributor"]
```

**Hide stats (you have your own header):**

```text
[translation_tracker show_stats="no"]
```

**Only the Training Handbook:**

```text
[translation_tracker show_pathways="no" show_orphans="no"]
```

**A pathway plus handbook, no orphans:**

```text
[translation_tracker pathway="user" show_handbook="yes" show_orphans="no"]
```

The explicit `show_handbook="yes"` overrides the smart default.

## 5. Using the tracker in the frontend

### Stats pills at the top

Show the totals per status:

- **Items**, all items combined
- **done** (green), `overall_status = done`
- **review** (yellow), `overall_status = review`
- **in progress** (blue), `overall_status = wip`
- **open** (gray), `overall_status = open`
- **n/a** (light gray), `overall_status = na`

Clicking a pill filters the cards to that status. Clicking "Items" resets the filter.

### The cards

Each card shows one content item:

**Status bar on the left** in the color of `overall_status`.

**Original column** (left): title and link to the English content. If there is a WordPress.tv recording or a YouTube video, they appear as small links below the title.

**Translation column** (right): same for the German translation. If the English title is shown there in gray italics, the German translation does not exist yet.

**Footer row:** left side shows the issue number (e.g. `#2952`) linked to the GitHub issue, next to it the issue status `open`/`closed` and possibly markers ("Orphan", "Duplicate", "Original draft", "Out of scope"). Right side shows up to seven small colored icons for the components (thumbnails, text, subtitles, exercise, quiz, audio, video). Hovering over an icon opens a popover with status, creator + avatar and reviewer + avatar.

### Search field

Live search in the header. Typing filters the cards whose title (German or English) or issue number contains the entered text.

### Collapsing / expanding sections

Click the title of a section (e.g. "Get Started With WordPress") to collapse the cards below it. The arrow turns from ▾ to ▸. Click again to expand. The state is stored in the browser, so on the next page load it stays as last left.

## 6. Refreshing the data

Three ways:

### 1. Automatically every 12 hours

The GitHub Action runs on a cron schedule and publishes a new `tracker.json`. The plugin reloads it at the latest when the cache duration has expired (default 12 h).

### 2. "Clear cache now" in the plugin

In the plugin settings, press the button. On the next page load the plugin fetches fresh data, provided the action has rebuilt in the meantime.

### 3. Trigger the action manually

Whoever has write access to the action repo can trigger the workflow "Build tracker.json" manually via the GitHub web UI. After that:

1. Wait about 2 minutes until the action has finished.
2. Press "Clear cache now" in the plugin.
3. Reload the page.

## 7. Creating issues for new translations

The full template is in [Issue-Templates-DACH.md](Issue-Templates-DACH.md). The key points here:

### Where to create them

In the **`WordPress/Learn`** repo, not in the inventory plugin repo. The issue must be on the DACH project board with the custom field `Locale = German`.

### Three mandatory points

1. **Canonical original URL**, `https://`, lowercase, trailing slash, no query/fragment, no `www.`.

   Correct: `https://learn.wordpress.org/lesson/wordpress-essentials-domains-and-hosting/`

   Incorrect: `http://learn.wordpress.org/lesson/WordPress-Essentials` or `…?ref=email`

2. **Locale marking** on the DACH project board (`Locale = German`).

3. **Status table with HTML markers**, a Markdown table between `<!-- TRANSLATION-STATUS-START -->` and `<!-- TRANSLATION-STATUS-END -->`. Copy the template verbatim.

### Status values

`open` · `wip` · `review` · `done` · `na`

### Creator / reviewer

GitHub username, with or without the `@` prefix. Both `rfluethi` and `@rfluethi` work, the parser strips the leading `@` automatically. Separate multiple names with a comma. Leave empty if nobody is assigned yet.

### One issue per content item and language

If two issues are created for the same URL by mistake, the tracker shows both with a warning icon "multiple issues". Cleanup is manual, close one or repurpose it.

## 8. Common problems

### The dashboard is empty / shows "Tracker data is being prepared"

Causes: the JSON file has never been loaded successfully, which happens the very first time, before the first action run. Or the JSON URL in the settings is wrong or unreachable. Or the site has no internet access to `raw.githubusercontent.com`.

**Fix:** check the URL in the plugin settings and press "Clear cache". If still empty, ask a site admin to check the action repo.

### "Generated at:" shows an older date

The cache has not expired yet. Either wait or press "Clear cache".

### A translation is missing from the dashboard

Check the issue number on the DACH GitHub project, does the issue exist at all? The issue must have the label `Locale=German`. The original URL in the issue must match the inventory URL exactly (lowercase, with trailing slash, without query parameters). If all of that is correct, the next action run picks it up and the item appears on the next cache update.

### A card shows "Orphan" or "Out of scope"

**Orphan:** the issue points to a URL that no longer exists in the inventory (learn.wordpress.org) (renamed, deleted).

**Out of scope:** the issue is valid, but the URL is not in the action's `scope.yml`, a deliberate choice of which content lands on the dashboard.

In both cases action is needed either on the issue (fix the URL) or on the scope (add the item to `scope.yml`, see developer docs).

### Status filter and search do not respond

In certain theme / page builder combinations the interaction JavaScript does not load reliably. **Workaround:** instead of clicking filters, use a shortcode with a `pathway=` attribute on its own page, that filters server-side and always works.

Diagnosis: open the browser console (F12 → Console + Network) and check whether `tracker.js` loaded with HTTP 200. If not, the page builder stripped the `<script src>` tag out of the output.

## 9. Reporting a bug

Before you report a bug, please note down:

1. **Plugin version** (shown in the plugin list and in the settings).
2. **WordPress version** and, if relevant, **page builder / theme**.
3. **Which shortcode** the page uses.
4. **What you expected** and **what actually happened**.
5. **Screenshot** of the page, preferably full browser window.
6. **Browser console** (F12 → Console tab), copy all red errors.

Open an issue in the action repo: <https://github.com/rfluethi/Training-Translation-Tracker-Inventory-Plugin/issues>

Or contact the maintainer directly by email (see the plugin header).

## Further reading

- System architecture: [Architecture.md](Architecture.md)
- Operations (releases, token, recovery): [Operations.md](Operations.md)
- Code & extensions: [Developer.md](Developer.md)
