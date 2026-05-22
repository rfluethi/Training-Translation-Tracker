# Action, Translation Tracker data pipeline

> Python-based GitHub Action that builds `tracker.json` for the DACH tracker.
> Part of the mono-repo. The WordPress plugin lives under `../wp-plugin/`.
> For an overview, see the [top-level README](../README.md).

## What happens here

A GitHub Action runs every 12 hours and:

1. reads `scope.yml` (which URLs should appear in the tracker)
2. fetches inventory data for every URL from `learn.wordpress.org` or `make.wordpress.org/training/handbook/`
3. fetches all DACH translation issues from `WordPress/Learn` (Project V2 #104, Locale = German)
4. matches inventory and issues via the normalized original URL
5. parses the status tables from the issue bodies
6. writes `tracker.json` (and `last-run.md` as a log) onto the `data` branch

The resulting `tracker.json` is then statically available at `https://raw.githubusercontent.com/<owner>/Training-Translation-Tracker-Inventory-Plugin/data/tracker.json` and is read by the WordPress plugin.

## Architecture overview

See the [top-level README](../README.md) for the three-component pipeline
(issues → action → plugin) and the full repository layout.

The format and maintenance of DACH translation issues are described in the
user guide: [docs/User-Guide.md → Creating issues for new translations](../docs/User-Guide.md#7-creating-issues-for-new-translations).

Architectural background (components, data flow, design decisions):
[docs/Architecture.md](../docs/Architecture.md).

## Repository structure

```text
action/
├── scope.yml                      Locale + hierarchy + URLs (single source of truth)
├── component-templates.yml        Default components per item type
├── inventory-cache.json           Precomputed inventory data (refreshed locally)
├── schemas/                       JSON schemas for tracker.json, scope.yml, component-templates.yml
├── src/
│   ├── inventory/                 REST modules per item type + URL normalizer
│   ├── github/                    GraphQL client + issue parser
│   ├── builder/                   Joiner, stats, output writer
│   └── build.py                   Entry point for the action
├── tests/                         Unit tests (with mocked API)
├── requirements.txt
├── LICENSE                        GPL-2.0-or-later
└── README.md                      This document
```

The workflow `../.github/workflows/build.yml` lives at the repo top level
(GitHub convention) and uses `working-directory: action` for its commands.

Output lands on a separate branch:

```text
data branch
├── tracker.json                   Read by the WP plugin
└── last-run.md                    Human-readable report per run
```

## Initial setup by the maintainer

1. Create a public repository on GitHub: `<owner>/Training-Translation-Tracker-Inventory-Plugin`.
2. Default branch `main` (created automatically on first push).
3. Create a second branch `data`. Empty is enough, the workflow overwrites it:

   ```bash
   git checkout --orphan data
   git rm -rf .
   echo "# Translation Tracker Output" > README.md
   git add README.md
   git commit -m "Initial data branch"
   git push origin data
   git checkout main
   ```

4. Set the secret `GH_PAT_PROJECT_READ`:
   - Create a token at <https://github.com/settings/tokens>.
   - Scopes: `read:org`, `project`.
   - Store it in the repo under Settings → Secrets and variables → Actions as a repository secret.
5. Trigger the workflow manually via Actions → "Build tracker.json" → Run workflow.

## Inventory cache

The action no longer calls `learn.wordpress.org` live. The GitHub
runner IPs are rate-limited aggressively by the WP CDN, in practice
hardly any request gets through. Instead, the inventory lives as a
precomputed file `inventory-cache.json` in the repo. The action reads
that file and uses it for pathway grouping.

Whenever `scope.yml` changes or content on learn.wordpress.org is
restructured, the maintainer refreshes the cache **locally** (home or
office IPs are not rate-limited) and commits the new file:

```bash
python -m src.build --refresh-cache
git diff inventory-cache.json     # review the changes
git add inventory-cache.json
git commit -m "Refresh inventory cache"
git push
```

`--refresh-cache` fetches every URL from `scope.yml` (with a 1.5 s throttle
by default) and writes the InventoryItems into `inventory-cache.json`. It
does **not** fetch issues and does **not** write `tracker.json`.

URLs that cannot be reached during the refresh simply stay out of the cache.
Retry on the next local run. Issues for those URLs end up (temporarily) in
the orphan bucket.

## Local development

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt

# Run tests:
pytest

# Local full build (needs a token, reads from cache):
export GH_PAT_PROJECT_READ=<your token>
python -m src.build

# Build / refresh the cache:
python -m src.build --refresh-cache
```

## License

GPL-2.0-or-later, see `LICENSE`.
