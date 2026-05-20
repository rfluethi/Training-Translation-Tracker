"""Tutorial inventory source.

Tutorials live at /tutorial/{slug}/ on learn.wordpress.org. Internally they
are the CPT `wporg_workshop` — "Tutorial" and "Online Workshop" map to the
same content type (see API-Befunde.md §1.3).

Endpoint: GET /wp-json/wp/v2/wporg_workshop?slug={slug}&_fields=id,slug,title,status,link
"""

from __future__ import annotations

import re

from .base import InventoryError, InventoryItem, InventorySource
from .url_normalizer import normalize

LIST_URL = "https://learn.wordpress.org/wp-json/wp/v2/wporg_workshop"

_URL_RE = re.compile(r"^https://learn\.wordpress\.org/tutorial/([a-z0-9\-]+)/$")


class TutorialInventorySource(InventorySource):
    def fetch(self, normalized_url: str) -> InventoryItem:
        match = _URL_RE.match(normalized_url)
        if not match:
            raise InventoryError(f"Not a tutorial URL: {normalized_url!r}")
        slug = match.group(1)

        # Anonymous requests can't query drafts — see lesson.py for the reasoning.
        items = self._get_json(
            LIST_URL,
            params={
                "slug": slug,
                "_fields": "id,slug,title,status",
            },
        )
        if not items:
            raise InventoryError(f"No tutorial with slug {slug!r}")
        item = items[0]
        title = (item.get("title", {}) or {}).get("rendered", "") or slug

        return InventoryItem(
            type="tutorial",
            slug=slug,
            title_en=title,
            url_en=normalize(normalized_url),
            parent_path=[],
            draft_original=(item.get("status") == "draft"),
        )
