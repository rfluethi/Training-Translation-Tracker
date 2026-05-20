"""Dispatcher: pick the right inventory source for a given URL.

Used by the builder to resolve each scope.yml entry to an InventoryItem.
"""

from __future__ import annotations

import re
import time

import requests

from .base import InventoryError, InventoryItem, InventorySource
from .handbook import HandbookInventorySource
from .lesson import LessonInventorySource
from .lesson_plan import LessonPlanInventorySource
from .tutorial import TutorialInventorySource
from .url_normalizer import normalize

# Order matters: more specific patterns first.
_PATTERNS: list[tuple[re.Pattern[str], str]] = [
    (re.compile(r"^https://learn\.wordpress\.org/lesson-plan/"), "lesson_plan"),
    (re.compile(r"^https://learn\.wordpress\.org/lesson/"), "lesson"),
    (re.compile(r"^https://learn\.wordpress\.org/tutorial/"), "tutorial"),
    (re.compile(r"^https://make\.wordpress\.org/training/handbook/"), "handbook"),
]


def classify(normalized_url: str) -> str:
    """Return the item type for a URL ('lesson', 'lesson_plan', ...).

    Raises InventoryError when no pattern matches.
    """
    for pattern, kind in _PATTERNS:
        if pattern.match(normalized_url):
            return kind
    raise InventoryError(f"No inventory source for URL: {normalized_url!r}")


class Dispatcher:
    """Routes URLs to the correct source. Sources are reused across calls.

    `throttle_s`, if > 0, applies a `time.sleep(throttle_s)` between
    fetch() calls. Used by build.py to back off learn.wordpress.org's
    rate limiter. Default 0 keeps tests fast.
    """

    def __init__(
        self,
        session: requests.Session | None = None,
        throttle_s: float = 0.0,
    ) -> None:
        self.session = session or requests.Session()
        self.throttle_s = max(0.0, float(throttle_s))
        self._sources: dict[str, InventorySource] = {}
        self._first_call = True

    def fetch(self, url: str) -> InventoryItem:
        if self.throttle_s > 0 and not self._first_call:
            time.sleep(self.throttle_s)
        self._first_call = False

        normalized = normalize(url)
        kind = classify(normalized)
        source = self._sources.get(kind)
        if source is None:
            source = self._build(kind)
            self._sources[kind] = source
        return source.fetch(normalized)

    def _build(self, kind: str) -> InventorySource:
        if kind == "lesson":
            return LessonInventorySource(self.session)
        if kind == "lesson_plan":
            return LessonPlanInventorySource(self.session)
        if kind == "tutorial":
            return TutorialInventorySource(self.session)
        if kind == "handbook":
            return HandbookInventorySource(self.session)
        raise InventoryError(f"Unknown inventory kind: {kind!r}")


# Module-level convenience for one-off calls (matches the import surface in __init__.py).
_default = Dispatcher()


def fetch(url: str) -> InventoryItem:
    return _default.fetch(url)
