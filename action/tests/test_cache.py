"""Tests for the inventory cache load/save round-trip."""

import json

from src.inventory.base import InventoryItem
from src.inventory.cache import CACHE_SCHEMA_VERSION, load_cache, save_cache


def test_round_trip(tmp_path):
    path = tmp_path / "inventory-cache.json"
    items = {
        "https://learn.wordpress.org/lesson/what-is-wordpress/": InventoryItem(
            type="lesson",
            slug="what-is-wordpress",
            title_en="What is WordPress",
            url_en="https://learn.wordpress.org/lesson/what-is-wordpress/",
            parent_path=["beginner-wordpress-user", "getting-started"],
        ),
        "https://learn.wordpress.org/lesson-plan/foo/": InventoryItem(
            type="lesson_plan",
            slug="foo",
            title_en="Foo",
            url_en="https://learn.wordpress.org/lesson-plan/foo/",
            parent_path=[],
        ),
    }

    save_cache(path, items)
    loaded = load_cache(path)

    assert set(loaded.keys()) == set(items.keys())
    assert loaded["https://learn.wordpress.org/lesson/what-is-wordpress/"].title_en == "What is WordPress"
    assert loaded["https://learn.wordpress.org/lesson/what-is-wordpress/"].parent_path == ["beginner-wordpress-user", "getting-started"]
    assert loaded["https://learn.wordpress.org/lesson-plan/foo/"].parent_path == []


def test_missing_file_returns_empty(tmp_path):
    path = tmp_path / "nope.json"
    assert load_cache(path) == {}


def test_wrong_schema_version_returns_empty(tmp_path):
    path = tmp_path / "bad.json"
    path.write_text(json.dumps({
        "schema_version": 99,
        "items": {},
    }))
    assert load_cache(path) == {}


def test_malformed_json_returns_empty(tmp_path):
    path = tmp_path / "broken.json"
    path.write_text("not json")
    assert load_cache(path) == {}


def test_malformed_entry_is_skipped(tmp_path):
    path = tmp_path / "partial.json"
    path.write_text(json.dumps({
        "schema_version": CACHE_SCHEMA_VERSION,
        "items": {
            "https://good.example.com/x/": {
                "type": "lesson",
                "slug": "x",
                "title_en": "X",
                "url_en": "https://good.example.com/x/",
            },
            "https://bad.example.com/y/": {
                "type": "lesson",
                # missing slug, title_en, url_en
            },
        },
    }))
    loaded = load_cache(path)
    assert "https://good.example.com/x/" in loaded
    assert "https://bad.example.com/y/" not in loaded


def test_optional_fields_round_trip(tmp_path):
    path = tmp_path / "optional.json"
    items = {
        "https://learn.wordpress.org/lesson/x/": InventoryItem(
            type="lesson",
            slug="x",
            title_en="X",
            url_en="https://learn.wordpress.org/lesson/x/",
            parent_path=["c", "s"],
            url_wptv="https://wordpress.tv/2024/x/",
            url_youtube="https://www.youtube.com/watch?v=abc",
            draft_original=True,
        ),
    }
    save_cache(path, items)
    loaded = load_cache(path)
    item = loaded["https://learn.wordpress.org/lesson/x/"]
    assert item.url_wptv == "https://wordpress.tv/2024/x/"
    assert item.url_youtube == "https://www.youtube.com/watch?v=abc"
    assert item.draft_original is True


def test_save_creates_pretty_diffable_json(tmp_path):
    """Verify the saved file is line-broken so PR diffs are reviewable."""
    path = tmp_path / "pretty.json"
    items = {
        "https://learn.wordpress.org/lesson/x/": InventoryItem(
            type="lesson", slug="x", title_en="X",
            url_en="https://learn.wordpress.org/lesson/x/", parent_path=[],
        ),
    }
    save_cache(path, items)
    content = path.read_text()
    assert "\n" in content
    # Should end with a final newline (common convention).
    assert content.endswith("\n")
