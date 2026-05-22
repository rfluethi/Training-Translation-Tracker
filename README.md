# Training Translation Tracker Inventory

Mono-repo for the inventory-driven translation dashboard of the
WordPress DACH team. Two components, one repo:

1. **`action/`**, a GitHub Action (Python) that produces a `tracker.json`
   snapshot of all DACH translations. Runs every 12 hours and on pushes
   to relevant action paths.
2. **`wp-plugin/`**, a WordPress plugin that loads `tracker.json` and
   renders it on a WP page as a dashboard (cards, filters, search, collapse).

## Documentation

Five documents, depending on your role:

| If you want to… | Then read… |
|---|---|
| understand the system, how it works and why it is built this way | [docs/Architecture.md](docs/Architecture.md) |
| work on the code (Action-Python or Plugin-PHP/JS/CSS) | [docs/Developer.md](docs/Developer.md) |
| operate the tool (releases, token maintenance, failure recovery) | [docs/Operations.md](docs/Operations.md) |
| install the plugin on a WP site or maintain issues | [docs/User-Guide.md](docs/User-Guide.md) |
| create a DACH translation issue | [docs/Issue-Templates-DACH.md](docs/Issue-Templates-DACH.md) |

## Repository layout

```text
Training-Translation-Tracker-Inventory-Plugin/
├── .github/workflows/build.yml   Workflow at top level (GitHub convention)
├── action/                       Python action, builds tracker.json on the data branch
│   ├── src/                      Inventory sources, issue parser, joiner, build entry point
│   ├── tests/                    pytest tests
│   ├── schemas/                  JSON schemas (runtime copy)
│   ├── scope.yml                 DACH scope: which URLs are tracked
│   ├── component-templates.yml   Default components per item type
│   ├── inventory-cache.json      Committed inventory snapshot
│   ├── requirements.txt
│   └── LICENSE
│
├── wp-plugin/                    WordPress plugin
│   ├── training-translation-tracker.php   Plugin header and boot
│   ├── includes/                 Settings, fetcher, renderer
│   ├── assets/                   JS for the frontend
│   ├── readme.txt                WordPress standard readme
│   └── LICENSE
│
├── docs/                         Documentation suite (Architecture, Developer, Operations, User Guide, Issue Templates)
├── build-plugin-zip.sh           Build the plugin ZIP for WP upload
├── sync-schemas.py               Schema sync tool for maintenance
├── CONTRIBUTING.md
└── README.md                     This document
```

Not in the repo (in `.gitignore`):

- `training-translation-tracker.zip`, regenerated on every build.
- `.venv/`, `.pytest_cache/`, `.ruff_cache/`, `__pycache__/`, Python tooling caches.
- `action/tracker.json`, `action/last-run.md`, `action/data-hygiene.md`, local action outputs (live on the `data` branch).

## Three-component pipeline

```
┌──────────────────────────┐    ┌──────────────────────────┐    ┌──────────────────────────┐
│  GitHub Issues (DACH)    │    │  GitHub Action (Python)  │    │  WordPress plugin (PHP)  │
│  Project V2 #104         │───►│  builds tracker.json on  │───►│  reads tracker.json,     │
│  Locale=German           │    │  data branch every 12 h  │    │  renders the shortcode   │
└──────────────────────────┘    └──────────────────────────┘    └──────────────────────────┘
       maintained by                  aggregation and                  rendered in
       translators                    schema validation                the frontend
```

The plugin makes **no** API calls to GitHub or learn.wordpress.org itself.
Everything is precomputed by the action; the plugin is a thin renderer with a cache.

For a deeper introduction, see [docs/Architecture.md](docs/Architecture.md).

## Quickstart

### Test the action locally

```bash
cd action
python -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
python -m src.build --skip-issues  # builds tracker.json without a GitHub token
```

### Build the plugin ZIP

```bash
./build-plugin-zip.sh
# → ~/Desktop/training-translation-tracker.zip
```

Install in WordPress admin via "Upload Plugin", step-by-step in
[docs/User-Guide.md](docs/User-Guide.md).

### Refresh the inventory cache (when scope.yml gets new URLs)

```bash
cd action
python -m src.build --refresh-cache    # only fetches missing URLs
git add scope.yml inventory-cache.json
git commit -m "Scope: new URLs"
git push
```

The action then triggers automatically and rebuilds tracker.json.

## Credits

The frontend UI design concept (card layout, status pills, component
icons, filter bar interaction) is by **Andy Rudorfer**
([@Bigod](https://github.com/Bigod)). The implementation in PHP, CSS
and JavaScript was carried out on top of that concept.

## License

GPL v2 or later, see `action/LICENSE` and `wp-plugin/LICENSE`.
