# Contributing

Glad you want to contribute. This repo contains two components, a GitHub
Action written in Python and a WordPress plugin in PHP. Depending on what
you want to change, the workflow is slightly different.

## Table of contents

1. [What you can contribute](#what-you-can-contribute)
2. [Repository setup](#repository-setup)
3. [Action development (Python)](#action-development-python)
4. [Plugin development (PHP)](#plugin-development-php)
5. [Documentation](#documentation)
6. [Pull request process](#pull-request-process)
7. [Adapting for other locales](#adapting-for-other-locales)
8. [Acknowledgments](#acknowledgments)
9. [Code of conduct](#code-of-conduct)

## What you can contribute

| Type | How | Examples |
|---|---|---|
| Bug report | Open an issue with the bug template | Plugin shows wrong layout, action throws an error |
| Feature request | Open an issue with the feature template | "Filter by issue assignee", new shortcode parameter |
| Code change | Pull request | Bug fix, small improvement, new inventory source |
| Translation | Pull request with a `.po` file | `de_DE`, `de_CH`, `en_US` for UI strings |
| Documentation | Pull request with README or docs changes | Typo fixes, clearer explanations, examples |

## Repository setup

```bash
git clone https://github.com/rfluethi/Training-Translation-Tracker.git
cd Training-Translation-Tracker
```

The repository contains:

- `action/`, Python code for the GitHub Action
- `wp-plugin/`, WordPress plugin
- `.github/workflows/`, build and release workflows
- `build-plugin-zip.sh`, builds the plugin ZIP locally

Documentation suite (covers both components):

- [docs/Architecture.md](docs/Architecture.md), system architecture and design decisions
- [docs/Developer.md](docs/Developer.md), code setup, tests, extensions
- [docs/Operations.md](docs/Operations.md), releases, token maintenance, failure recovery
- [docs/User-Guide.md](docs/User-Guide.md), plugin usage and issue maintenance
- [action/README.md](action/README.md), short action-specific notes

## Action development (Python)

### Setup

```bash
cd action
python -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

### Run tests

```bash
cd action
python -m pytest tests/ -v
```

Tests should **always be green** before every commit.

### Test the action locally without a GitHub token

```bash
cd action
python -m src.build --skip-issues   # builds tracker.json from inventory-cache.json, no issues
```

This produces `tracker.json`, `last-run.md`, and `data-hygiene.md` locally. All three files are in `.gitignore` and are never committed.

### Refresh the inventory cache

When you add new URLs to `scope.yml`, you must refresh the inventory cache locally (the action itself does not, since GitHub runner IPs are rate-limited too aggressively):

```bash
cd action
python -m src.build --refresh-cache   # only fetches missing URLs
git add scope.yml inventory-cache.json
git commit -m "Scope: new URLs"
```

### Code style

- Python 3.10+
- Ruff for linting (`ruff check src tests`)
- Use type hints wherever possible
- Place tests next to the code (`tests/test_<module>.py`)

## Plugin development (PHP)

### Setup

```bash
# Symlink into the WordPress plugin directory (for a local WP installation)
ln -s "$(pwd)/wp-plugin" /path/to/wp-content/plugins/training-translation-tracker
```

Or build a ZIP for every test:

```bash
./build-plugin-zip.sh
# → ~/Desktop/training-translation-tracker.zip
```

### Code style

- WordPress Coding Standards (see the [WordPress.org Handbook](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/))
- All user-visible strings via `__()`, `_e()`, or `esc_html__()` for i18n
- Inline comments in English, consistent across files
- Vendor code (e.g. JS libraries) always with a clear note on origin

### Versioning

For every change that justifies a release, keep three places in sync:

1. `wp-plugin/training-translation-tracker.php`, `Version:` in the header
2. `wp-plugin/training-translation-tracker.php`, `TTT_VERSION` constant
3. `wp-plugin/readme.txt`, `Stable tag:`

Plus an entry in the changelog (`wp-plugin/readme.txt`).

### Release

```bash
git tag v0.4.2        # beta scheme: 0.x.y
git push --tags
```

The release workflow automatically builds the ZIP and publishes a GitHub
release.

## Documentation

The documentation lives at the top level under `docs/`:

- `Architecture.md`, system architecture, data model, decisions
- `Developer.md`, code setup, modules, tests, extension points
- `Operations.md`, releases, token, failure recovery
- `User-Guide.md`, plugin settings, shortcodes, frontend usage, issue maintenance
- `Issue-Templates-DACH.md`, templates for creating translation issues (lesson, handbook text, handbook video)

Documentation contributions are welcome: typo fixes, clearer explanations, examples.

## Pull request process

1. **Fork** the repo on GitHub.
2. **Branch** off `main` with a descriptive name:
   - `fix/popover-positioning`
   - `feat/csv-export`
   - `docs/contributing-guide`
3. **Commits** with clear, short, imperative messages:
   - "Fix: popover gets clipped at the right viewport edge"
   - "Add: CSV export for tracker items"
4. **Run tests** (action: `pytest`, plugin: manual smoke test).
5. **Open a pull request** against `main` with a description covering:
   - What changes?
   - Why?
   - How was it tested?
6. Iterate on review feedback. No force pushes on the PR branch once review has started.

## Adapting for other locales

Other language teams can use this tracker for their own locale:

1. **Fork** the repository.
2. `action/scope.yml`: set `locale: French` (or whichever Project V2 locale applies).
3. `action/scope.yml`: enter your pathway and URL list.
4. Populate `inventory-cache.json` locally with `--refresh-cache`.
5. Set the GitHub secret `GH_PAT_PROJECT_READ` to your own PAT (Project V2 read scope).
6. Update the plugin header in `wp-plugin/training-translation-tracker.php`
   (your plugin name, author, project URI).
7. Translate text in `docs/` and all locale-specific strings.
8. Build the plugin ZIP and install it on your own site.

Pull requests that contribute generic improvements back upstream (e.g. new
inventory sources, new shortcode options) are welcome. Please keep
locale-specific changes in your fork.

## Acknowledgments

**Frontend UI design concept:** Andy Rudorfer
([@Bigod](https://github.com/Bigod)). Card layout, status pills,
component icons and filter bar interaction follow Andy's design. The
implementation in PHP, CSS and JavaScript turns that concept into a
WordPress plugin.

## Code of conduct

We follow the [WordPress Community Code of Conduct](https://make.wordpress.org/community/handbook/community-code-of-conduct/). Short version: respectful, helpful, constructive. Anyone who insults, excludes, or spreads hate speech will be excluded.
