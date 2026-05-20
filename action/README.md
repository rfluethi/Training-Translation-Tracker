# Action — Translation-Tracker-Datenpipeline

> Python-basierte GitHub Action, die `tracker.json` für den DACH-Tracker baut.
> Teil des Mono-Repos — das WordPress-Plugin liegt unter `../wp-plugin/`.
> Übersicht: [Top-Level-README](../README.md).

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

Architektur, Entscheidungen und Phase-0-Deliverables liegen lokal beim Maintainer
unter `../Konzept/` (NICHT Teil dieses Repos):

- `Konzept.md` — Gesamtarchitektur
- `Arbeitsplan.md` — verbindliche Entscheidungen und AI-ausführbare Aufgabenliste
- `API-Befunde.md` — REST-Endpoint-Liste
- `Issue-Vorlage-DACH.md` — Vorlage für neue DACH-Übersetzungs-Issues
- `schemas/` — JSON Schemata (authoritative Spec)

Die JSON-Schemata in `schemas/` dieses Ordners sind eine Laufzeit-Kopie und
müssen synchron mit `../Konzept/schemas/` bleiben. Das Top-Level-Skript
`../sync-schemas.py` hält beide Stände abgleichbar:

```bash
# Im Repo-Root:
python sync-schemas.py            # prüft, Exit 1 bei Drift
python sync-schemas.py --apply    # Konzept/schemas/ → action/schemas/
```

Vor jedem Push empfehlenswert.

## Repository-Struktur

```text
.
├── .github/workflows/build.yml   Workflow: cron / dispatch / push
├── scope.yml                      Locale + Hierarchie + URLs (Single Source of Truth)
├── component-templates.yml        Default-Komponenten pro Item-Typ
├── inventory-cache.json           Vorberechnete Inventar-Daten (lokal aktualisiert)
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

## Inventar-Cache

Die Action ruft `learn.wordpress.org` **nicht** mehr live an — die
GitHub-Runner-IPs werden vom WP-CDN aggressiv ratelimitet, in der Praxis
kommt fast keine Anfrage durch. Stattdessen lebt das Inventar als
vorberechnete Datei `inventory-cache.json` im Repo. Die Action liest diese
Datei und macht damit die Pathway-Gruppierung.

Wenn sich `scope.yml` ändert oder Inhalte auf learn.wordpress.org
umstrukturiert werden, frischt der Maintainer den Cache **lokal** auf
(Heim-/Büro-IPs sind nicht ratelimitet) und committet die neue Datei:

```bash
python -m src.build --refresh-cache
git diff inventory-cache.json     # Review der Änderungen
git add inventory-cache.json
git commit -m "Refresh inventory cache"
git push
```

`--refresh-cache` fetcht jede URL aus `scope.yml` (mit 1.5s-Throttle,
default-mäßig) und schreibt die InventoryItems in `inventory-cache.json`.
Es macht **keinen** Issue-Fetch und schreibt **kein** `tracker.json`.

URLs, die beim Refresh nicht erreichbar sind, bleiben einfach nicht im
Cache — beim nächsten lokalen Lauf erneut versuchen. Issues zu diesen
URLs landen dann eben (vorübergehend) im Orphan-Bucket.

## Lokale Entwicklung

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt

# Tests laufen lassen:
pytest

# Lokaler Voll-Build (braucht Token, liest aus Cache):
export GH_PAT_PROJECT_READ=<your token>
python -m src.build

# Cache aufbauen / aktualisieren:
python -m src.build --refresh-cache
```

## Lizenz

GPL-2.0-or-later — siehe `LICENSE`.
