# Training Translation Tracker Inventory

Mono-Repo für das inventar-getriebene Übersetzungs-Dashboard des
WordPress-DACH-Teams. Zwei Komponenten, ein Repo:

1. **`action/`** — GitHub Action (Python), die ein `tracker.json`-Snapshot
   aller DACH-Übersetzungen erzeugt. Läuft alle 12 Stunden und beim Push
   auf relevante Action-Pfade.
2. **`wp-plugin/`** — WordPress-Plugin, das `tracker.json` lädt und auf
   einer WP-Seite als Dashboard rendert (Karten, Filter, Suche, Collapse).

---

## Dokumentation

Vier Dokumente, je nach Zielgruppe:

| Wenn du… | Dann lies… |
|---|---|
| das System verstehen willst — wie es arbeitet und warum es so gebaut ist | [docs/Architektur.md](docs/Architektur.md) |
| am Code arbeitest (Action-Python oder Plugin-PHP/JS/CSS) | [docs/Developer.md](docs/Developer.md) |
| das Tool betreibst (Releases, Token-Pflege, Failure-Recovery) | [docs/Operations.md](docs/Operations.md) |
| das Plugin auf einer WP-Site einsetzt oder Issues pflegst | [docs/User-Guide.md](docs/User-Guide.md) |
| ein Übersetzungs-Issue für DACH anlegen willst | [docs/Issue-Vorlagen-DACH.md](docs/Issue-Vorlagen-DACH.md) |

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
│   └── LICENSE
│
├── wp-plugin/                    WordPress-Plugin
│   ├── training-translation-tracker.php   Plugin-Header + Boot
│   ├── includes/                 Settings, Fetcher, Renderer
│   ├── assets/                   CSS + JS für das Frontend
│   ├── readme.txt                WordPress-Standard-Readme
│   └── LICENSE
│
├── docs/                         Doku-Suite (Architektur, Developer, Operations, User-Guide, Issue-Vorlagen)
├── build-plugin-zip.sh           Plugin-ZIP für WP-Upload bauen
├── sync-schemas.py               Sync zwischen ../Konzept/schemas und action/schemas (lokal)
├── CONTRIBUTING.md
└── README.md                     Dieses Dokument
```

Nicht im Repo (in `.gitignore`):

- `training-translation-tracker.zip` — wird bei jedem Build neu erzeugt.
- `.venv/`, `.pytest_cache/`, `.ruff_cache/`, `__pycache__/` — Python-Werkzeug-Caches.
- `action/tracker.json`, `action/last-run.md`, `action/data-hygiene.md` — lokale Action-Outputs (live auf dem `data`-Branch).

---

## Maintainer-Arbeitsordner

Beim Maintainer liegt dieses Repo in einem Wrapper-Ordner, daneben die lokale Arbeitsfläche:

```
Arbeitsordner/
├── GitHub/                       ← der Inhalt dieses Repos (oben gezeigt)
└── Konzept/                      ← LOKAL, nicht im Repo
    ├── Arbeitsplan.md            Roadmap, offene Punkte
    ├── Team-Uebersicht.md        1-A4-Demo
    ├── schemas/                  Source-of-Truth für JSON-Schemata
    └── _archiv/                  Historische Konzept-Dokumente
```

So sieht der Maintainer auf einen Blick, was ins Repo geht (`GitHub/`) und was lokal bleibt (`Konzept/`). Contributors, die das Repo standalone clonen, sehen nur den `GitHub/`-Inhalt und brauchen `Konzept/` nicht — `sync-schemas.py` erwartet aber `../Konzept/schemas/` und meldet einen klaren Fehler, wenn der Pfad fehlt.

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

Tieferer Einstieg: [docs/Architektur.md](docs/Architektur.md).

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

Im WordPress-Admin via "Plugin hochladen" installieren — Schritt-für-Schritt in
[docs/User-Guide.md](docs/User-Guide.md).

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
