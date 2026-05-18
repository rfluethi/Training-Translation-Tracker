"""Base types for inventory sources.

Each item type lives in its own module (`lesson.py`, `lesson_plan.py`, ...).
All modules return `InventoryItem` instances via a uniform `InventorySource.fetch()`.
"""

from __future__ import annotations

import logging
from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from typing import Any

import requests

LOG = logging.getLogger(__name__)

# Item-type values must match the enum in schemas/tracker.schema.json.
ItemType = str  # one of: lesson | lesson_plan | tutorial | handbook_text | handbook_video


@dataclass
class InventoryItem:
    """A single piece of translatable content as known to the inventory side.

    Issue-derived fields (translation URL, components, etc.) are added later
    by the builder/joiner — they do not belong on this dataclass.
    """

    type: ItemType
    slug: str
    title_en: str
    url_en: str               # canonical, see url_normalizer.normalize()
    parent_path: list[str] = field(default_factory=list)  # pathway / course / section slugs (top → bottom)
    url_wptv: str | None = None
    url_youtube: str | None = None
    draft_original: bool = False

    def to_minimal_dict(self) -> dict[str, Any]:
        """Return only the fields that belong in the tracker.json item object."""
        out: dict[str, Any] = {
            "type": self.type,
            "slug": self.slug,
            "title_en": self.title_en,
            "url_en": self.url_en,
        }
        if self.url_wptv:
            out["url_wptv"] = self.url_wptv
        if self.url_youtube:
            out["url_youtube"] = self.url_youtube
        if self.draft_original:
            out["draft_original"] = True
        return out


class InventoryError(Exception):
    """Raised when an inventory source cannot resolve an item."""


class InventorySource(ABC):
    """Abstract base. Subclasses are wired up in `dispatcher.py`."""

    # User-Agent for all HTTP calls — easy to spot in upstream access logs.
    USER_AGENT = (
        "Training-Translation-Tracker-Inventory/0.1 "
        "(+https://github.com/rfluethi/Training-Translation-Tracker-Inventory-Plugin)"
    )

    def __init__(self, session: requests.Session | None = None) -> None:
        self.session = session or requests.Session()
        self.session.headers.setdefault("User-Agent", self.USER_AGENT)
        self.session.headers.setdefault("Accept", "application/json")

    @abstractmethod
    def fetch(self, normalized_url: str) -> InventoryItem:
        """Return the inventory item for the given canonical URL.

        Raise InventoryError on 404, on unparseable responses, or on
        any condition where no item can be returned.
        """

    # ------------------------------------------------------------------ helpers

    def _get_json(self, url: str, *, params: dict[str, Any] | None = None) -> Any:
        """GET with sane defaults. Returns parsed JSON or raises InventoryError."""
        try:
            resp = self.session.get(url, params=params, timeout=15)
        except requests.RequestException as exc:
            raise InventoryError(f"GET {url} failed: {exc}") from exc

        if resp.status_code == 404:
            raise InventoryError(f"GET {url} → 404")
        if resp.status_code >= 400:
            raise InventoryError(f"GET {url} → HTTP {resp.status_code}")

        try:
            return resp.json()
        except ValueError as exc:
            raise InventoryError(f"GET {url} returned non-JSON body") from exc
