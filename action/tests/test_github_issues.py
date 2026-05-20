"""Tests for IssueFetcher: locale filtering, URL normalization, duplicate grouping."""

from src.github.issues import IssueFetcher


def _item(number, body, *, locale="German", state="OPEN"):
    """Build a single fake projectV2 item node."""
    return {
        "content": {
            "title": f"Issue {number}",
            "number": number,
            "url": f"https://github.com/WordPress/Learn/issues/{number}",
            "state": state,
            "body": body,
            "repository": {"nameWithOwner": "WordPress/Learn"},
            "assignees": {"nodes": [{"login": "rfluethi"}]},
        },
        "fieldValues": {
            "nodes": [
                {
                    "name": locale,
                    "field": {"name": "Locale"},
                }
            ]
        },
    }


class FakeClient:
    """Stand-in for ProjectV2Client.iter_items."""
    def __init__(self, items):
        self._items = items

    def iter_items(self, org, project_number):
        for item in self._items:
            yield item


def _make_fetcher(items, *, locale="German"):
    fetcher = IssueFetcher.__new__(IssueFetcher)
    fetcher.org = "WordPress"
    fetcher.project_number = 104
    fetcher.locale = locale
    fetcher.client = FakeClient(items)
    return fetcher


# ---------------------------------------------------------------------------

def test_locale_filter_keeps_matching_and_drops_others():
    body_de = """\
**Link to original content:** https://learn.wordpress.org/lesson/foo/

<!-- TRANSLATION-STATUS-START -->
| Component | Status | Creator | Reviewer |
|-----------|--------|---------|----------|
| text | done | a | b |
<!-- TRANSLATION-STATUS-END -->
"""
    body_fr = """\
**Link to original content:** https://learn.wordpress.org/lesson/bar/

<!-- TRANSLATION-STATUS-START -->
| Component | Status |
|-----------|--------|
| text | open |
<!-- TRANSLATION-STATUS-END -->
"""
    items = [
        _item(1, body_de, locale="German"),
        _item(2, body_fr, locale="French"),
    ]
    fetcher = _make_fetcher(items, locale="German")

    parsed = fetcher.fetch_all()
    assert len(parsed) == 1
    assert parsed[0].number == 1


def test_normalizes_original_url_even_for_non_canonical_input():
    body = """\
**Link to original content:** HTTPS://Learn.WordPress.Org/lesson/Foo
"""
    fetcher = _make_fetcher([_item(7, body)])
    parsed = fetcher.fetch_all()
    assert parsed[0].normalized_original == "https://learn.wordpress.org/lesson/foo/"


def test_missing_original_url_yields_empty_normalized():
    body = "No URL fields here at all."
    fetcher = _make_fetcher([_item(8, body)])
    parsed = fetcher.fetch_all()
    assert parsed[0].normalized_original == ""


def test_group_by_original_url_detects_duplicates():
    body_template = """\
**Link to original content:** https://learn.wordpress.org/lesson/foo/

<!-- TRANSLATION-STATUS-START -->
| Component | Status |
|-----------|--------|
| text | open |
<!-- TRANSLATION-STATUS-END -->
"""
    items = [_item(1, body_template), _item(2, body_template)]
    fetcher = _make_fetcher(items)
    parsed = fetcher.fetch_all()
    grouped = IssueFetcher.group_by_original_url(parsed)
    assert "https://learn.wordpress.org/lesson/foo/" in grouped
    assert len(grouped["https://learn.wordpress.org/lesson/foo/"]) == 2


def test_state_is_lowercased():
    body = "**Link to original content:** https://learn.wordpress.org/lesson/foo/"
    fetcher = _make_fetcher([_item(9, body, state="CLOSED")])
    parsed = fetcher.fetch_all()
    assert parsed[0].state == "closed"


def test_to_issue_dict_includes_assignees():
    body = "**Link to original content:** https://learn.wordpress.org/lesson/foo/"
    fetcher = _make_fetcher([_item(11, body)])
    parsed = fetcher.fetch_all()
    d = parsed[0].to_issue_dict()
    assert d["number"] == 11
    assert d["state"] == "open"
    assert d["assignees"] == ["rfluethi"]
