# Operations, Training Translation Tracker

> **Audience:** Maintainers and site admins. The people who **operate** the tool, not develop it and not use it.
> **Focus:** Releases, token maintenance, action triggers, failure recovery, cache management, monitoring.

## 1. Topology

| Component | Where it lives | Who has access |
|---|---|---|
| Action code | Repo `Training-Translation-Tracker-Inventory-Plugin`, branch `main` | Maintainers + contributors |
| `tracker.json` | Same repo, branch `data` | Force-pushed by the action; readable via `raw.githubusercontent.com` |
| `last-run.md` | Same repo, branch `data` | Force-pushed by the action |
| Plugin code | Same repo, path `wp-plugin/` | Maintainers + contributors |
| Plugin releases | GitHub Releases in the same repo | Created automatically by `release-plugin.yml` |
| `GH_PAT_PROJECT_READ` | Repo secret in the same repo | Maintainer |
| Inventory cache | `action/inventory-cache.json` in the repo, branch `main` | Refreshed locally and committed |

## 2. Release workflow (plugin)

### Preparation

Keep three places in sync (example `0.2.5`):

| File | Place |
|---|---|
| `wp-plugin/training-translation-tracker.php` | Plugin header `Version: 0.2.5` |
| `wp-plugin/training-translation-tracker.php` | Constant `TTT_VERSION = '0.2.5'` |
| `wp-plugin/readme.txt` | `Stable tag: 0.2.5` |

Add a `readme.txt` changelog entry for the new version, follow the format of existing entries. Group items by "Compliance", "Architecture", "Polish".

### Trigger the release

```bash
# On main, with all changes committed and pushed
git tag v0.2.5
git push origin v0.2.5
```

The workflow `.github/workflows/release-plugin.yml` runs automatically and does:

1. Check out the tagged commit.
2. Extract the version from the tag name (strips the `v` prefix).
3. **Verify**: checks that plugin header, constant and `readme.txt` all carry the tag value. On mismatch the workflow aborts.
4. Build the plugin ZIP via `build-plugin-zip.sh` (rsync excludes `.git`, `.DS_Store`, `README.md`, `docs/`, `*.zip`).
5. Extract changelog notes for the tagged version from `readme.txt`.
6. Create the GitHub release via `softprops/action-gh-release@v2` with the ZIP as asset and the notes as description.

Watch progress under `Actions → release-plugin.yml`. When the workflow is green: the release is available at `https://github.com/<owner>/Training-Translation-Tracker-Inventory-Plugin/releases/tag/v0.2.5`.

### Build the ZIP locally (without a release)

```bash
./build-plugin-zip.sh
# → ~/Desktop/training-translation-tracker.zip
```

Works without a tag and without GitHub. Useful for local testing before pushing the tag.

## 3. Action workflow (`build.yml`)

### Three triggers

| Trigger | When | Effect |
|---|---|---|
| `schedule: cron: "0 */12 * * *"` | Every 12 h | Regular build |
| `workflow_dispatch` | Manually from the GitHub UI | Immediate build, GraphQL cost is written to the log |
| `push` on `main` | On changes to `scope.yml` / `component-templates.yml` | Rebuild after config change |

### Triggering manually

In the GitHub UI: **Actions → Build tracker.json → Run workflow**. Pick branch `main`, click "Run workflow". Expected runtime ~2 minutes.

### What the action prints in the log

GraphQL cost (`Cost: X / Y points`), early warning when quota gets tight. Inventory load status (from cache: X URLs). Parse warnings (issue # with malformed body). Schema validation result at the end. Committed hash for `tracker.json`.

### On failure

An action failure aborts the run but **does not** write a broken `tracker.json`, the existing state on the `data` branch stays intact. The frontend therefore continues to display the last successful state.

The maintainer receives a standard GitHub notification. First diagnostic step: open the action log in the repo.

## 4. Maintaining the inventory cache

The action **no longer** calls `learn.wordpress.org` live. GitHub runner IPs are throttled aggressively by the WP CDN, in practice hardly any request gets through. The inventory lives as a precomputed file `action/inventory-cache.json` in the repo.

### When does the cache need refreshing?

`scope.yml` got new URLs. Content on learn.wordpress.org was restructured (renamed, deleted, moved). Or fresh data is wanted (rare).

### How

```bash
cd action
source .venv/bin/activate
python -m src.build --refresh-cache       # only fetch missing URLs (default)
# or
python -m src.build --refresh-cache --force  # fetch everything fresh
```

Throttle: 1.5 s per request, default. URLs that cannot be reached simply stay out of the cache, retry on the next run. Issues for those URLs land temporarily in the orphan bucket.

### Commit

```bash
git diff inventory-cache.json   # review the changes
git add inventory-cache.json
git commit -m "Refresh inventory cache (n new entries)"
git push
```

The push triggers the action and a new `tracker.json` is built.

## 5. Token maintenance

### `GH_PAT_PROJECT_READ`

GitHub Personal Access Token for the action. Reads from `WordPress/Learn` issues and Project V2 #104.

**Scopes:** `read:org`, `project`.

**Owner:** currently the maintainer's personal account (Rico).

**Expiry:** the token has no planned expiry, but GitHub allows a maximum of 1 year for classic PATs and 1 day to 1 year for fine-grained PATs. Whoever creates the token should note the expiry in their calendar.

### Creating / rotating the token

1. Create a new token at <https://github.com/settings/tokens>.
2. Scopes: `read:org`, `project`.
3. Store it in the repo under **Settings → Secrets and variables → Actions** as a repository secret `GH_PAT_PROJECT_READ` (overwrites the previous value).
4. Trigger the action manually to verify.
5. Revoke the old token at GitHub.

### Move to a team account

When a DACH team account exists:

1. Create a new token with the same scopes from the team account.
2. Update the repo secret.
3. Update the documentation (this file), note ownership.
4. Adjust the maintainer entry in the action workflow if needed (no technical effect, `github-actions[bot]` remains the commit identity).

## 6. Plugin update on a WordPress site

### Via the WP admin UI

1. **Plugins → Installed Plugins**, deactivate Translation Tracker (settings and cache are preserved).
2. Remove **Translation Tracker** (red delete action).
3. **Plugins → Upload Plugin**, choose the ZIP from the current Releases tab.
4. **Install Now** → **Activate**.
5. **Settings → Translation Tracker**, verify URL and cache duration.
6. Press **Clear cache now**.
7. Open the test page, verify that the dashboard appears.

### Via WP-CLI

```bash
wp plugin install ~/Downloads/training-translation-tracker.zip --activate
wp option update ttt_settings '{"tracker_url":"…","cache_hours":12}' --format=json
wp transient delete ttt_tracker_payload
wp transient delete ttt_tracker_last_good
```

### Via the GitHub Updater plugin (planned, Phase 4)

A GitHub Updater plugin handles the update directly from the repo. Once the variant is picked and configured, the update flow runs automatically through the WordPress plugins list.

## 7. Cache management

### Plugin transients

| Transient key | TTL | Contents |
|---|---|---|
| `ttt_tracker_payload` | `cache_hours` (default 12 h) | Current `tracker.json` payload |
| `ttt_tracker_last_good` | **no TTL** | Last successfully parsed payload (fallback) |

### Clearing the cache

**From the admin:** Settings → Translation Tracker → **Clear cache now** (AJAX, uses nonce + capability check). Deletes both transients.

**Via WP-CLI:**

```bash
wp transient delete ttt_tracker_payload
wp transient delete ttt_tracker_last_good
```

**Via SQL** (emergency):

```sql
DELETE FROM wp_options WHERE option_name IN (
  '_transient_ttt_tracker_payload',
  '_transient_timeout_ttt_tracker_payload',
  '_transient_ttt_tracker_last_good'
);
```

## 8. Monitoring

### Action status

The GitHub Actions tab gives an overview: green = build OK, red = failure. The maintainer gets the standard email notification on failure.

### `last-run.md`

A `last-run.md` is committed to the `data` branch on each run:

Statistics: how many items in total, how many with an issue, how many orphans. Warnings: issues with parse errors, duplicates, missing URLs. End time + run ID.

URL: `https://github.com/<owner>/Training-Translation-Tracker-Inventory-Plugin/blob/data/last-run.md`

### Plugin side

**Admin notice** in the dashboard: on fetch failure a span "last successfully stored state, current fetch failed" appears in the header. **`generated_at` timestamp** in the plugin settings shows when the current cache state was built. Older than 24 h without an action failure means the cache is stuck.

### Health probes (manual)

```bash
# Is tracker.json reachable?
curl -sf https://raw.githubusercontent.com/<owner>/.../data/tracker.json | jq .schema_version
# Expected: 1

# Is generated_at within the last 13 h?
curl -sf https://raw.githubusercontent.com/<owner>/.../data/tracker.json | jq .generated_at
```

## 9. Failure recovery

### Case 1: The action has been failing for several days

1. Open the action log (GitHub → Actions → latest red runs).
2. Common causes:
   - **Token expired** → create a new token, update the secret.
   - **GraphQL query cost exceeded** → usually transient; one manual trigger is enough.
   - **Schema validation fail** → a new issue with an unknown field; adjust the schema or harden the parser.
3. Trigger manually. If still red: reproduce locally with `python -m src.build` using the token.

### Case 2: The plugin only shows the old state although the action is green

1. Clear the plugin cache (Settings → "Clear cache now").
2. If still stale: check plugin settings, is the URL correct? Network issues?
3. Check **WordPress-side caches** (WP Rocket, LiteSpeed, object cache).
4. Probe directly via `curl` from the WP server to rule out network blocks.

### Case 3: Issues are missing from the dashboard

1. Issue in the DACH project board: does it have `Locale = German`?
2. Is the original URL canonical? (lowercase, https, trailing slash, no query/fragment, no `www.`)
3. Status table with correct HTML markers? Use the template from [Issue-Templates-DACH.md](Issue-Templates-DACH.md).
4. Trigger the action manually + clear the plugin cache.

### Case 4: `last_good` fallback is stuck

When the plugin shows the last-good state because the fresh fetch fails:

1. Check the URL in the settings, typo?
2. Reachable from the WP site? (firewall, proxy, hosting block?)
3. Is the JSON at the URL valid? `curl URL | jq .schema_version` (should yield `1`).
4. On schema mismatch: check the action output, update the plugin to a new version if needed.

### Case 5: `data` branch completely broken

The `tracker.json` can be regenerated from the GitHub issues at any time, no history is required. If the `data` branch is lost:

```bash
git checkout --orphan data
git rm -rf .
echo "# Translation Tracker Output" > README.md
git add README.md
git commit -m "Re-init data branch"
git push origin data
```

Then trigger the action manually, it does the force push and refills the branch.

## 10. Initial setup (new repo)

If a new repo is set up (e.g. for a different locale account):

1. Create a public repo under `<owner>/Training-Translation-Tracker-Inventory-Plugin`.
2. Default branch `main`.
3. Initialize the `data` branch (see case 5 above).
4. Set the secret `GH_PAT_PROJECT_READ` (scopes: `read:org`, `project`).
5. Fill `scope.yml` with your own URLs, adjust `component-templates.yml` if needed.
6. Build `inventory-cache.json` locally (`python -m src.build --refresh-cache`), commit it.
7. Trigger the workflow `build.yml` manually.
8. Install the plugin on the WordPress site, point the URL in the settings to the new `data` branch.

## 11. Backup

Not needed in the current setup: the `data` branch is regenerable, the plugin code is in the mono-repo, and the plugin configuration on the site only covers three settings (URL, cache hours, default).

What matters:

The repo itself, which is hosted by GitHub. And `GH_PAT_PROJECT_READ`, document the token creation (who? when? with which scopes?).

If the DACH team becomes independent at some point, at least a second maintainer should have access to the repo + token so the bus factor is not 1.

## Further reading

- System architecture: [Architecture.md](Architecture.md)
- Code & extensions: [Developer.md](Developer.md)
- User perspective (plugin settings, shortcodes): [User-Guide.md](User-Guide.md)
