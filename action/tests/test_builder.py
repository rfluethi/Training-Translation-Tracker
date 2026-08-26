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
        "published": 0,
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


# ---------------------------------------------------------------------------
# Board status leads (0.5.0, status-map.yml)
# ---------------------------------------------------------------------------

STATUS_MAP = {
    "awaiting triage": "open",
    "looking for translator": "open",
    "translation in progress": "wip",
    "preparing to publish": "wip",
    "ready for review": "review",
    "published or closed": "published",
}


def _issue_with_board_status(url_orig, board_status, components=None, number=1):
    issue = _issue(url_orig, components, number=number)
    issue.raw.project_status = board_status
    return issue


def test_board_status_leads_over_component_rollup():
    """"Ready for Review" on the board counts as review, even when the
    component table would roll up to open."""
    inv = [_inventory_item()]
    issues = [
        _issue_with_board_status(
            "https://learn.wordpress.org/lesson/what-is-wordpress/",
            "Ready for Review",
            [ComponentStatus("text", "open")],
            number=11,
        )
    ]
    result = build_groups(inv, issues, COMPONENT_TEMPLATES, status_map=STATUS_MAP)
    item = result.groups[0]["courses"][0]["sections"][0]["items"][0]
    assert item["overall_status"] == "review"


def test_published_or_closed_maps_to_published_despite_open_components():
    """A published item stays published even when optional components
    (e.g. subtitles) were intentionally not translated."""
    inv = [_inventory_item()]
    issues = [
        _issue_with_board_status(
            "https://learn.wordpress.org/lesson/what-is-wordpress/",
            "Published or Closed",
            [ComponentStatus("text", "done"), ComponentStatus("subtitles", "open")],
            number=12,
        )
    ]
    result = build_groups(inv, issues, COMPONENT_TEMPLATES, status_map=STATUS_MAP)
    item = result.groups[0]["courses"][0]["sections"][0]["items"][0]
    assert item["overall_status"] == "published"


def test_unknown_board_status_falls_back_to_rollup():
    inv = [_inventory_item()]
    issues = [
        _issue_with_board_status(
            "https://learn.wordpress.org/lesson/what-is-wordpress/",
            "Some Brand New Column",
            [ComponentStatus("text", "wip")],
            number=13,
        )
    ]
    result = build_groups(inv, issues, COMPONENT_TEMPLATES, status_map=STATUS_MAP)
    item = result.groups[0]["courses"][0]["sections"][0]["items"][0]
    assert item["overall_status"] == "wip"


def test_without_status_map_behavior_unchanged():
    """No status_map passed: pre-0.5.0 rollup-only behavior."""
    inv = [_inventory_item()]
    issues = [
        _issue_with_board_status(
            "https://learn.wordpress.org/lesson/what-is-wordpress/",
            "Ready for Review",
            [ComponentStatus("text", "open")],
            number=14,
        )
    ]
    result = build_groups(inv, issues, COMPONENT_TEMPLATES)
    item = result.groups[0]["courses"][0]["sections"][0]["items"][0]
    assert item["overall_status"] == "open"


def test_board_status_leads_for_orphans():
    issues = [
        _issue_with_board_status(
            "https://learn.wordpress.org/lesson/not-in-scope/",
            "Published or Closed",
            [ComponentStatus("text", "open")],
            number=15,
        )
    ]
    result = build_groups([], issues, COMPONENT_TEMPLATES, status_map=STATUS_MAP)
    orphans = [g for g in result.groups if g["type"] == "orphan"]
    assert orphans[0]["items"][0]["overall_status"] == "published"


def test_stats_count_published_bucket():
    groups = [
        {
            "type": "orphan",
            "label": "Other",
            "items": [
                {"type": "lesson", "slug": "p", "title_en": "P",
                 "url_en": "https://learn.wordpress.org/lesson/p/",
                 "overall_status": "published"},
            ],
        },
    ]
    stats = calculate_stats(groups)
    assert stats["published"] == 1
    assert stats["total_items"] == 1


def test_status_map_yml_matches_schema_and_loader():
    """The shipped status-map.yml validates against its schema, and the
    normalized keys cover the six known board columns."""
    import yaml
    raw = yaml.safe_load((REPO_ROOT / "status-map.yml").read_text(encoding="utf-8"))
    schema = json.loads((REPO_ROOT / "schemas" / "status-map.schema.json").read_text(encoding="utf-8"))
    Draft202012Validator(schema).validate(raw)
    normalized = {str(k).strip().lower(): v for k, v in raw["map"].items()}
    assert normalized == STATUS_MAP
