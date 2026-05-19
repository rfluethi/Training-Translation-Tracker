"""Lesson inventory source.

Looks up a lesson by slug via the WP REST API. Returns title + url only;
`parent_path` is left empty by design — see HISTORY note below.

Endpoint (see API-Befunde.md §1.2):
  GET /wp-json/wp/v2/lessons?slug={slug}&_fields=id,slug,title,status

HISTORY: An earlier version of this module also resolved each lesson's
position in the Sensei course structure (`parent_path = [course, section]`)
by paginating /wp-json/wp/v2/courses and calling
/wp-json/sensei-internal/v1/course-structure/{course_id} for each course.
That mechanism was removed (May 2026) because:

  - learn.wordpress.org rate-limits these endpoints hard enough that
    even from a residential IP, every single course-structure call hit
    HTTP 429 — so the populated hierarchy was always empty anyway.
  - Even when it works, it costs ~30 API calls just to find the parent
    of the FIRST lesson, which is wasteful given how seldom WP's course
    structure changes.
  - If pathway-level grouping ever becomes important, the cleaner design
    is to encode it explicitly in scope.yml (or a sibling file) — that
    matches what the DACH team actually wants to display, independent of
    WP's internal layout. See Arbeitsplan §A.1.6 (v2 ideas).
"""

from __future__ import annotations

import re

from .base import InventoryError, InventoryItem, InventorySource
from .url_normalizer import normalize

LESSON_LIST_URL = "https://learn.wordpress.org/wp-json/wp/v2/lessons"

# A lesson URL on learn.wordpress.org has the form:
#   https://learn.wordpress.org/lesson/{slug}/
_LESSON_URL_RE = re.compile(r"^https://learn\.wordpress\.org/lesson/([a-z0-9\-]+)/$")


class LessonInventorySource(InventorySource):
    """Resolves a lesson URL to an InventoryItem (title + slug + url, flat)."""

    def fetch(self, normalized_url: str) -> InventoryItem:
        slug = self._extract_slug(normalized_url)

        # Anonymous requests can't query drafts — see Arbeitsplan §A.1.5 / API-Befunde §1.5.
        items = self._get_json(
            LESSON_LIST_URL,
            params={
                "slug": slug,
                "_fields": "id,slug,title,status",
            },
        )
        if not items:
            raise InventoryError(f"No lesson with slug {slug!r}")
        lesson = items[0]
        title = (lesson.get("title", {}) or {}).get("rendered", "") or slug

        return InventoryItem(
            type="lesson",
            slug=slug,
            title_en=title,
            url_en=normalize(normalized_url),
            parent_path=[],
            draft_original=False,
        )

    @staticmethod
    def _extract_slug(url: str) -> str:
        match = _LESSON_URL_RE.match(url)
        if not match:
            raise InventoryError(f"Not a lesson URL: {url!r}")
        return match.group(1)
