"""Parse the body of a translation issue.

Extracts:
  - URL fields (original, translated, WP.tv, YouTube)
  - The component-status table between `<!-- TRANSLATION-STATUS-START -->`
    and `<!-- TRANSLATION-STATUS-END -->`

The new DACH issue template (see `Issue-Vorlage-DACH.md`) uses clean
`Link to <kind> content` field names. Older issues in the wild still use a
mix of names; both are recognized for backward compatibility.
"""

from __future__ import annotations

import logging
import re
from dataclasses import dataclass

LOG = logging.getLogger(__name__)

VALID_STATUSES = {"open", "wip", "review", "done", "na"}
VALID_COMPONENTS = {"text", "thumbnails", "video", "subtitles", "quiz", "exercise", "audio"}

# Markers used to delimit the status table inside the issue body.
TABLE_START = "<!-- TRANSLATION-STATUS-START -->"
TABLE_END = "<!-- TRANSLATION-STATUS-END -->"


# ---------------------------------------------------------------------------
# Data classes
# ---------------------------------------------------------------------------

@dataclass
class ComponentStatus:
    name: str
    status: str
    creator: str = ""
    reviewer: str = ""

    def to_dict(self) -> dict[str, str]:
        out = {"name": self.name, "status": self.status}
        if self.creator:
            out["creator"] = self.creator
        if self.reviewer:
            out["reviewer"] = self.reviewer
        return out


@dataclass
class IssueBody:
    """Parsed view of an issue body."""

    url_original: str = ""
    url_translated: str = ""
    # German title from the issue body. Recognises several spellings:
    # "German title:", "German lesson name:", "Deutscher Titel:", "Translation title:".
    # Empty if none found; the plugin then falls back to a humanised
    # slug derived from url_translated.
    title_de: str = ""
    # Recording URLs: separate slots for English originals and German translations.
    # `url_wptv` / `url_youtube` are backward-compat aliases:
    # old bodies with only "Link to WordPress.tv recording" end up in `url_wptv_de`,
    # because that is the usual meaning in the existing DACH issues.
    url_wptv_en: str = ""
    url_wptv_de: str = ""
    url_youtube_en: str = ""
    url_youtube_de: str = ""
    # Deprecated aliases, kept for output consistency (mapped to the _de slot).
    url_wptv: str = ""
    url_youtube: str = ""
    components: list[ComponentStatus] | None = None
    parse_error: bool = False


# ---------------------------------------------------------------------------
# Public entry points
# ---------------------------------------------------------------------------

def parse_issue_body(body: str) -> IssueBody:
    """Parse a full issue body into structured data."""
    body = body or ""

    # WP.tv: first look for the explicit "original" / "translated" labels.
    # If those are missing, old style "Link to WordPress.tv recording" -> DE slot.
    # If that is also missing, auto-detect (any wordpress.tv link) -> DE slot.
    wptv_en = _extract_url(body, _WPTV_EN_PATTERNS)
    wptv_de = (
        _extract_url(body, _WPTV_DE_PATTERNS)
        or _extract_url(body, _WPTV_GENERIC_PATTERNS)
        or _auto_wptv(body)
    )
    youtube_en = _extract_url(body, _YOUTUBE_EN_PATTERNS)
    youtube_de = (
        _extract_url(body, _YOUTUBE_DE_PATTERNS)
        or _extract_url(body, _YOUTUBE_GENERIC_PATTERNS)
        or _auto_youtube(body)
    )

    return IssueBody(
        url_original=_extract_url(body, _ORIGINAL_PATTERNS),
        url_translated=_extract_url(body, _TRANSLATED_PATTERNS),
        title_de=_extract_text(body, _TITLE_DE_PATTERNS),
        url_wptv_en=wptv_en,
        url_wptv_de=wptv_de,
        url_youtube_en=youtube_en,
        url_youtube_de=youtube_de,
        # Alias: existing consumers read `url_wptv` and `url_youtube`.
        url_wptv=wptv_de,
        url_youtube=youtube_de,
        components=_parse_table_or_none(body),
        parse_error=_has_table_markers(body) and not _parse_table_or_none(body),
    )


def parse_status_table(body: str) -> list[ComponentStatus]:
    """Just the component-status table. Returns [] if absent or unparseable."""
    return _parse_table_or_none(body) or []


# ---------------------------------------------------------------------------
# URL extraction
# ---------------------------------------------------------------------------

# Each entry is (regex, group_index). The label is matched case-insensitively.
# The `:.*?` after the label is non-greedy and stays on the same line; that
# tolerates wrappers like `**Label:**`, `(English)`, `< >` etc. between the
# colon and the URL.
_ORIGINAL_PATTERNS = [
    (
        re.compile(
            r"(?:link to original content|original content url|original url)[^\n:]*:.*?(https?://[^\s\n<>)\]]+)",
            re.IGNORECASE,
        ),
        1,
    ),
]

_TRANSLATED_PATTERNS = [
    (
        re.compile(
            r"(?:link to translated content|translated content|translation url|german lesson|deutsche lektion)[^\n:]*:.*?(https?://[^\s\n<>)\]]+)",
            re.IGNORECASE,
        ),
        1,
    ),
]

# WP.tv: explicit "original" / "translated" labels
_WPTV_EN_PATTERNS = [
    (
        re.compile(
            r"(?:link to original wordpress\.tv recording|original wordpress\.tv|english wordpress\.tv)[^\n:]*:.*?(https?://[^\s\n<>)\]]+)",
            re.IGNORECASE,
        ),
        1,
    ),
]

_WPTV_DE_PATTERNS = [
    (
        re.compile(
            r"(?:link to translated wordpress\.tv recording|translated wordpress\.tv|german wordpress\.tv|deutsche wordpress\.tv)[^\n:]*:.*?(https?://[^\s\n<>)\]]+)",
            re.IGNORECASE,
        ),
        1,
    ),
]

# Backward compat: old form without "original/translated" maps to DE
_WPTV_GENERIC_PATTERNS = [
    (
        re.compile(
            r"(?:link to wordpress\.tv recording|link to wptv|link to tv)[^\n:]*:.*?(https?://[^\s\n<>)\]]+)",
            re.IGNORECASE,
        ),
        1,
    ),
]

_YOUTUBE_EN_PATTERNS = [
    (
        re.compile(
            r"(?:link to original youtube recording|original youtube|english youtube)[^\n:]*:.*?(https?://[^\s\n<>)\]]+)",
            re.IGNORECASE,
        ),
        1,
    ),
]

_YOUTUBE_DE_PATTERNS = [
    (
        re.compile(
            r"(?:link to translated youtube recording|translated youtube|german youtube|deutsche youtube)[^\n:]*:.*?(https?://[^\s\n<>)\]]+)",
            re.IGNORECASE,
        ),
        1,
    ),
]

_YOUTUBE_GENERIC_PATTERNS = [
    (
        re.compile(
            r"(?:link to youtube recording|link to youtube|youtube url)[^\n:]*:.*?(https?://[^\s\n<>)\]]+)",
            re.IGNORECASE,
        ),
        1,
    ),
]

_AUTO_WPTV = re.compile(r"(https?://wordpress\.tv/[^\s\n<]+)", re.IGNORECASE)
_AUTO_YT = re.compile(r"(https?://(?:www\.)?(?:youtube\.com|youtu\.be)/[^\s\n<]+)", re.IGNORECASE)

# German title: recognise several spellings, all case-insensitive.
# Recognised labels:
#   - "German title:" (canonical)
#   - "German lesson name:" (variant seen in many older issues)
#   - "Deutscher Titel:" / "Übersetzungstitel:" / "Translation title:" /
#     "Translated title:"
# Recognised line-start formats:
#   - "**German title:** value"  (bold label, DACH style)
#   - "- German title: value"    (bullet list, official WordPress/Learn style)
#   - "* German title: value"    (bullet with asterisk)
#   - "German title: value"      (no prefix)
_TITLE_DE_PATTERNS = [
    (
        re.compile(
            # Start of line + optional list marker (- or *) + optional whitespace
            r"(?:^|\n)[ \t]*[-*]?[ \t]*"
            # Optional bold marker
            r"(?:\*\*[ \t]*)?"
            # Label (multiple spellings)
            r"(?:german title|german lesson name|deutscher titel|"
            r"übersetzungstitel|translation title|translated title)"
            # Optionally close bold marker + colon
            r"[ \t]*(?:\*\*)?[ \t]*:[ \t]*(?:\*\*[ \t]*)?"
            # Actual title (non-greedy until end of line or markdown end)
            r"([^\n*<]+?)"
            r"[ \t]*(?:\*\*)?[ \t]*(?:\n|$)",
            re.IGNORECASE,
        ),
        1,
    ),
]


def _extract_url(body: str, patterns: list[tuple[re.Pattern[str], int]]) -> str:
    for pattern, group in patterns:
        match = pattern.search(body)
        if match:
            return match.group(group).strip().rstrip(").,;")
    return ""


def _extract_text(body: str, patterns: list[tuple[re.Pattern[str], int]]) -> str:
    """Like _extract_url, but for free-text fields. Trims whitespace and
    typical leftover markdown (asterisks, backticks).
    """
    for pattern, group in patterns:
        match = pattern.search(body)
        if match:
            value = match.group(group).strip()
            # Strip any leftover markdown at the end of the value.
            value = value.strip("*` ").strip()
            if value:
                return value
    return ""


def _auto_wptv(body: str) -> str:
    match = _AUTO_WPTV.search(body)
    return match.group(1).strip() if match else ""


def _auto_youtube(body: str) -> str:
    match = _AUTO_YT.search(body)
    return match.group(1).strip() if match else ""


# ---------------------------------------------------------------------------
# Status table
# ---------------------------------------------------------------------------

def _has_table_markers(body: str) -> bool:
    return TABLE_START in body and TABLE_END in body


def _parse_table_or_none(body: str) -> list[ComponentStatus] | None:
    """Return None if no table is recognised; [] is reserved for empty-but-present."""

    block = _extract_table_block(body)
    if block is None:
        return None

    rows: list[ComponentStatus] = []
    for raw_line in block.splitlines():
        line = raw_line.strip()
        if not line.startswith("|"):
            continue
        # Skip separator rows like `|---|---|`
        if re.match(r"^\|[\s\-:|]+\|?$", line):
            continue

        cells = [c.strip() for c in line.strip("|").split("|")]
        # Drop trailing empty cells that come from `... |`
        while cells and cells[-1] == "":
            cells.pop()
        if not cells:
            continue

        first = cells[0].lower()
        # Skip header rows
        if first in ("component", "name", "field"):
            continue
        if first not in VALID_COMPONENTS:
            # Unknown component name → ignore quietly
            continue

        status = cells[1].lower() if len(cells) > 1 else "open"
        if status not in VALID_STATUSES:
            LOG.warning("Unknown status %r in row %r — defaulting to 'open'", status, line)
            status = "open"

        creator = cells[2].lstrip("@") if len(cells) > 2 else ""
        reviewer = cells[3].lstrip("@") if len(cells) > 3 else ""

        rows.append(
            ComponentStatus(
                name=first, status=status, creator=creator, reviewer=reviewer
            )
        )

    return rows


def _extract_table_block(body: str) -> str | None:
    """Find the table content. Strict: only between the documented markers."""
    if not _has_table_markers(body):
        return None
    start = body.index(TABLE_START) + len(TABLE_START)
    end = body.index(TABLE_END)
    if end <= start:
        return None
    return body[start:end]
