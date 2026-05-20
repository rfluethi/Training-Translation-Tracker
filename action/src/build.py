"""Entry point for the GitHub Action.

Two run modes:

  default (the GitHub Action uses this)
      Reads inventory from `inventory-cache.json` (committed to the repo),
      fetches DACH issues from Project V2, joins them, writes tracker.json.
      Does NOT call learn.wordpress.org — that lookup is too rate-limited
      for the GitHub-hosted runner IPs.

  --refresh-cache  (you run this locally from a non-rate-limited IP)
      Calls learn.wordpress.org for every URL in scope.yml, writes the
      resulting InventoryItems to `inventory-cache.json`. Does NOT fetch
      issues, does NOT write tracker.json. Commit the updated cache file
      and push.

Workflow (see README for the full procedure):

    # On your laptop, occasionally to refresh inventory:
    python -m src.build --refresh-cache
    git add inventory-cache.json
    git commit -m "Refresh inventory cache"
    git push

    # In CI, on every run:
    python -m src.build
"""

from __future__ import annotations

import argparse
import json
import logging
import os
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

import yaml
from jsonschema import Draft202012Validator

from .builder import build_groups, calculate_stats, render_hygiene_markdown, write_outputs
from .github.issues import IssueFetcher
from .inventory.cache import CACHE_FILENAME, load_cache, save_cache
from .inventory.dispatcher import Dispatcher

LOG = logging.getLogger("build")

# Default Project V2 source (Arbeitsplan §5.1 / §5.2)
DEFAULT_PROJECT_OWNER = "WordPress"
DEFAULT_PROJECT_NUMBER = 104


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Build tracker.json or refresh inventory cache")
    parser.add_argument(
        "--repo-root",
        type=Path,
        default=Path(__file__).resolve().parent.parent,
        help="Repo root (containing scope.yml, schemas/, etc.)",
    )
    parser.add_argument(
        "--output-dir",
        type=Path,
        default=None,
        help="Where to write tracker.json (default: repo root)",
    )
    parser.add_argument(
        "--refresh-cache",
        action="store_true",
        help="Call learn.wordpress.org for scope URLs that are NOT yet in "
             "inventory-cache.json, and append them. Skips issue fetch and "
             "tracker.json output. Run locally — too rate-limited for CI.",
    )
    parser.add_argument(
        "--force",
        action="store_true",
        help="When used with --refresh-cache: re-fetch every scope URL, "
             "even if already in the cache. Slow because of rate-limits.",
    )
    parser.add_argument(
        "--skip-issues",
        action="store_true",
        help="Skip GitHub Project fetch (useful for local smoke runs without a token)",
    )
    parser.add_argument(
        "--verbose", "-v", action="store_true", help="Enable debug logging"
    )

    args = parser.parse_args(argv)

    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format="%(asctime)s  %(name)s  %(levelname)s  %(message)s",
    )

    repo_root: Path = args.repo_root

    if args.refresh_cache:
        return _refresh_cache(repo_root, force=args.force)

    return _build_tracker(repo_root, args.output_dir, args.skip_issues)


# ---------------------------------------------------------------------------
# Mode A: refresh the inventory cache (run this locally)
# ---------------------------------------------------------------------------

def _refresh_cache(repo_root: Path, force: bool = False) -> int:
    """Append missing scope URLs to inventory-cache.json.

    Default: only fetch URLs that aren't already cached. Saves significant
    time and avoids burning through wordpress.org rate-limits — every URL
    that's already cached is left untouched.

    With --force: re-fetch ALL scope URLs, overwriting cached entries.
    Use this when you suspect the cache is stale or you've changed the
    inventory parsing logic.
    """
    scope = _load_and_validate_yaml(
        repo_root / "scope.yml",
        repo_root / "schemas" / "scope.schema.json",
    )
    scope_urls = _extract_scope_urls(scope)

    cache_path = repo_root / CACHE_FILENAME
    cached_items = {} if force else load_cache(cache_path)
    already = set(cached_items.keys())
    to_fetch = [u for u in scope_urls if force or u not in already]

    LOG.info(
        "scope.yml: %d URLs total, %d already cached, %d to fetch%s",
        len(scope_urls),
        len(scope_urls) - len(to_fetch) if not force else 0,
        len(to_fetch),
        " (--force: re-fetching all)" if force else "",
    )
    if not to_fetch:
        LOG.info(
            "Nothing to do — cache is fully populated for the current scope.yml. "
            "Use --force to re-fetch all entries anyway."
        )
        return 0

    # Higher throttle for local refresh — we have all the time in the world,
    # and we want to be polite to wordpress.org.
    throttle_s = float(os.environ.get("INVENTORY_THROTTLE_S", "1.5"))
    LOG.info("Throttle: %.2fs between fetches", throttle_s)
    dispatcher = Dispatcher(throttle_s=throttle_s)

    out: dict = dict(cached_items)
    failed: list[tuple[str, str]] = []
    for url in to_fetch:
        try:
            item = dispatcher.fetch(url)
        except Exception as exc:  # noqa: BLE001 — log and continue
            LOG.warning("Could not fetch %s: %s", url, exc)
            failed.append((url, str(exc)))
            continue
        out[item.url_en] = item
        LOG.info("Cached %s (parent_path=%s)", item.url_en, item.parent_path)

    save_cache(cache_path, out)

    LOG.info(
        "Wrote %d cache entries to %s (added/updated %d)",
        len(out), cache_path, len(to_fetch) - len(failed),
    )
    if failed:
        LOG.warning(
            "%d URLs could not be fetched (likely rate-limited). "
            "Re-run later to fill the gaps:", len(failed),
        )
        for url, reason in failed:
            LOG.warning("  - %s: %s", url, reason)

    return 0


# ---------------------------------------------------------------------------
# Mode B: build tracker.json (this is what the Action runs)
# ---------------------------------------------------------------------------

def _build_tracker(repo_root: Path, output_dir: Path | None, skip_issues: bool) -> int:
    output_dir = output_dir or repo_root

    # ----------------------------------------------------------------- step 1
    LOG.info("Loading scope.yml")
    scope = _load_and_validate_yaml(
        repo_root / "scope.yml",
        repo_root / "schemas" / "scope.schema.json",
    )
    scope_urls = _extract_scope_urls(scope)
    LOG.info(
        "scope.yml: locale=%s, %d pathway(s), %d handbook URL(s), %d URL(s) total",
        scope["locale"],
        len(scope.get("pathways") or []),
        len(scope.get("handbook") or []),
        len(scope_urls),
    )

    # ----------------------------------------------------------------- step 2
    LOG.info("Loading component-templates.yml")
    component_templates = _load_and_validate_yaml(
        repo_root / "component-templates.yml",
        repo_root / "schemas" / "component-templates.schema.json",
    )

    # ----------------------------------------------------------------- step 3
    # Read inventory from cache (committed to the repo). No live API calls
    # against wordpress.org — that lookup is too rate-limited in CI.
    cache_path = repo_root / CACHE_FILENAME
    cached_items = load_cache(cache_path)
    inventory_items = []
    inventory_warnings: list[str] = []
    for url in scope_urls:
        if url in cached_items:
            inventory_items.append(cached_items[url])
            continue
        msg = f"No inventory cache entry for {url}"
        LOG.warning("%s — run `python -m src.build --refresh-cache` locally", msg)
        inventory_warnings.append(msg)
    LOG.info("Loaded %d inventory items from cache (of %d scope URLs)",
             len(inventory_items), len(scope_urls))

    # ----------------------------------------------------------------- step 4
    issues = []
    if skip_issues:
        LOG.info("--skip-issues set: not fetching GitHub Project V2")
    else:
        token = os.environ.get("GH_PAT_PROJECT_READ", "")
        if not token:
            LOG.error("GH_PAT_PROJECT_READ is not set — cannot fetch issues")
            return 2
        fetcher = IssueFetcher(
            token=token,
            org=DEFAULT_PROJECT_OWNER,
            project_number=DEFAULT_PROJECT_NUMBER,
            locale=scope["locale"],
        )
        issues = fetcher.fetch_all()

    # ----------------------------------------------------------------- step 5
    LOG.info("Joining inventory and issues")
    # The full scope.yml dict carries the pathways hierarchy — that's what the
    # joiner uses to place each inventory item.
    result = build_groups(inventory_items, issues, component_templates, scope)
    warnings = inventory_warnings + result.warnings

    # ----------------------------------------------------------------- step 6
    stats = calculate_stats(result.groups)
    LOG.info(
        "Stats: %d items (done=%d review=%d wip=%d open=%d na=%d)",
        stats["total_items"],
        stats["done"],
        stats["review"],
        stats["wip"],
        stats["open"],
        stats["na"],
    )

    # ----------------------------------------------------------------- step 7
    scope_version = _scope_version(repo_root)
    generated_at = datetime.now(timezone.utc)
    tracker_path, report_path = write_outputs(
        output_dir,
        schema_version=1,
        generated_at=generated_at,
        scope_version=scope_version,
        stats=stats,
        groups=result.groups,
        warnings=warnings,
    )
    LOG.info("Wrote %s and %s", tracker_path.name, report_path.name)

    # ----------------------------------------------------------------- step 7b
    # Pflege-Bericht (Datenhygiene). Wird zusätzlich auf den data-Branch
    # committet — siehe .github/workflows/build.yml.
    hygiene_md = render_hygiene_markdown(result.hygiene, generated_at=generated_at)
    hygiene_path = output_dir / "data-hygiene.md"
    hygiene_path.write_text(hygiene_md, encoding="utf-8")
    LOG.info(
        "Wrote %s (%d markers/parse-errors, %d duplicates, %d items without issue)",
        hygiene_path.name,
        len(result.hygiene.issues_without_markers) + len(result.hygiene.issues_with_parse_errors),
        len(result.hygiene.duplicate_url_clusters),
        len(result.hygiene.inventory_items_without_issue),
    )

    # ----------------------------------------------------------------- self-check
    _validate_tracker_against_schema(tracker_path, repo_root / "schemas" / "tracker.schema.json")
    LOG.info("tracker.json validates against schema")

    return 0


# ---------------------------------------------------------------------------
# helpers
# ---------------------------------------------------------------------------

def _load_and_validate_yaml(yaml_path: Path, schema_path: Path):
    data = yaml.safe_load(yaml_path.read_text(encoding="utf-8"))
    schema = json.loads(schema_path.read_text(encoding="utf-8"))
    Draft202012Validator(schema).validate(data)
    return data


def _extract_scope_urls(scope: dict) -> list[str]:
    """Walk scope.yml's pathways tree + handbook list and return all
    leaf-item URLs in order. Duplicates across pathways and the handbook
    block are de-dup'd; the first occurrence wins for ordering.
    """
    out: list[str] = []
    seen: set[str] = set()
    for pathway in scope.get("pathways") or []:
        for course in pathway.get("courses") or []:
            for section in course.get("sections") or []:
                for url in section.get("items") or []:
                    if url not in seen:
                        seen.add(url)
                        out.append(url)
    for url in scope.get("handbook") or []:
        if url not in seen:
            seen.add(url)
            out.append(url)
    return out


def _validate_tracker_against_schema(tracker_path: Path, schema_path: Path) -> None:
    tracker = json.loads(tracker_path.read_text(encoding="utf-8"))
    schema = json.loads(schema_path.read_text(encoding="utf-8"))
    Draft202012Validator(schema).validate(tracker)


def _scope_version(repo_root: Path) -> str | None:
    """Return a short identifier for the scope.yml content — current git rev if available."""
    try:
        out = subprocess.run(
            ["git", "log", "-1", "--format=%h %ai", "--", "scope.yml"],
            cwd=str(repo_root),
            capture_output=True,
            text=True,
            check=True,
        )
        return out.stdout.strip() or None
    except Exception:  # noqa: BLE001
        return None


if __name__ == "__main__":  # pragma: no cover
    sys.exit(main())
