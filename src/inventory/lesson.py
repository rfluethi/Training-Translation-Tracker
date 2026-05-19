"""Lesson inventory source.

Looks up a lesson by slug via the WP REST API and resolves its position in
the course structure via the Sensei-internal endpoint.

Endpoints (see API-Befunde.md):
  - GET /wp-json/wp/v2/lessons?slug={slug}&_fields=id,slug,title,status
  - GET /wp-json/wp/v2/courses?_fields=id,slug,title,learning-pathway,...
  - GET /wp-json/sensei-internal/v1/course-structure/{course_id}
"""

from __future__ import annotations

import logging
import re
from typing import Any

from .base import InventoryError, InventoryItem, InventorySource
from .url_normalizer import normalize

LOG = logging.getLogger(__name__)

LESSON_LIST_URL = "https://learn.wordpress.org/wp-json/wp/v2/lessons"
COURSE_LIST_URL = "https://learn.wordpress.org/wp-json/wp/v2/courses"
COURSE_STRUCTURE_URL = (
    "https://learn.wordpress.org/wp-json/sensei-internal/v1/course-structure/{id}"
)

# A lesson URL on learn.wordpress.org has the form:
#   https://learn.wordpress.org/lesson/{slug}/
_LESSON_URL_RE = re.compile(r"^https://learn\.wordpress\.org/lesson/([a-z0-9\-]+)/$")


class LessonInventorySource(InventorySource):
    """Resolves a lesson URL to an InventoryItem with parent_path = [course, section]."""

    def __init__(self, session=None) -> None:
        super().__init__(session)
        # Lazy lookup map slug → (course_slug, section_slug | None) built on first call.
        self._lesson_index: dict[str, tuple[str, str | None]] | None = None

    # ----------------------------------------------------------------- public

    def fetch(self, normalized_url: str) -> InventoryItem:
        slug = self._extract_slug(normalized_url)

        # Step 1: fetch the lesson itself for title and (publish) status.
        # NB: anonymous requests cannot query non-publish posts — the
        # WP REST API returns HTTP 400 if `status=draft` is included.
        # As a result, drafts are invisible to this Action; `draft_original`
        # is always False until we add authentication (future phase).
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

        # Step 2: locate the lesson in the course structure.
        index = self._get_lesson_index()
        course_slug, section_slug = index.get(slug, (None, None))
        parent_path = [s for s in (course_slug, section_slug) if s]

        return InventoryItem(
            type="lesson",
            slug=slug,
            title_en=title,
            url_en=normalize(normalized_url),
            parent_path=parent_path,
            draft_original=(lesson.get("status") == "draft"),
        )

    # ---------------------------------------------------------------- helpers

    @staticmethod
    def _extract_slug(url: str) -> str:
        match = _LESSON_URL_RE.match(url)
        if not match:
            raise InventoryError(f"Not a lesson URL: {url!r}")
        return match.group(1)

    def _get_lesson_index(self) -> dict[str, tuple[str, str | None]]:
        """Build slug → (course_slug, section_slug) map. Cached per instance.

        On any inventory error during index construction, returns an empty
        index and caches that empty result. This keeps the lesson itself
        usable (with empty parent_path) and prevents N more retry storms
        for each subsequent lesson lookup.
        """
        if self._lesson_index is not None:
            return self._lesson_index

        try:
            courses = self._fetch_all_courses()
        except InventoryError as exc:
            LOG.warning(
                "Could not build lesson index (course list unreachable): %s. "
                "Lessons will be returned without parent_path.", exc,
            )
            self._lesson_index = {}
            return self._lesson_index

        index: dict[str, tuple[str, str | None]] = {}

        for course in courses:
            course_id = course["id"]
            course_slug = course["slug"]
            try:
                structure = self._get_json(
                    COURSE_STRUCTURE_URL.format(id=course_id)
                )
            except InventoryError as exc:
                LOG.warning("Could not fetch structure for course %s: %s", course_slug, exc)
                continue

            self._collect_lessons(structure, course_slug, parent_section=None, index=index)

        self._lesson_index = index
        return index

    def _fetch_all_courses(self) -> list[dict[str, Any]]:
        """Paginate through all courses on learn.wordpress.org."""
        out: list[dict[str, Any]] = []
        page = 1
        while True:
            chunk = self._get_json(
                COURSE_LIST_URL,
                params={
                    "_fields": "id,slug",
                    "per_page": 100,
                    "page": page,
                },
            )
            if not chunk:
                break
            out.extend(chunk)
            if len(chunk) < 100:
                break
            page += 1
        return out

    def _collect_lessons(
        self,
        structure: dict[str, Any] | list[Any] | None,
        course_slug: str,
        parent_section: str | None,
        index: dict[str, tuple[str, str | None]],
    ) -> None:
        """Walk a course-structure tree and register every lesson it contains."""
        if not structure:
            return

        # The endpoint returns either a list at the top level, or an object
        # with "lessons" and "modules" keys. Tolerate both shapes.
        if isinstance(structure, list):
            for node in structure:
                self._collect_node(node, course_slug, parent_section, index)
            return

        for node in structure.get("lessons", []) or []:
            self._collect_node(node, course_slug, parent_section, index)

        for module in structure.get("modules", []) or []:
            section_slug = (
                module.get("slug")
                or _slugify(module.get("title", ""))
                or parent_section
            )
            for node in module.get("lessons", []) or []:
                self._collect_node(node, course_slug, section_slug, index)

    def _collect_node(
        self,
        node: dict[str, Any],
        course_slug: str,
        section_slug: str | None,
        index: dict[str, tuple[str, str | None]],
    ) -> None:
        node_type = node.get("type", "lesson")
        if node_type != "lesson":
            return
        slug = node.get("slug")
        if not slug:
            return
        # First registration wins so that earlier (= more authoritative) courses are kept.
        index.setdefault(slug, (course_slug, section_slug))


def _slugify(text: str) -> str:
    """Loose slug fallback for module names that have no slug field."""
    text = (text or "").strip().lower()
    return re.sub(r"[^a-z0-9]+", "-", text).strip("-")
