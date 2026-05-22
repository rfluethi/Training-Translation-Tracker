"""Hygiene module: detects maintenance-relevant observations from the build."""

from src.builder.hygiene import collect_hygiene, render_hygiene_markdown
from src.github.issues import ParsedIssue, RawIssue
from src.github.parser import ComponentStatus, IssueBody
from src.inventory.base import InventoryItem


def _make_issue(number, body):
    raw = RawIssue(
        number=number,
        url=f"https://github.com/WordPress/Learn/issues/{number}",
        state="OPEN",
        title=f"Issue {number}",
        body="",
        repository="WordPress/Learn",
        assignees=[],
    )
    return ParsedIssue(raw=raw, parsed=body, normalized_original=body.url_original)


def test_detects_issues_without_markers():
    body = IssueBody(url_original="https://learn.wordpress.org/lesson/foo/", components=None)
    issue = _make_issue(101, body)
    report = collect_hygiene([issue], [], set(), {})
    assert report.issues_without_markers == [(101, "Issue 101")]
    assert report.issues_with_parse_errors == []


def test_detects_parse_errors():
    body = IssueBody(
        url_original="https://learn.wordpress.org/lesson/foo/",
        components=[],
        parse_error=True,
    )
    issue = _make_issue(102, body)
    report = collect_hygiene([issue], [], set(), {})
    assert report.issues_with_parse_errors == [(102, "Issue 102")]


def test_detects_missing_original_url():
    body = IssueBody(url_original="", components=None)
    issue = _make_issue(103, body)
    report = collect_hygiene([issue], [], set(), {})
    assert report.issues_without_original_url == [(103, "Issue 103")]


def test_detects_suspicious_usernames():
    body = IssueBody(
        url_original="https://learn.wordpress.org/lesson/foo/",
        components=[
            ComponentStatus("text", "done", creator="Ursha-wp.", reviewer="rfluethi"),
            ComponentStatus("video", "wip", creator="@bigod", reviewer=""),
        ],
    )
    issue = _make_issue(104, body)
    report = collect_hygiene([issue], [], set(), {})
    suspicious = report.suspicious_users
    assert (104, "text", "Creator", "Ursha-wp.") in suspicious
    assert (104, "video", "Creator", "@bigod") in suspicious


def test_detects_duplicate_urls():
    body_a = IssueBody(url_original="https://learn.wordpress.org/lesson/foo/", components=None)
    body_b = IssueBody(url_original="https://learn.wordpress.org/lesson/foo/", components=None)
    a = _make_issue(11, body_a)
    b = _make_issue(22, body_b)
    issue_index = {"https://learn.wordpress.org/lesson/foo/": [a, b]}
    report = collect_hygiene([a, b], [], set(), issue_index)
    assert report.duplicate_url_clusters == [
        ("https://learn.wordpress.org/lesson/foo/", [11, 22])
    ]


def test_detects_scope_items_without_issue():
    inv = [
        InventoryItem(
            type="lesson",
            slug="x",
            title_en="Lesson X",
            url_en="https://learn.wordpress.org/lesson/x/",
        )
    ]
    matched: set[str] = set()  # nothing matched
    report = collect_hygiene([], inv, matched, {})
    assert report.inventory_items_without_issue == [
        ("https://learn.wordpress.org/lesson/x/", "Lesson X")
    ]


def test_render_markdown_contains_all_sections():
    body = IssueBody(url_original="", components=None)
    issue = _make_issue(99, body)
    report = collect_hygiene([issue], [], set(), {})
    md = render_hygiene_markdown(report)
    # Headings for every category must be present
    assert "# Data-Hygiene Report" in md
    assert "## Issues without new marker table" in md
    assert "## Issues with parse error" in md
    assert "## Multiple issues" in md
    assert "## Issues without an extractable original URL" in md
    assert "## Creator/Reviewer with suspicious characters" in md
    assert "## Scope items without an issue" in md
    # The one finding should be listed
    assert "#99" in md


def test_render_markdown_when_no_issues():
    report = collect_hygiene([], [], set(), {})
    md = render_hygiene_markdown(report)
    # Each category should report "None"
    assert md.count("_None") >= 4
