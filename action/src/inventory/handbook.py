"""Handbook inventory source.

The training handbook is a hierarchical CPT `handbook` on
make.wordpress.org/training/ (see API-Befunde.md §2). The hierarchy
is resolved client-side via the `parent` field on each page.

Endpoint: GET https://make.wordpress.org/training/wp-json/wp/v2/handbook

We default the item type to `handbook_text`. A future version can detect
embedded video and switch to `handbook_video` (open question — Arbeitsplan
phase 3).
"""

from __future__ import annotations

import logging
import re
from typing import Any

from .base import InventoryError, InventoryItem, InventorySource
from .url_normalizer import normalize

LOG = logging.getLogger(__name__)

LIST_URL = "https://make.wordpress.org/training/wp-json/wp/v2/handbook"
BASE_PATH = "/training/handbook/"

# Handbook page URL: https://make.wordpress.org/training/handbook/[<section>/]...<slug>/
_URL_RE = re.compile(
    r"^https://make\.wordpress\.org/training/handbook/((?:[a-z0-9\-]+/)*)([a-z0-9\-]+)/$"
)


class HandbookInventorySource(InventorySource):
    """Resolves handbook URLs and walks the parent chain for hierarchy."""

    def __init__(self, session=None) -> None:
        super().__init__(session)
        # Cache id → page for parent-chain walks.
        self._page_by_id: dict[int, dict[str, Any]] = {}

    # ----------------------------------------------------------------- public

    def fetch(self, normalized_url: str) -> InventoryItem:
        match = _URL_RE.match(normalized_url)
        if not match:
            raise InventoryError(f"Not a handbook URL: {normalized_url!r}")
        slug = match.group(2)

        pages = self._get_json(
            LIST_URL,
            params={
                "slug": slug,
                "status": "publish",
                "_fields": "id,slug,title,parent,link,status",
            },
        )
        if not pages:
            raise InventoryError(f"No handbook page with slug {slug!r}")
        page = pages[0]
        title = (page.get("title", {}) or {}).get("rendered", "") or slug

        # Cache and walk up the parent chain to build the section path.
        self._page_by_id[page["id"]] = page
        parent_path = self._resolve_parents(page)

        return InventoryItem(
            type="handbook_text",
            slug=slug,
            title_en=title,
            url_en=normalize(normalized_url),
            parent_path=parent_path,
            draft_original=False,  # we only request status=publish for handbook
        )

    # ---------------------------------------------------------------- helpers

    def _resolve_parents(self, page: dict[str, Any]) -> list[str]:
        """Return slugs of all ancestors (top → direct parent), excluding the page itself."""
        chain: list[str] = []
        parent_id = page.get("parent") or 0
        while parent_id:
            parent_page = self._fetch_by_id(parent_id)
            if not parent_page:
                break
            chain.append(parent_page["slug"])
            parent_id = parent_page.get("parent") or 0
        chain.reverse()
        return chain

    def _fetch_by_id(self, page_id: int) -> dict[str, Any] | None:
        if page_id in self._page_by_id:
            return self._page_by_id[page_id]
        try:
            page = self._get_json(
                f"{LIST_URL}/{page_id}",
                params={"_fields": "id,slug,title,parent"},
            )
        except InventoryError as exc:
            LOG.warning("Could not fetch handbook page id=%s: %s", page_id, exc)
            return None
        self._page_by_id[page_id] = page
        return page
