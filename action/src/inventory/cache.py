"""Inventory cache — pre-computed inventory data, committed to the repo.

learn.wordpress.org rate-limits GitHub-hosted runner IPs hard enough that
the Action cannot reliably fetch inventory directly. Workaround: a
maintainer runs the inventory fetch locally (from a non-rate-limited IP),
writes the result to `inventory-cache.json`, and commits it. The Action
then reads from the cache file — no wordpress.org calls during the run.

The cache file is plain JSON for easy review in pull requests.
"""

from __future__ import annotations

import json
import logging
from dataclasses import asdict
from datetime import datetime, timezone
from pathlib import Path

from .base import InventoryItem

LOG = logging.getLogger(__name__)

CACHE_SCHEMA_VERSION = 1
CACHE_FILENAME = "inventory-cache.json"


def load_cache(path: Path) -> dict[str, InventoryItem]:
    """Return URL → InventoryItem mapping from the cache file.

    Returns an empty dict if the file is missing, malformed, or carries a
    newer schema version we don't understand.
    """
    if not path.exists():
        LOG.info("No inventory cache at %s — proceeding without inventory.", path)
        return {}

    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        LOG.warning("Cache file %s is unreadable: %s", path, exc)
        return {}

    version = data.get("schema_version")
    if version != CACHE_SCHEMA_VERSION:
        LOG.warning(
            "Cache file %s has unsupported schema_version=%r — ignoring.",
            path, version,
        )
        return {}

    raw_items = data.get("items") or {}
    out: dict[str, InventoryItem] = {}
    for url, fields in raw_items.items():
        try:
            out[url] = InventoryItem(
                type=fields["type"],
                slug=fields["slug"],
                title_en=fields["title_en"],
                url_en=fields["url_en"],
                parent_path=list(fields.get("parent_path") or []),
                url_wptv=fields.get("url_wptv"),
                url_youtube=fields.get("url_youtube"),
                draft_original=bool(fields.get("draft_original", False)),
            )
        except (KeyError, TypeError) as exc:
            LOG.warning("Skipping malformed cache entry for %s: %s", url, exc)

    LOG.info("Loaded %d inventory items from cache.", len(out))
    return out


def save_cache(path: Path, items: dict[str, InventoryItem]) -> None:
    """Write an inventory cache file (overwrites existing)."""
    payload = {
        "schema_version": CACHE_SCHEMA_VERSION,
        "generated_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "items": {url: _item_to_cache_dict(item) for url, item in items.items()},
    }
    path.write_text(
        json.dumps(payload, ensure_ascii=False, indent=2, sort_keys=False) + "\n",
        encoding="utf-8",
    )
    LOG.info("Wrote inventory cache with %d items to %s", len(items), path)


def _item_to_cache_dict(item: InventoryItem) -> dict:
    """Serialise an InventoryItem to the cache file format.

    Drops empty/falsy optional fields to keep diffs in the committed JSON minimal.
    """
    out: dict = {
        "type": item.type,
        "slug": item.slug,
        "title_en": item.title_en,
        "url_en": item.url_en,
    }
    if item.parent_path:
        out["parent_path"] = list(item.parent_path)
    if item.url_wptv:
        out["url_wptv"] = item.url_wptv
    if item.url_youtube:
        out["url_youtube"] = item.url_youtube
    if item.draft_original:
        out["draft_original"] = True
    return out


# Suppress lint warning about asdict being unused (kept for future serialisation).
_ = asdict
