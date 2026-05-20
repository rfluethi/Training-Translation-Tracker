"""Inventory module tests with mocked HTTP."""

import pytest

from src.inventory.dispatcher import Dispatcher, classify
from src.inventory.base import InventoryError
from src.inventory.handbook import HandbookInventorySource
from src.inventory.lesson import LessonInventorySource
from src.inventory.lesson_plan import LessonPlanInventorySource
from src.inventory.tutorial import TutorialInventorySource
from tests._fakes import FakeSession


# ---------------------------------------------------------------------------
# Dispatcher classification
# ---------------------------------------------------------------------------

@pytest.mark.parametrize(
    "url, kind",
    [
        ("https://learn.wordpress.org/lesson/foo/", "lesson"),
        ("https://learn.wordpress.org/lesson-plan/foo/", "lesson_plan"),
        ("https://learn.wordpress.org/tutorial/foo/", "tutorial"),
        ("https://make.wordpress.org/training/handbook/foo/", "handbook"),
        ("https://make.wordpress.org/training/handbook/section/page/", "handbook"),
    ],
)
def test_dispatcher_classify(url, kind):
    assert classify(url) == kind


def test_dispatcher_rejects_unknown_url():
    with pytest.raises(InventoryError):
        classify("https://example.com/foo/")


# ---------------------------------------------------------------------------
# Lesson source — happy path with course-structure lookup
# ---------------------------------------------------------------------------

def test_lesson_source_resolves_title():
    """LessonInventorySource hits exactly one endpoint and returns a flat item.

    By design (May 2026), hierarchy resolution via /courses + course-structure
    was removed — see lesson.py docstring. parent_path is always empty.
    """
    fake = FakeSession()
    fake.register(
        "https://learn.wordpress.org/wp-json/wp/v2/lessons",
        [{"id": 101, "slug": "what-is-wordpress", "title": {"rendered": "What is WordPress"}, "status": "publish"}],
        params={"slug": "what-is-wordpress", "_fields": "id,slug,title,status"},
    )

    source = LessonInventorySource(session=fake)
    item = source.fetch("https://learn.wordpress.org/lesson/what-is-wordpress/")

    assert item.type == "lesson"
    assert item.slug == "what-is-wordpress"
    assert item.title_en == "What is WordPress"
    assert item.url_en == "https://learn.wordpress.org/lesson/what-is-wordpress/"
    assert item.parent_path == []
    assert item.draft_original is False
    # Exactly one GET — no /courses, no /course-structure spam.
    assert len(fake.calls) == 1


def test_lesson_source_missing_slug_raises():
    fake = FakeSession()
    fake.register(
        "https://learn.wordpress.org/wp-json/wp/v2/lessons",
        [],
        params={"slug": "nope", "_fields": "id,slug,title,status"},
    )

    source = LessonInventorySource(session=fake)
    with pytest.raises(InventoryError):
        source.fetch("https://learn.wordpress.org/lesson/nope/")


# ---------------------------------------------------------------------------
# Lesson plan source
# ---------------------------------------------------------------------------

def test_lesson_plan_source():
    fake = FakeSession()
    fake.register(
        "https://learn.wordpress.org/wp-json/wp/v2/lesson-plan",
        [{"id": 5, "slug": "intro-to-blocks", "title": {"rendered": "Intro to Blocks"}, "status": "publish"}],
        params={
            "slug": "intro-to-blocks",
            "_fields": "id,slug,title,status",
        },
    )

    source = LessonPlanInventorySource(session=fake)
    item = source.fetch("https://learn.wordpress.org/lesson-plan/intro-to-blocks/")
    assert item.type == "lesson_plan"
    assert item.slug == "intro-to-blocks"
    assert item.title_en == "Intro to Blocks"
    assert item.parent_path == []


# ---------------------------------------------------------------------------
# Tutorial source
# ---------------------------------------------------------------------------

def test_tutorial_source():
    fake = FakeSession()
    fake.register(
        "https://learn.wordpress.org/wp-json/wp/v2/wporg_workshop",
        [{"id": 9, "slug": "css-flexbox", "title": {"rendered": "CSS Flexbox"}, "status": "publish"}],
        params={
            "slug": "css-flexbox",
            "_fields": "id,slug,title,status",
        },
    )

    source = TutorialInventorySource(session=fake)
    item = source.fetch("https://learn.wordpress.org/tutorial/css-flexbox/")
    assert item.type == "tutorial"
    assert item.slug == "css-flexbox"
    assert item.title_en == "CSS Flexbox"


# ---------------------------------------------------------------------------
# Handbook source — with parent chain
# ---------------------------------------------------------------------------

def test_handbook_source_walks_parents():
    fake = FakeSession()
    fake.register(
        "https://make.wordpress.org/training/wp-json/wp/v2/handbook",
        [
            {
                "id": 50,
                "slug": "welcome",
                "title": {"rendered": "Welcome"},
                "parent": 40,
                "status": "publish",
            }
        ],
        params={
            "slug": "welcome",
            "status": "publish",
            "_fields": "id,slug,title,parent,link,status",
        },
    )
    fake.register(
        "https://make.wordpress.org/training/wp-json/wp/v2/handbook/40",
        {"id": 40, "slug": "getting-started", "title": {"rendered": "Getting Started"}, "parent": 0},
        params={"_fields": "id,slug,title,parent"},
    )

    source = HandbookInventorySource(session=fake)
    item = source.fetch("https://make.wordpress.org/training/handbook/getting-started/welcome/")

    assert item.type == "handbook_text"
    assert item.slug == "welcome"
    assert item.title_en == "Welcome"
    assert item.parent_path == ["getting-started"]


def test_handbook_source_top_level_page():
    fake = FakeSession()
    fake.register(
        "https://make.wordpress.org/training/wp-json/wp/v2/handbook",
        [{"id": 10, "slug": "overview", "title": {"rendered": "Overview"}, "parent": 0, "status": "publish"}],
        params={
            "slug": "overview",
            "status": "publish",
            "_fields": "id,slug,title,parent,link,status",
        },
    )

    source = HandbookInventorySource(session=fake)
    item = source.fetch("https://make.wordpress.org/training/handbook/overview/")
    assert item.parent_path == []


# ---------------------------------------------------------------------------
# Dispatcher integration — picks the right module
# ---------------------------------------------------------------------------

def test_dispatcher_routes_to_lesson_module():
    fake = FakeSession()
    fake.register(
        "https://learn.wordpress.org/wp-json/wp/v2/lessons",
        [{"id": 7, "slug": "x", "title": {"rendered": "X"}, "status": "publish"}],
        params={"slug": "x", "_fields": "id,slug,title,status"},
    )

    dispatcher = Dispatcher(session=fake)
    item = dispatcher.fetch("HTTPS://Learn.WordPress.Org/lesson/x")  # not canonical on input
    assert item.type == "lesson"
    assert item.slug == "x"
    # Dispatcher must normalize on the way in:
    assert item.url_en == "https://learn.wordpress.org/lesson/x/"
