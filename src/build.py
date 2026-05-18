"""Entry point for the GitHub Action.

Steps (Arbeitsplan §1.6):
  1. Load and validate scope.yml against schemas/scope.schema.json.
  2. Load and validate component-templates.yml.
  3. Dispatch each scope URL to the matching inventory module.
  4. Fetch all DACH issues via IssueFetcher.
  5. Build the group tree via builder.joiner.
  6. Compute stats.
  7. Write tracker.json + last-run.md into the output directory.
  8. On any uncaught error, exit non-zero WITHOUT touching the old tracker.json
     (the workflow's commit step only runs on a successful build).
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

from .builder import build_groups, calculate_stats, write_outputs
from .github.issues import IssueFetcher
from .inventory.dispatcher import Dispatcher

LOG = logging.getLogger("build")

# Default Project V2 source (Arbeitsplan §5.1 / §5.2)
DEFAULT_PROJECT_OWNER = "WordPress"
DEFAULT_PROJECT_NUMBER = 104


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Build tracker.json")
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
    output_dir: Path = args.output_dir or repo_root

    # ----------------------------------------------------------------- step 1
    LOG.info("Loading scope.yml")
    scope = _load_and_validate_yaml(
        repo_root / "scope.yml",
        repo_root / "schemas" / "scope.schema.json",
    )
    LOG.info("scope.yml: %d URLs, locale=%s", len(scope["items"]), scope["locale"])

    # ----------------------------------------------------------------- step 2
    LOG.info("Loading component-templates.yml")
    component_templates = _load_and_validate_yaml(
        repo_root / "component-templates.yml",
        repo_root / "schemas" / "component-templates.schema.json",
    )

    # ----------------------------------------------------------------- step 3
    # Throttle between learn.wordpress.org requests — quick consecutive
    # calls otherwise hit HTTP 429 (rate limit). 0.3s is empirically gentle
    # enough; override via INVENTORY_THROTTLE_S env var if needed.
    throttle_s = float(os.environ.get("INVENTORY_THROTTLE_S", "0.3"))
    LOG.info("Resolving inventory items (throttle=%.2fs)", throttle_s)
    dispatcher = Dispatcher(throttle_s=throttle_s)
    inventory_items = []
    inventory_warnings: list[str] = []
    for url in scope["items"]:
        try:
            inventory_items.append(dispatcher.fetch(url))
        except Exception as exc:  # noqa: BLE001 — keep going on per-item errors
            LOG.warning("Inventory fetch failed for %s: %s", url, exc)
            inventory_warnings.append(f"Inventory fetch failed for {url}: {exc}")
    LOG.info("Resolved %d inventory items", len(inventory_items))

    # ----------------------------------------------------------------- step 4
    issues = []
    if args.skip_issues:
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
    result = build_groups(inventory_items, issues, component_templates)
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
    tracker_path, report_path = write_outputs(
        output_dir,
        schema_version=1,
        generated_at=datetime.now(timezone.utc),
        scope_version=scope_version,
        stats=stats,
        groups=result.groups,
        warnings=warnings,
    )
    LOG.info("Wrote %s and %s", tracker_path.name, report_path.name)

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
