# Training Translation Tracker Inventory

Mono-Repo für das inventar-getriebene Übersetzungs-Dashboard des
WordPress-DACH-Teams. Zwei Komponenten, ein Repo:

1. **`action/`** — GitHub Action (Python), die ein `tracker.json`-Snapshot
   aller DACH-Übersetzungen erzeugt. Läuft alle 12 Stunden und beim Push
   auf relevante Action-Pfade.
2. **`wp-plugin/`** — WordPress-Plugin, das `tracker.json` lädt und auf
   einer WP-Seite als Dashboard rendert (Karten, Filter, Suche, Collapse).

---

## Repo-Layout

```text
Training-Translation-Tracker-Inventory-Plugin/
├── .github/workflows/build.yml   Workflow auf Top-Level (GitHub-Convention)
├── action/                       Python-Action — baut tracker.json auf data-Branch
│   ├── src/                      Inventar-Sources, Issue-Parser, Joiner, Build-Entry-Point
│   ├── tests/                    pytest-Tests
│   ├── schemas/                  JSON-Schemata (Laufzeit-Kopie)
│   ├── scope.yml                 DACH-Scope: welche URLs werden getrackt
│   ├── component-templates.yml   Default-Komponenten pro Item-Typ
│   ├── inventory-cache.json      Committed Inventory-Snapshot
│   ├── requirements.txt
│   ├── README.md                 Action-spezifisches README
│   └── LICENSE
│
├── wp-plugin/                    WordPress-Plugin
│   ├── training-translation-tracker.php   Plugin-Header + Boot
│   ├── includes/                 Settings, Fetcher, Renderer
│   ├── assets/                   CSS + JS für das Frontend
│   ├── docs/                     Benutzerhandbuch + Entwickler-Doku
│   ├── readme.txt                WordPress-Standard-Readme
│   └── LICENSE
│
├── build-plugin-zip.sh           Plugin-ZIP für WP-Upload bauen
├── sync-schemas.py               Sync zwischen Konzept/schemas und action/schemas (lokal)
└── README.md                     Dieses Dokument
```

Nicht im Repo (lokal-only):

- `training-translation-tracker.zip` — wird bei jedem Build neu erzeugt.
- `.venv/`, `.pytest_cache/`, `.ruff_cache/` — Python-Werkzeug-Caches.

---

## Drei-Komponenten-Pipeline

```
┌──────────────────────────┐    ┌──────────────────────────┐    ┌──────────────────────────┐
│  GitHub Issues (DACH)    │    │  GitHub Action (Python)  │    │  WordPress-Plugin (PHP)  │
│  Project V2 #104         │───►│  build tracker.json auf  │───►│  liest tracker.json,     │
│  Locale=German           │    │  data-Branch alle 12h    │    │  rendert Shortcode       │
└──────────────────────────┘    └──────────────────────────┘    └──────────────────────────┘
       Pflege durch                  Aggregation +                    Anzeige im
       Übersetzer                    Schema-Validierung               Frontend
```

Das Plugin macht **keine** eigenen API-Calls gegen GitHub oder learn.wordpress.org.
Alles ist auf der Action vorgerechnet, das Plugin ist ein dünner Renderer mit Cache.

---

## Schnellstart

### Action lokal testen

```bash
cd action
python -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
python -m src.build --skip-issues  # baut tracker.json ohne GitHub-Token
```

### Plugin-ZIP bauen

```bash
./build-plugin-zip.sh
# → ~/Desktop/training-translation-tracker.zip
```

Im WordPress-Admin via "Plugin hochladen" installieren.

### Inventory-Cache nachziehen (wenn neue scope.yml-URLs)

```bash
cd action
python -m src.build --refresh-cache    # holt nur die noch fehlenden URLs
git add scope.yml inventory-cache.json
git commit -m "Scope: neue URLs"
git push
```

Die Action triggert dann automatisch und baut tracker.json neu.

---

## Lizenz

GPL v2 oder später — siehe `action/LICENSE` und `wp-plugin/LICENSE`.
