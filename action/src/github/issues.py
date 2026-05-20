"""High-level Issue fetcher.

Wraps the GraphQL client and the body parser to produce `ParsedIssue`
objects ready to be joined with the inventory by the builder.
"""

from __future__ import annotations

import logging
from collections import defaultdict
from dataclasses import dataclass

import requests

from ..inventory.url_normalizer import normalize
from .graphql import ProjectV2Client, get_field_value
from .parser import ComponentStatus, IssueBody, parse_issue_body

LOG = logging.getLogger(__name__)


@dataclass
class RawIssue:
    """An issue as it sits in the Project V2 response."""
    number: int
    url: str
    state: str  # 'OPEN' or 'CLOSED' in the GraphQL response
    title: str
    body: str
    repository: str
    assignees: list[str]
    # Wert des Project-V2-Status-Felds, z. B. 'Awaiting Triage',
    # 'Translation in Progress', 'Ready for Review', 'Preparing to Publish',
    # 'Published or Closed', 'Looking for Translator'. Leer wenn unset.
    project_status: str = ""


@dataclass
class ParsedIssue:
    """An issue + its parsed body, ready for matching."""
    raw: RawIssue
    parsed: IssueBody
    # normalized_original is what the builder matches against inventory URLs.
    normalized_original: str = ""

    @property
    def number(self) -> int:
        return self.raw.number

    @property
    def url(self) -> str:
        return self.raw.url

    @property
    def state(self) -> str:
        return self.raw.state.lower()  # 'open' / 'closed'

    @property
    def components(self) -> list[ComponentStatus]:
        return self.parsed.components or []

    def to_issue_dict(self) -> dict:
        """The 'issue' field that goes into a tracker.json item."""
        out = {
            "number": self.raw.number,
            "url": self.raw.url,
            "state": self.state,
        }
        if self.raw.assignees:
            out["assignees"] = list(self.raw.assignees)
        if self.raw.project_status:
            out["project_status"] = self.raw.project_status
        return out


class IssueFetcher:
    """One pass: read all Project V2 items, filter by locale, parse bodies."""

    def __init__(
        self,
        token: str,
        org: str,
        project_number: int,
        locale: str,
        session: requests.Session | None = None,
    ) -> None:
        self.org = org
        self.project_number = int(project_number)
        self.locale = locale
        self.client = ProjectV2Client(token, session=session)

    # ------------------------------------------------------------------ public

    def fetch_all(self) -> list[ParsedIssue]:
        """Return all DACH-locale issues with parsed bodies."""
        out: list[ParsedIssue] = []
        for raw_item in self.client.iter_items(self.org, self.project_number):
            content = raw_item.get("content") or {}
            if not content:
                # Item is a draft or a non-issue card
                continue

            # Locale filter
            if self.locale:
                actual = (get_field_value(raw_item, "locale") or "").strip()
                if actual.lower() != self.locale.strip().lower():
                    continue

            raw_issue = _coerce_raw_issue(content)
            # Project-V2-Status-Field (z. B. 'Translation in Progress'). Leer
            # wenn das Issue dem Projekt noch nicht hinzugefügt wurde oder
            # kein Status gesetzt ist.
            raw_issue.project_status = get_field_value(raw_item, "status") or ""
            parsed = parse_issue_body(raw_issue.body or "")

            normalized_original = ""
            if parsed.url_original:
                try:
                    normalized_original = normalize(parsed.url_original)
                except ValueError:
                    LOG.warning(
                        "Issue #%s has malformed original URL %r — ignoring",
                        raw_issue.number,
                        parsed.url_original,
                    )

            out.append(
                ParsedIssue(
                    raw=raw_issue,
                    parsed=parsed,
                    normalized_original=normalized_original,
                )
            )

        LOG.info(
            "Fetched %d issues (locale=%s, project=%s/%d)",
            len(out),
            self.locale,
            self.org,
            self.project_number,
        )
        return out

    # ---------------------------------------------------------- duplicate util

    @staticmethod
    def group_by_original_url(issues: list[ParsedIssue]) -> dict[str, list[ParsedIssue]]:
        """Bucket parsed issues by their normalized original URL.

        Used by the builder to detect duplicates (Arbeitsplan §A.1.4):
        more than one issue per URL → all are kept; the most recently updated
        is the primary, the rest go into `duplicate_issues`. Issues without
        an original URL are skipped here — they end up in the orphan bucket
        in the builder.
        """
        out: dict[str, list[ParsedIssue]] = defaultdict(list)
        for issue in issues:
            if issue.normalized_original:
                out[issue.normalized_original].append(issue)
        return dict(out)


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _coerce_raw_issue(content: dict) -> RawIssue:
    repo = ((content.get("repository") or {}).get("nameWithOwner")) or ""
    assignees_node = (content.get("assignees") or {}).get("nodes") or []
    assignees = [n.get("login", "") for n in assignees_node if n and n.get("login")]
    return RawIssue(
        number=int(content.get("number") or 0),
        url=content.get("url") or "",
        state=content.get("state") or "",
        title=content.get("title") or "",
        body=content.get("body") or "",
        repository=repo,
        assignees=assignees,
    )
