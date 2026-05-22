# Operations — Training Translation Tracker

> **Zielgruppe:** Maintainer und Site-Admins. Wer das Tool **betreibt** — nicht entwickelt, nicht benutzt.
> **Schwerpunkt:** Releases, Token-Pflege, Action-Trigger, Failure-Recovery, Cache-Verwaltung, Monitoring.

---

## 1. Topologie

| Stück | Wo lebt es? | Wer hat Zugriff? |
|---|---|---|
| Action-Code | Repo `Training-Translation-Tracker-Inventory-Plugin`, Branch `main` | Maintainer + Contributors |
| `tracker.json` | Selbes Repo, Branch `data` | Force-Push durch Action; lesbar via `raw.githubusercontent.com` |
| `last-run.md` | Selbes Repo, Branch `data` | Force-Push durch Action |
| Plugin-Code | Selbes Repo, Pfad `wp-plugin/` | Maintainer + Contributors |
| Plugin-Releases | GitHub-Releases im selben Repo | Erstellt automatisch durch `release-plugin.yml` |
| `GH_PAT_PROJECT_READ` | Repo-Secret im selben Repo | Maintainer |
| Inventory-Cache | `action/inventory-cache.json` im Repo, Branch `main` | Lokal beim Maintainer aktualisiert + committet |

---

## 2. Release-Workflow (Plugin)

### Vorbereitung

Drei Stellen synchron halten (Beispiel `0.2.5`):

| Datei | Stelle |
|---|---|
| `wp-plugin/training-translation-tracker.php` | Plugin-Header `Version: 0.2.5` |
| `wp-plugin/training-translation-tracker.php` | Konstante `TTT_VERSION = '0.2.5'` |
| `wp-plugin/readme.txt` | `Stable tag: 0.2.5` |

`readme.txt` Changelog mit der neuen Version ergänzen — Format siehe vorhandene Einträge. Items nach „Compliance", „Architektur", „Polish" gruppieren.

### Release auslösen

```bash
# Auf main, mit allen Änderungen committet und gepusht
git tag v0.2.5
git push origin v0.2.5
```

Der Workflow `.github/workflows/release-plugin.yml` läuft automatisch und macht:

1. Checkout des getaggten Commits.
2. Version aus Tag-Name extrahieren (entfernt `v`-Prefix).
3. **Verify**: prüft, dass Plugin-Header, Konstante und `readme.txt` alle den Tag-Wert tragen. Bei Mismatch bricht der Workflow ab.
4. Plugin-ZIP bauen via `build-plugin-zip.sh` (rsync exkludiert `.git`, `.DS_Store`, `README.md`, `docs/`, `*.zip`).
5. Changelog-Notes für die getaggte Version aus `readme.txt` extrahieren.
6. GitHub-Release erstellen via `softprops/action-gh-release@v2` mit dem ZIP als Asset und den Notes als Beschreibung.

Verlauf beobachten unter `Actions → release-plugin.yml`. Bei grünem Workflow: Release ist verfügbar unter `https://github.com/<owner>/Training-Translation-Tracker-Inventory-Plugin/releases/tag/v0.2.5`.

### Lokal ZIP bauen (ohne Release)

```bash
./build-plugin-zip.sh
# → ~/Desktop/training-translation-tracker.zip
```

Geht ohne Tag und ohne GitHub. Nützlich für lokales Testen vor dem Tag-Push.

---

## 3. Action-Workflow (`build.yml`)

### Drei Auslöser

| Auslöser | Wann | Wirkung |
|---|---|---|
| `schedule: cron: "0 */12 * * *"` | Alle 12 h | Regulärer Build |
| `workflow_dispatch` | Manuell in der GitHub-UI | Sofort-Build, GraphQL-Cost wird ins Log geschrieben |
| `push` auf `main` | Bei Änderung von `scope.yml` / `component-templates.yml` | Re-Build nach Konfig-Änderung |

### Manuelles Triggern

In der GitHub-UI: **Actions → Build tracker.json → Run workflow**. Branch `main` auswählen, „Run workflow" klicken. Erwartete Laufzeit ~2 Minuten.

### Was die Action im Log zeigt

- GraphQL-Kosten (`Cost: X / Y points`) — frühwarnung bei Quota-Knappheit.
- Inventory-Lade-Status (aus Cache: X URLs).
- Parse-Warnungen (Issue # mit fehlerhaftem Body).
- Schema-Validation-Ergebnis am Ende.
- Committed-Hash für `tracker.json`.

### Bei Fehlschlag

Action-Fehler bricht den Lauf ab, **schreibt aber kein** kaputtes `tracker.json` — der bestehende Stand auf dem `data`-Branch bleibt erhalten. Frontend zeigt damit weiter den letzten erfolgreichen Stand.

Maintainer bekommt eine Standard-GitHub-Notification. Erste Diagnose: Action-Log im Repo öffnen.

---

## 4. Inventory-Cache pflegen

Die Action ruft `learn.wordpress.org` **nicht** mehr live an — GitHub-Runner-IPs werden vom WP-CDN aggressiv ratelimitet, in der Praxis kommt fast keine Anfrage durch. Das Inventar lebt als vorberechnete Datei `action/inventory-cache.json` im Repo.

### Wann muss aufgefrischt werden?

- `scope.yml` wurde um neue URLs erweitert.
- Inhalte auf learn.wordpress.org wurden umstrukturiert (umbenannt, gelöscht, verschoben).
- Frische Daten sind erwünscht (selten).

### Wie

```bash
cd action
source .venv/bin/activate
python -m src.build --refresh-cache       # nur fehlende URLs holen (Default)
# oder
python -m src.build --refresh-cache --force  # alles neu holen
```

Throttle: 1.5 s pro Request, default. URLs, die nicht erreichbar sind, bleiben einfach nicht im Cache — beim nächsten Lauf erneut versuchen. Issues zu diesen URLs landen vorübergehend im Orphan-Bucket.

### Committen

```bash
git diff inventory-cache.json   # Review der Änderungen
git add inventory-cache.json
git commit -m "Refresh inventory cache (n new entries)"
git push
```

Push triggert die Action — neue `tracker.json` wird gebaut.

---

## 5. Token-Pflege

### `GH_PAT_PROJECT_READ`

GitHub Personal Access Token für die Action. Liest aus `WordPress/Learn`-Issues und Project V2 #104.

**Scopes:** `read:org`, `project`.

**Inhaber:** aktuell persönlicher Account des Maintainers (Rico).

**Ablauf:** Token hat keinen geplanten Ablauf, aber GitHub erlaubt max. 1 Jahr für klassische PATs und 1 Tag bis 1 Jahr für Fine-Grained PATs. Wer den Token erstellt, sollte den Ablauf in seinem Kalender notieren.

### Token erstellen / rotieren

1. Unter <https://github.com/settings/tokens> einen neuen Token erstellen.
2. Scopes: `read:org`, `project`.
3. Im Repo unter **Settings → Secrets and variables → Actions** als Repository-Secret `GH_PAT_PROJECT_READ` hinterlegen (überschreibt den alten Wert).
4. Action manuell triggern, um zu verifizieren.
5. Alten Token bei GitHub revoken.

### Umzug zu Team-Account

Wenn ein DACH-Team-Account existiert:

1. Neuer Token mit demselben Scope vom Team-Account aus erstellen.
2. Im Repo-Secret aktualisieren.
3. Doku (diese Datei) aktualisieren, Inhaberschaft notieren.
4. Maintainer-Eintrag im Action-Workflow ggf. anpassen (kein technischer Effekt — `github-actions[bot]` bleibt die Commit-Identität).

---

## 6. Plugin-Update auf einer WordPress-Site

### Über WP-Admin-UI

1. **Plugins → Installierte Plugins** — Translation Tracker deaktivieren (Einstellungen und Cache bleiben erhalten).
2. **Translation Tracker** entfernen (rote Löschen-Aktion).
3. **Plugins → Plugin hochladen** — ZIP aus dem aktuellen Release-Tab wählen.
4. **Jetzt installieren** → **Aktivieren**.
5. **Einstellungen → Translation Tracker** — URL und Cache-Dauer prüfen.
6. **Cache jetzt leeren** drücken.
7. Test-Seite öffnen, prüfen ob Dashboard erscheint.

### Per WP-CLI

```bash
wp plugin install ~/Downloads/training-translation-tracker.zip --activate
wp option update ttt_settings '{"tracker_url":"…","cache_hours":12}' --format=json
wp transient delete ttt_tracker_payload
wp transient delete ttt_tracker_last_good
```

### Per GitHub-Updater-Plugin (geplant, Phase 4)

GitHub-Updater-Plugin verwaltet das Update direkt vom Repo. Sobald die Variante ausgewählt und konfiguriert ist, läuft der Update-Prozess automatisch über die WordPress-Plugin-Liste.

---

## 7. Cache-Verwaltung

### Plugin-Transients

| Transient-Key | TTL | Inhalt |
|---|---|---|
| `ttt_tracker_payload` | `cache_hours` (Default 12 h) | Aktuelle `tracker.json`-Payload |
| `ttt_tracker_last_good` | **kein TTL** | Letzte erfolgreich geparste Payload (Fallback) |

### Cache leeren

**Aus dem Admin:** Einstellungen → Translation Tracker → **Cache jetzt leeren** (AJAX, nutzt Nonce + Capability-Check). Löscht beide Transients.

**Per WP-CLI:**

```bash
wp transient delete ttt_tracker_payload
wp transient delete ttt_tracker_last_good
```

**Per SQL** (Notfall):

```sql
DELETE FROM wp_options WHERE option_name IN (
  '_transient_ttt_tracker_payload',
  '_transient_timeout_ttt_tracker_payload',
  '_transient_ttt_tracker_last_good'
);
```

---

## 8. Monitoring

### Action-Status

GitHub-Actions-Tab gibt Übersicht: grün = Build OK, rot = Fehlschlag. Maintainer erhält Standard-E-Mail-Notification bei Fehlschlag.

### `last-run.md`

Pro Lauf wird auf dem `data`-Branch eine `last-run.md` committet:

- Statistik: wie viele Items insgesamt, wie viele mit Issue, wie viele Orphans.
- Warnungen: Issues mit Parse-Fehler, Duplikate, fehlende URLs.
- Endzeit + Run-ID.

URL: `https://github.com/<owner>/Training-Translation-Tracker-Inventory-Plugin/blob/data/last-run.md`

### Plugin-seitig

- **Admin-Hinweis** im Dashboard: bei Fetch-Fehler erscheint ein Span „letzter erfolgreich gespeicherter Stand — aktueller Fetch schlug fehl" im Header.
- **`generated_at`-Zeitstempel** in den Plugin-Settings zeigt, wann der aktuelle Cache-Stand gebaut wurde. Älter als 24 h ohne Action-Fehler → Cache hängt.

### Health-Probes (manuell)

```bash
# tracker.json ist erreichbar?
curl -sf https://raw.githubusercontent.com/<owner>/.../data/tracker.json | jq .schema_version
# Erwartet: 1

# generated_at innerhalb der letzten 13 h?
curl -sf https://raw.githubusercontent.com/<owner>/.../data/tracker.json | jq .generated_at
```

---

## 9. Failure-Recovery

### Fall 1: Action schlägt seit mehreren Tagen fehl

1. Action-Log öffnen (GitHub → Actions → letzte rote Runs).
2. Häufige Ursachen:
   - **Token abgelaufen** → neuen Token erstellen, Secret aktualisieren.
   - **GraphQL-Query-Cost überschritten** → meist transient; einmal manuell triggern reicht.
   - **Schema-Validation-Fail** → ein neues Issue mit unbekanntem Feld; Schema oder Parser-Robustheit anpassen.
3. Manuell triggern. Wenn weiter rot: lokal `python -m src.build` mit Token reproduzieren.

### Fall 2: Plugin zeigt nur den alten Stand, obwohl Action grün ist

1. Plugin-Cache leeren (Settings → „Cache jetzt leeren").
2. Wenn weiter alt: Plugin-Settings prüfen — URL korrekt? Netzwerk-Probleme?
3. **WordPress-eigene Caches** prüfen (WP Rocket, LiteSpeed, Object Cache).
4. Direkt-Probe per `curl` vom WP-Server aus, um Netzwerk-Sperre auszuschließen.

### Fall 3: Issues fehlen im Dashboard

1. Issue im DACH-Projekt-Board: hat es `Locale = German`?
2. Original-URL kanonisch? (lowercase, https, Trailing-Slash, ohne Query/Fragment, ohne `www.`)
3. Status-Tabelle mit korrekten HTML-Markern? Vorlage aus [Issue-Vorlagen-DACH.md](Issue-Vorlagen-DACH.md).
4. Action manuell triggern + Plugin-Cache leeren.

### Fall 4: `last_good`-Fallback hängt

Wenn das Plugin den Last-Good-Stand zeigt, weil der frische Fetch fehlschlägt:

1. URL in den Settings prüfen — Tippfehler?
2. Von der WP-Site aus erreichbar? (Firewall, Proxy, Hosting-Block?)
3. JSON unter der URL valid? `curl URL | jq .schema_version` (sollte `1` liefern).
4. Bei Schema-Mismatch: Action-Output checken, ggf. Plugin auf neue Version updaten.

### Fall 5: `data`-Branch komplett zerschossen

Die `tracker.json` ist aus den GitHub-Issues jederzeit re-generierbar — keine History nötig. Wenn der `data`-Branch verloren geht:

```bash
git checkout --orphan data
git rm -rf .
echo "# Translation Tracker Output" > README.md
git add README.md
git commit -m "Re-init data branch"
git push origin data
```

Anschließend Action manuell triggern — sie macht den Force-Push und füllt den Branch wieder.

---

## 10. Erst-Einrichtung (neues Repo)

Falls ein neues Repo aufgesetzt wird (z. B. anderer Locale-Account):

1. Repo öffentlich anlegen unter `<owner>/Training-Translation-Tracker-Inventory-Plugin`.
2. Default-Branch `main`.
3. `data`-Branch initialisieren (siehe Fall 5 oben).
4. Secret `GH_PAT_PROJECT_READ` setzen (Scopes: `read:org`, `project`).
5. `scope.yml` mit den eigenen URLs füllen, `component-templates.yml` bei Bedarf anpassen.
6. `inventory-cache.json` lokal aufbauen (`python -m src.build --refresh-cache`), committen.
7. Workflow `build.yml` manuell triggern.
8. Plugin auf der WordPress-Site installieren, URL in den Settings auf den neuen `data`-Branch zeigen lassen.

---

## 11. Backup

Im aktuellen Setup nicht nötig: der `data`-Branch ist re-generierbar, der Plugin-Code ist im Mono-Repo, und die Plugin-Konfiguration auf der Site umfasst nur drei Settings (URL, Cache-Stunden, Default).

Wichtig ist nur:

- Repo selbst — wird durch GitHub gehosted.
- `GH_PAT_PROJECT_READ` — Token-Erstellung dokumentieren (Wer? Wann? Mit welchen Scopes?).

Wenn das DACH-Team perspektivisch eigenständig wird, sollte mindestens ein zweiter Maintainer-Zugang zu Repo + Token bestehen, damit der Bus-Faktor nicht 1 ist.

---

## Weiterführende Dokumente

- System-Architektur: [Architektur.md](Architektur.md)
- Code & Erweiterungen: [Developer.md](Developer.md)
- Benutzersicht (Plugin-Settings, Shortcodes): [User-Guide.md](User-Guide.md)
- Maintainer-Arbeitsplan (außerhalb des Repos): `../Konzept/Arbeitsplan.md`
