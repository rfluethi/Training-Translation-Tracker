# Training Translation Tracker

> WordPress plugin that reads the static `tracker.json` published by the
> sister GitHub Action and renders it as a translation dashboard on a
> WordPress page.

This plugin is the slim renderer half of the mono-repo. Instead of making
GraphQL or REST calls against WordPress/Learn itself, it loads a single
precomputed JSON file. That file is built by the GitHub Action in this
same repo every 12 hours and published on a separate `data` branch.

For the full picture (mono-repo, three-component pipeline, design
decisions) see the [top-level README](../README.md) and
[docs/Architecture.md](../docs/Architecture.md).

## What it does

The shortcode `[translation_tracker]` renders the dashboard on any page.

Settings under **Settings → Translation Tracker**: tracker.json URL
(defaults to the inventory plugin repo, `data` branch), cache duration
(default 12 hours via a WordPress transient), a "Clear cache now" button,
and a display of the `generated_at` timestamp from the current cache.

A `schema_version` check rejects payloads with an unknown major version.
On fetch errors the plugin keeps showing the last successful state from a
separate `last_good` transient, so the dashboard never goes blank.

The frontend supports filters, search, sectional collapse, and a
component popover (creator and reviewer with GitHub avatars). All
user-facing strings are i18n-enabled (source language English, German
translation shipped under `languages/`).

## Requirements

| | |
| --- | --- |
| WordPress | 6.0 or higher |
| PHP | 8.0 or higher |
| Data source | A reachable `tracker.json` conforming to `tracker.schema.json` v1 |

## Installation (local testing)

Either symlink the plugin folder into your local WP install:

```bash
cd /path/to/wp-content/plugins
ln -s /path/to/Training-Translation-Tracker-Inventory-Plugin/wp-plugin training-translation-tracker
```

Or build a release ZIP from the repo root and upload it via the WP admin:

```bash
./build-plugin-zip.sh
# Then upload ~/Desktop/training-translation-tracker.zip via Plugins → Add New → Upload Plugin
```

In WP admin: **Plugins → Training Translation Tracker** → activate, then
**Settings → Translation Tracker**, adjust the URL if needed, press
"Clear cache now". Insert the shortcode on any page:

```text
[translation_tracker]
```

Full usage including shortcode attributes lives in
[docs/User-Guide.md](../docs/User-Guide.md).

## Repository layout

```text
.
├── training-translation-tracker.php   Main file (header, constants, boot)
├── uninstall.php                       Cleanup on plugin deletion
├── includes/
│   ├── class-settings.php              Settings page + clear-cache AJAX
│   ├── class-fetcher.php               wp_remote_get + transient cache
│   └── class-renderer.php              Shortcode + HTML output
├── assets/
│   ├── tracker.js                      Frontend interaction (filter, search, collapse)
│   └── admin.js                        Clear-cache AJAX in the settings page
├── languages/                          .pot / de_DE.po / de_DE.mo
├── readme.txt                          WordPress standard readme
├── README.md                           This document
└── LICENSE
```

## License

GPL v2 or later, see `LICENSE`.
