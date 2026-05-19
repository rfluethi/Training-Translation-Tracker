"""Retry logic for HTTP 429 in InventorySource._get_json()."""

import pytest

from src.inventory import base as base_mod
from src.inventory.base import InventoryError
from src.inventory.lesson_plan import LessonPlanInventorySource
from tests._fakes import FakeSession


@pytest.fixture(autouse=True)
def _silence_sleep(monkeypatch):
    """Make time.sleep() a no-op so tests don't actually wait."""
    monkeypatch.setattr(base_mod.time, "sleep", lambda _s: None)


def test_429_then_success():
    """Two 429 responses, then a 200 — should succeed transparently."""
    fake = FakeSession()
    fake.register_sequence(
        "https://learn.wordpress.org/wp-json/wp/v2/lesson-plan",
        [
            (None, 429),
            (None, 429),
            (
                [{"id": 1, "slug": "x", "title": {"rendered": "X"}, "status": "publish"}],
                200,
            ),
        ],
        params={"slug": "x", "_fields": "id,slug,title,status"},
    )

    source = LessonPlanInventorySource(session=fake)
    item = source.fetch("https://learn.wordpress.org/lesson-plan/x/")

    assert item.slug == "x"
    assert item.title_en == "X"
    # The fake recorded three GETs to the same URL — the retries.
    assert len(fake.calls) == 3


def test_429_exhausts_retries():
    """When 429 persists, after HTTP_429_MAX_RETRIES the error escalates."""
    fake = FakeSession()
    fake.register(
        "https://learn.wordpress.org/wp-json/wp/v2/lesson-plan",
        None,
        params={"slug": "y", "_fields": "id,slug,title,status"},
        status=429,
    )

    source = LessonPlanInventorySource(session=fake)
    with pytest.raises(InventoryError) as ei:
        source.fetch("https://learn.wordpress.org/lesson-plan/y/")
    assert "429" in str(ei.value)
    assert len(fake.calls) == base_mod.HTTP_429_MAX_RETRIES


def test_429_respects_retry_after_header(monkeypatch):
    """If the response has a Retry-After header, that value is used for the wait."""
    sleeps: list[float] = []
    monkeypatch.setattr(base_mod.time, "sleep", sleeps.append)

    fake = FakeSession()
    fake._routes[(
        "https://learn.wordpress.org/wp-json/wp/v2/lesson-plan",
        frozenset((("slug", "z"), ("_fields", "id,slug,title,status"))),
    )] = [
        (None, 429, {"Retry-After": "7"}),
        ([{"id": 1, "slug": "z", "title": {"rendered": "Z"}, "status": "publish"}], 200, {}),
    ]

    source = LessonPlanInventorySource(session=fake)
    item = source.fetch("https://learn.wordpress.org/lesson-plan/z/")
    assert item.slug == "z"
    # Exactly one sleep call, value taken from Retry-After (7 seconds).
    assert sleeps == [7.0]
