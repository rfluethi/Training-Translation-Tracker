"""Tests for the issue body parser (URL fields + status table)."""

from src.github.parser import (
    parse_issue_body,
)


# ---------------------------------------------------------------------------
# A "clean" issue body in the new DACH template format
# ---------------------------------------------------------------------------

CLEAN_BODY = """\
**Link to original content:** https://learn.wordpress.org/lesson/what-is-wordpress/
**Link to translated content:** https://learn.wordpress.org/lesson/was-ist-wordpress/
**Link to WordPress.tv recording:** https://wordpress.tv/2024/01/foo/
**Link to YouTube recording:** https://www.youtube.com/watch?v=abc

<!-- TRANSLATION-STATUS-START -->
| Component  | Status | Creator    | Reviewer  |
|------------|--------|------------|-----------|
| text       | done   | rfluethi   | Ursha-wp  |
| thumbnails | done   | rfluethi   | Ursha-wp  |
| video      | wip    | rfluethi   |           |
| subtitles  | open   |            |           |
| quiz       | na     |            |           |
| exercise   | na     |            |           |
| audio      | na     |            |           |
<!-- TRANSLATION-STATUS-END -->
"""


def test_clean_body_urls():
    parsed = parse_issue_body(CLEAN_BODY)
    assert parsed.url_original == "https://learn.wordpress.org/lesson/what-is-wordpress/"
    assert parsed.url_translated == "https://learn.wordpress.org/lesson/was-ist-wordpress/"
    assert parsed.url_wptv == "https://wordpress.tv/2024/01/foo/"
    assert parsed.url_youtube == "https://www.youtube.com/watch?v=abc"
    assert parsed.parse_error is False


def test_clean_body_components():
    parsed = parse_issue_body(CLEAN_BODY)
    names = [c.name for c in parsed.components]
    assert names == ["text", "thumbnails", "video", "subtitles", "quiz", "exercise", "audio"]

    by_name = {c.name: c for c in parsed.components}
    assert by_name["text"].status == "done"
    assert by_name["text"].creator == "rfluethi"
    assert by_name["text"].reviewer == "Ursha-wp"
    assert by_name["video"].status == "wip"
    assert by_name["subtitles"].status == "open"
    assert by_name["audio"].status == "na"


# ---------------------------------------------------------------------------
# At-prefix stripping
# ---------------------------------------------------------------------------

def test_at_prefix_is_stripped():
    body = """\
**Link to original content:** https://learn.wordpress.org/lesson/foo/

<!-- TRANSLATION-STATUS-START -->
| Component | Status | Creator    | Reviewer  |
|-----------|--------|------------|-----------|
| text      | done   | @rfluethi  | @Ursha-wp |
<!-- TRANSLATION-STATUS-END -->
"""
    parsed = parse_issue_body(body)
    assert parsed.components[0].creator == "rfluethi"
    assert parsed.components[0].reviewer == "Ursha-wp"


# ---------------------------------------------------------------------------
# Handbook-style issue with just one row
# ---------------------------------------------------------------------------

def test_single_row_table():
    body = """\
**Link to original content:** https://make.wordpress.org/training/handbook/welcome/

<!-- TRANSLATION-STATUS-START -->
| Component | Status | Creator | Reviewer |
|-----------|--------|---------|----------|
| text      | review |         |          |
<!-- TRANSLATION-STATUS-END -->
"""
    parsed = parse_issue_body(body)
    assert len(parsed.components) == 1
    assert parsed.components[0].name == "text"
    assert parsed.components[0].status == "review"


# ---------------------------------------------------------------------------
# Broken table → parse_error True, components is None
# ---------------------------------------------------------------------------

def test_broken_table_sets_parse_error():
    body = """\
**Link to original content:** https://learn.wordpress.org/lesson/foo/

<!-- TRANSLATION-STATUS-START -->
[totally not a table]
<!-- TRANSLATION-STATUS-END -->
"""
    parsed = parse_issue_body(body)
    # No rows recognised, but markers exist → parse_error
    assert parsed.parse_error is True
    assert parsed.components == []


def test_no_markers_means_empty_components_no_error():
    body = "Some plain prose without any structured table."
    parsed = parse_issue_body(body)
    assert parsed.components is None
    assert parsed.parse_error is False


# ---------------------------------------------------------------------------
# Unknown status defaults to 'open'
# ---------------------------------------------------------------------------

def test_unknown_status_defaults_to_open():
    body = """\
<!-- TRANSLATION-STATUS-START -->
| Component | Status     | Creator | Reviewer |
|-----------|------------|---------|----------|
| text      | somehow    |         |          |
<!-- TRANSLATION-STATUS-END -->
"""
    parsed = parse_issue_body(body)
    assert parsed.components[0].status == "open"


# ---------------------------------------------------------------------------
# Auto-detection of wordpress.tv / youtube URLs when no labelled field
# ---------------------------------------------------------------------------

def test_auto_detect_wptv_when_no_explicit_field():
    body = """\
**Link to original content:** https://learn.wordpress.org/lesson/foo/
There is a recording at https://wordpress.tv/2024/07/bar/ somewhere in the body.
"""
    parsed = parse_issue_body(body)
    assert parsed.url_wptv == "https://wordpress.tv/2024/07/bar/"
    assert parsed.url_wptv_de == "https://wordpress.tv/2024/07/bar/"
    assert parsed.url_wptv_en == ""


def test_separate_en_and_de_media_urls():
    """Neue Issue-Vorlage trennt Original- und Übersetzungs-Recording explizit."""
    body = """\
**Link to original content:** https://learn.wordpress.org/lesson/foo/
**Link to translated content:** https://learn.wordpress.org/lesson/foo-de/
**Link to original WordPress.tv recording:** https://wordpress.tv/2024/01/foo-en/
**Link to translated WordPress.tv recording:** https://wordpress.tv/2024/01/foo-de/
**Link to original YouTube recording:** https://www.youtube.com/watch?v=foo-en
**Link to translated YouTube recording:** https://www.youtube.com/watch?v=foo-de
"""
    parsed = parse_issue_body(body)
    assert parsed.url_wptv_en == "https://wordpress.tv/2024/01/foo-en/"
    assert parsed.url_wptv_de == "https://wordpress.tv/2024/01/foo-de/"
    assert parsed.url_youtube_en == "https://www.youtube.com/watch?v=foo-en"
    assert parsed.url_youtube_de == "https://www.youtube.com/watch?v=foo-de"
    # Aliasse stehen weiterhin (mapping auf _de)
    assert parsed.url_wptv == parsed.url_wptv_de
    assert parsed.url_youtube == parsed.url_youtube_de


def test_legacy_generic_label_maps_to_de_slot():
    """Alte Issues mit "Link to WordPress.tv recording" (ohne original/translated)."""
    body = """\
**Link to original content:** https://learn.wordpress.org/lesson/foo/
**Link to WordPress.tv recording:** https://wordpress.tv/2024/01/foo-de/
**Link to YouTube recording:** https://www.youtube.com/watch?v=foo-de
"""
    parsed = parse_issue_body(body)
    assert parsed.url_wptv_de == "https://wordpress.tv/2024/01/foo-de/"
    assert parsed.url_wptv_en == ""
    assert parsed.url_youtube_de == "https://www.youtube.com/watch?v=foo-de"
    assert parsed.url_youtube_en == ""
