"""Builder tests: overall_status algorithm, joiner, stats, schema validation of output."""

import json
from pathlib import Path

import pytest
from jsonschema import Draft202012Validator

from src.builder.joiner import build_groups, calculate_overall_status
from src.builder.stats import calculate_stats
from src.github.issues import ParsedIssue
from src.github.parser import ComponentStatus, IssueBody
from src.github.issues import RawIssue
from src.inventory.base import InventoryItem


REPO_ROOT = Path(__file__).resolve().parent.parent
SCHEMA = json.loads((REPO_ROOT / "schemas" / "tracker.schema.json").read_text())

COMPONENT_TEMPLATES = {
    "lesson": ["text", "thumbnails", "video", "subtitles", "quiz", "exercise", "audio"],
    "lesson_plan": ["text", "thumbnails"],
    "tutorial": ["text", "thumbnails", "video", "subtitles"],
    "handbook_text": ["text"],
    "handbook_video": ["text", "thumbnails", "video", "subtitles"],
}


# ---------------------------------------------------------------------------
# overall_status (work plan, section A.2.1)
# ---------------------------------------------------------------------------

@pytest.mark.parametrize(
    "statuses, expected",
    [
        (["na", "na", "na"], "na"),
        (["done", "done", "done"], "done"),
        (["done", "done", "na"], "done"),  # all non-na are done
        (["done", "wip"], "wip"),
        (["done", "review"], "review"),
        (["review", "wip"], "review"),
        (["open", "open"], "open"),
        (["open", "wip"], "wip"),
        ([], "open"),  # empty → open (safe default)
    ],
)
def test_calculate_overall_status(statuses, expected):
    assert calculate_overall_status(statuses) == expected


# ---------------------------------------------------------------------------
# Joiner — happy path
# ---------------------------------------------------------------------------

def _inventory_item(slug="what-is-wordpress", parent=("beginner-wordpress-user", "getting-started")):
    return InventoryItem(
        type="lesson",
        slug=slug,
        title_en=slug.replace("-", " ").title(),
        url_en=f"https://learn.wordpress.org/lesson/{slug}/",
        parent_path=list(parent),
    )


def _issue(url_orig, components=None, number=1, url_translated=""):
    body = IssueBody(
        url_original=url_orig,
        url_translated=url_translated,
        components=components or [],
    )
    raw = RawIssue(
        number=number,
        url=f"https://github.com/WordPress/Learn/issues/{number}",
        state="OPEN",
        title=f"Issue {number}",
        body="",
        repository="WordPress/Learn",
        assignees=["rfluethi"],
    )
    return ParsedIssue(raw=raw, parsed=body, normalized_original=url_orig)


def test_joiner_uses_scope_hierarchy():
    """scope.yml's `pathways` tree maps URLs to pathway/course/section labels."""
    inv = [
        _inventory_item("introduction-to-wordpress-2"),
        _inventory_item("using-the-media-library-2"),
    ]
    scope_config = {
        "locale": "German",
        "locale_short": "de",
        "pathways": [
            {
                "slug": "user",
                "label": "User Learning Pathway",
                "courses": [
                    {
                        "slug": "beginner",
                        "label": "Beginner WordPress User",
                        "sections": [
                            {
                                "slug": "intro",
                                "label": "Get Started",
                                "items": ["https://learn.wordpress.org/lesson/introduction-to-wordpress-2/"],
                            },
                            {
                                "slug": "interface",
                                "label": "Gain a familiarity with the WordPress Interface",
                                "items": ["https://learn.wordpress.org/lesson/using-the-media-library-2/"],
                            },
                        ],
                    }
                ],
            }
        ]
    }

    result = build_groups(inv, [], COMPONENT_TEMPLATES, scope_config)

    pathways = [g for g in result.groups if g["type"] == "pathway"]
    assert len(pathways) == 1
    pathway = pathways[0]
    assert pathway["slug"] == "user"
    assert pathway["label"] == "User Learning Pathway"
    assert len(pathway["courses"]) == 1

    sections = pathway["courses"][0]["sections"]
    section_labels = [s["label"] for s in sections]
    assert "Get Started" in section_labels
    assert "Gain a familiarity with the WordPress Interface" in section_labels


def test_joiner_url_outside_hierarchy_falls_back():
    """An inventory URL that isn't placed anywhere in scope.yml lands under 'Unassigned'."""
    inv = [_inventory_item("not-mapped")]
    scope_config = {"locale": "German", "locale_short": "de", "pathways": []}

    result = build_groups(inv, [], COMPONENT_TEMPLATES, scope_config)

    pathways = [g for g in result.groups if g["type"] == "pathway"]
    assert len(pathways) == 1
    assert pathways[0]["label"] == "Unassigned"


def test_joiner_without_scope_config():
    """Backwards compatibility: build_groups still works without scope_config."""
    inv = [_inventory_item()]
    result = build_groups(inv, [], COMPONENT_TEMPLATES)
    pathways = [g for g in result.groups if g["type"] == "pathway"]
    assert len(pathways) == 1


def test_joiner_matches_item_with_issue():
    inv = [_inventory_item()]
    issues = [
        _issue(
            "https://learn.wordpress.org/lesson/what-is-wordpress/",
            [
                ComponentStatus("text", "done"),
                ComponentStatus("thumbnails", "wip"),
            ],
            number=42,
        )
    ]
    result = build_groups(inv, issues, COMPONENT_TEMPLATES)

    # Must produce exactly one pathway group
    pathways = [g for g in result.groups if g["type"] == "pathway"]
    assert len(pathways) == 1
    item = pathways[0]["courses"][0]["sections"][0]["items"][0]

    assert item["overall_status"] == "wip"
    assert item["issue"]["number"] == 42
    assert len(item["components"]) == 2


def test_joiner_uses_defaults_when_no_issue():
    """Items without a matching issue fall back to the canonical component
    set, each marked status='unset' (so the frontend can render them as
    neutral icons rather than yellow 'open' icons). Overall rolls up to
    'open' so the item still appears in the open bucket."""
    inv = [_inventory_item()]
    result = build_groups(inv, [], COMPONENT_TEMPLATES)
    item = result.groups[0]["courses"][0]["sections"][0]["items"][0]

    assert item["overall_status"] == "open"
    assert [c["name"] for c in item["components"]] == COMPONENT_TEMPLATES["lesson"]
    assert all(c["status"] == "unset" for c in item["components"])


def test_joiner_creates_orphan_for_unmatched_issue():
    inv = []
    issues = [_issue("https://learn.wordpress.org/lesson/not-in-scope/", number=7)]
    result = build_groups(inv, issues, COMPONENT_TEMPLATES)

    orphans = [g for g in result.groups if g["type"] == "orphan"]
    assert len(orphans) == 1
    assert orphans[0]["items"][0]["orphan_reason"] == "outside_scope"
    assert orphans[0]["items"][0]["slug"] == "not-in-scope"


def test_joiner_detects_duplicate_issues():
    url = "https://learn.wordpress.org/lesson/what-is-wordpress/"
    inv = [_inventory_item()]
    issues = [
        _issue(url, [ComponentStatus("text", "done")], number=1),
        _issue(url, [ComponentStatus("text", "open")], number=2),
    ]
    result = build_groups(inv, issues, COMPONENT_TEMPLATES)

    item = result.groups[0]["courses"][0]["sections"][0]["items"][0]
    assert item["issue"]["number"] == 1
    assert len(item["duplicate_issues"]) == 1
    assert item["duplicate_issues"][0]["number"] == 2
    # Warning was emitted
    assert any("Duplicate" in w for w in result.warnings)


# ---------------------------------------------------------------------------
# Stats
# ---------------------------------------------------------------------------

def test_stats_count_per_overall_status():
    groups = [
        {
            "type": "pathway",
            "slug": "x",
            "label": "X",
            "courses": [
                {
                    "slug": "c",
                    "label": "C",
                    "sections": [
                        {
                            "slug": "s",
                            "label": "S",
                            "items": [
                                {"type": "lesson", "slug": "a", "title_en": "A",
                                 "url_en": "https://learn.wordpress.org/lesson/a/",
                                 "overall_status": "done"},
                                {"type": "lesson", "slug": "b", "title_en": "B",
                                 "url_en": "https://learn.wordpress.org/lesson/b/",
                                 "overall_status": "wip"},
                                {"type": "lesson", "slug": "c", "title_en": "C",
                                 "url_en": "https://learn.wordpress.org/lesson/c/",
                                 "overall_status": "open"},
                            ],
                        }
                    ],
                }
            ],
        },
        {
            "type": "orphan",
            "label": "Other",
            "items": [
                {"type": "lesson", "slug": "z", "title_en": "Z",
                 "url_en": "https://learn.wordpress.org/lesson/z/",
                 "overall_status": "review"},
            ],
        },
    ]
    stats = calculate_stats(groups)
    assert stats == {
        "total_items": 4,
        "done": 1,
        "review": 1,
        "wip": 1,
        "open": 1,
        "na": 0,
        "untouched": 0,
    }


# ---------------------------------------------------------------------------
# Full end-to-end smoke: produced tracker.json validates against the schema
# ---------------------------------------------------------------------------

def test_end_to_end_output_validates_against_schema(tmp_path):
    from src.builder.output import write_outputs

    inv = [
        _inventory_item("what-is-wordpress"),
        _inventory_item("wordpress-com-vs-wordpress-org"),
    ]
    issues = [
        _issue(
            "https://learn.wordpress.org/lesson/what-is-wordpress/",
            [
                ComponentStatus("text", "done", creator="rfluethi", reviewer="Ursha-wp"),
                ComponentStatus("thumbnails", "done"),
                ComponentStatus("video", "wip", creator="rfluethi"),
                ComponentStatus("subtitles", "open"),
            ],
            number=1234,
            url_translated="https://learn.wordpress.org/lesson/was-ist-wordpress/",
        ),
    ]
    result = build_groups(inv, issues, COMPONENT_TEMPLATES)
    stats = calculate_stats(result.groups)

    write_outputs(
        tmp_path,
        schema_version=1,
        generated_at=None,
        scope_version="test-fixture",
        stats=stats,
        groups=result.groups,
        warnings=result.warnings,
    )

    tracker = json.loads((tmp_path / "tracker.json").read_text(encoding="utf-8"))
    Draft202012Validator(SCHEMA).validate(tracker)

    # also sanity-check the report file
    report = (tmp_path / "last-run.md").read_text(encoding="utf-8")
    assert "total_items: 2" in report
