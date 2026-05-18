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

def test_lesson_source_resolves_title_and_course():
    fake = FakeSession()
    # 1) Lesson lookup
    fake.register(
        "https://learn.wordpress.org/wp-json/wp/v2/lessons",
        [
            {
                "id": 101,
                "slug": "what-is-wordpress",
                "title": {"rendered": "What is WordPress"},
                "status": "publish",
            }
        ],
        params={
            "slug": "what-is-wordpress",
            "status": "publish,draft",
            "_fields": "id,slug,title,status",
        },
    )
    # 2) All courses
    fake.register(
        "https://learn.wordpress.org/wp-json/wp/v2/courses",
        [{"id": 1, "slug": "beginner-wordpress-user"}],
        params={"_fields": "id,slug", "per_page": 100, "page": 1},
    )
    # 3) Course structure for course id 1
    fake.register(
        "https://learn.wordpress.org/wp-json/sensei-internal/v1/course-structure/1",
        {
            "lessons": [],
            "modules": [
                {
                    "id": 10,
                    "title": "Getting Started",
                    "slug": "getting-started",
                    "lessons": [
                        {
                            "id": 101,
                            "slug": "what-is-wordpress",
                            "title": "What is WordPress",
                            "type": "lesson",
                        }
                    ],
                }
            ],
        },
    )

    source = LessonInventorySource(session=fake)
    item = source.fetch("https://learn.wordpress.org/lesson/what-is-wordpress/")

    assert item.type == "lesson"
    assert item.slug == "what-is-wordpress"
    assert item.title_en == "What is WordPress"
    assert item.url_en == "https://learn.wordpress.org/lesson/what-is-wordpress/"
    assert item.parent_path == ["beginner-wordpress-user", "getting-started"]
    assert item.draft_original is False


def test_lesson_source_draft_status_is_detected():
    fake = FakeSession()
    fake.register(
        "https://learn.wordpress.org/wp-json/wp/v2/lessons",
        [{"id": 42, "slug": "secret", "title": {"rendered": "Secret"}, "status": "draft"}],
        params={
            "slug": "secret",
            "status": "publish,draft",
            "_fields": "id,slug,title,status",
        },
    )
    fake.register(
        "https://learn.wordpress.org/wp-json/wp/v2/courses",
        [],
        params={"_fields": "id,slug", "per_page": 100, "page": 1},
    )

    source = LessonInventorySource(session=fake)
    item = source.fetch("https://learn.wordpress.org/lesson/secret/")

    assert item.draft_original is True
    assert item.parent_path == []


def test_lesson_source_missing_slug_raises():
    fake = FakeSession()
    fake.register(
        "https://learn.wordpress.org/wp-json/wp/v2/lessons",
        [],
        params={
            "slug": "nope",
            "status": "publish,draft",
            "_fields": "id,slug,title,status",
        },
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
            "status": "publish,draft",
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
            "status": "publish,draft",
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
        params={
            "slug": "x",
            "status": "publish,draft",
            "_fields": "id,slug,title,status",
        },
    )
    fake.register(
        "https://learn.wordpress.org/wp-json/wp/v2/courses",
        [],
        params={"_fields": "id,slug", "per_page": 100, "page": 1},
    )

    dispatcher = Dispatcher(session=fake)
    item = dispatcher.fetch("HTTPS://Learn.WordPress.Org/lesson/x")  # not canonical on input
    assert item.type == "lesson"
    assert item.slug == "x"
    # Dispatcher must normalize on the way in:
    assert item.url_en == "https://learn.wordpress.org/lesson/x/"
