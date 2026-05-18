"""Lesson Plan inventory source.

Lesson Plans live at /lesson-plan/{slug}/ on learn.wordpress.org and are
registered by the wporg-learn plugin (no separate hierarchy — flat list).

Endpoint: GET /wp-json/wp/v2/lesson-plan?slug={slug}&_fields=id,slug,title,status
"""

from __future__ import annotations

import re

from .base import InventoryError, InventoryItem, InventorySource
from .url_normalizer import normalize

LIST_URL = "https://learn.wordpress.org/wp-json/wp/v2/lesson-plan"

_URL_RE = re.compile(r"^https://learn\.wordpress\.org/lesson-plan/([a-z0-9\-]+)/$")


class LessonPlanInventorySource(InventorySource):
    def fetch(self, normalized_url: str) -> InventoryItem:
        match = _URL_RE.match(normalized_url)
        if not match:
            raise InventoryError(f"Not a lesson-plan URL: {normalized_url!r}")
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
            raise InventoryError(f"No lesson-plan with slug {slug!r}")
        item = items[0]
        title = (item.get("title", {}) or {}).get("rendered", "") or slug

        return InventoryItem(
            type="lesson_plan",
            slug=slug,
            title_en=title,
            url_en=normalize(normalized_url),
            parent_path=[],
            draft_original=(item.get("status") == "draft"),
        )
