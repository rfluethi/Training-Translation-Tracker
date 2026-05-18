# Training Translation Tracker — Inventory Plugin

> Inventar-getriebener Translation Tracker für das WordPress DACH-Team.
> Diese Repository enthält die GitHub Action, die `tracker.json` baut.
> Das schlanke WP-Plugin folgt in einer späteren Phase.

## Was hier passiert

Eine GitHub Action läuft alle 12 Stunden und:

1. liest `scope.yml` (welche URLs sollen im Tracker erscheinen)
2. holt für jede URL die Inventar-Daten von `learn.wordpress.org` bzw. `make.wordpress.org/training/handbook/`
3. holt alle DACH-Übersetzungs-Issues aus `WordPress/Learn` (Project V2 #104, Locale = German)
4. matched Inventar ↔ Issues über die normalisierte Original-URL
5. parst die Status-Tabellen aus den Issue-Bodies
6. schreibt `tracker.json` (und `last-run.md` als Logfile) auf den `data`-Branch

Das resultierende `tracker.json` ist anschließend statisch unter `https://raw.githubusercontent.com/<owner>/Training-Translation-Tracker-Inventory-Plugin/data/tracker.json` abrufbar und wird vom WordPress-Plugin gelesen.

## Konzept und Entscheidungen

Architektur, Entscheidungen und Phase-0-Deliverables liegen im
Schwester-Ordner `../Konzept/` (lokal im DACH-Workspace, *nicht* Teil dieses
Repos):

- `Konzept.md` — Gesamtarchitektur
- `Arbeitsplan.md` — verbindliche Entscheidungen und AI-ausführbare Aufgabenliste
- `API-Befunde.md` — REST-Endpoint-Liste
- `Issue-Vorlage-DACH.md` — Vorlage für neue DACH-Übersetzungs-Issues
- `schemas/` — JSON Schemata (authoritative Spec)

Die JSON-Schemata in `schemas/` dieses Repos sind eine Laufzeit-Kopie und
müssen synchron mit `../Konzept/schemas/` bleiben. Das Workspace-Skript
`../sync-schemas.py` hält beide Stände abgleichbar:

```bash
# Im Workspace-Root (eine Ebene über diesem Repo):
python sync-schemas.py            # prüft, Exit 1 bei Drift
python sync-schemas.py --apply    # Konzept/schemas/ → github/schemas/
```

Vor jedem Push empfehlenswert.

## Repository-Struktur

```text
.
├── .github/workflows/build.yml   Workflow: cron / dispatch / push
├── scope.yml                      Welche URLs sind in Scope
├── component-templates.yml        Default-Komponenten pro Item-Typ
├── schemas/                       Phase-0-Schemata (Kopie aus dem Konzept-Ordner)
├── src/
│   ├── inventory/                 REST-Module pro Item-Typ + URL-Normalizer
│   ├── github/                    GraphQL-Client + Issue-Parser
│   ├── builder/                   Joiner, Stats, Output-Writer
│   └── build.py                   Einstiegspunkt für die Action
├── tests/                         Unit-Tests (mit gemockter API)
├── requirements.txt
├── LICENSE                        GPL-2.0-or-later
└── README.md
```

Output landet auf einem separaten Branch:

```text
data branch
├── tracker.json                   Vom WP-Plugin gelesene Datei
└── last-run.md                    Mensch-lesbarer Bericht je Lauf
```

## Erst-Einrichtung durch den Maintainer

1. Repo auf GitHub anlegen (öffentlich): `<owner>/Training-Translation-Tracker-Inventory-Plugin`.
2. Default-Branch `main` (wird beim Push automatisch erzeugt).
3. Zweiten Branch `data` anlegen — leer reicht, wird vom Workflow überschrieben:

   ```bash
   git checkout --orphan data
   git rm -rf .
   echo "# Translation Tracker Output" > README.md
   git add README.md
   git commit -m "Initial data branch"
   git push origin data
   git checkout main
   ```

4. Secret `GH_PAT_PROJECT_READ` setzen:
   - Token unter <https://github.com/settings/tokens> erstellen.
   - Scopes: `read:org`, `project`.
   - Im Repo unter Settings → Secrets and variables → Actions als Repository Secret hinterlegen.
5. Workflow manuell auslösen unter Actions → „Build tracker.json" → Run workflow.

## Lokale Entwicklung

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt

# Dry-Run der Pipeline (gemockte APIs):
pytest

# Echter Lauf gegen das WordPress-Learn-Repo (Token in der Umgebung):
export GH_PAT_PROJECT_READ=<your token>
python -m src.build
```

## Lizenz

GPL-2.0-or-later — siehe `LICENSE`.
