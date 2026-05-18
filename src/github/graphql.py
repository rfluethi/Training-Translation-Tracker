"""GraphQL client for GitHub Project V2.

Pages through `organization.projectV2.items`, returning the raw issue
content plus the project field values (so callers can filter by Locale).

Cost values from the response extensions are logged on every page (see
Arbeitsplan §A.4.1 — cost transparency in the Action log).
"""

from __future__ import annotations

import json
import logging
from dataclasses import dataclass
from typing import Any, Iterator

import requests

LOG = logging.getLogger(__name__)

ENDPOINT = "https://api.github.com/graphql"
PAGE_SIZE = 100
MAX_PAGES = 20  # safety net

_QUERY = """
query($org: String!, $num: Int!, $after: String) {
  organization(login: $org) {
    projectV2(number: $num) {
      items(first: 100, after: $after) {
        pageInfo { hasNextPage endCursor }
        nodes {
          content {
            ... on Issue {
              title number url state body
              repository { nameWithOwner }
              assignees(first: 20) { nodes { login } }
            }
          }
          fieldValues(first: 20) {
            nodes {
              ... on ProjectV2ItemFieldSingleSelectValue {
                name
                field { ... on ProjectV2SingleSelectField { name } }
              }
              ... on ProjectV2ItemFieldTextValue {
                text
                field { ... on ProjectV2Field { name } }
              }
            }
          }
        }
      }
    }
  }
  rateLimit { cost remaining resetAt }
}
"""


@dataclass
class GraphQLError(Exception):
    message: str

    def __str__(self) -> str:
        return self.message


class ProjectV2Client:
    """Thin pager over Project V2 items.

    Yields raw item dicts as returned by GitHub — caller is responsible for
    filtering (e.g. by Locale) and for issue body parsing.
    """

    def __init__(self, token: str, session: requests.Session | None = None) -> None:
        if not token:
            raise GraphQLError("GH_PAT_PROJECT_READ token is required")
        self.token = token
        self.session = session or requests.Session()
        self.session.headers.update(
            {
                "Authorization": f"Bearer {token}",
                "Accept": "application/vnd.github+json",
                "User-Agent": "Training-Translation-Tracker-Inventory/0.1",
            }
        )

    def iter_items(self, org: str, project_number: int) -> Iterator[dict[str, Any]]:
        after: str | None = None
        page = 0
        total_cost = 0
        while page < MAX_PAGES:
            page += 1
            data = self._post(
                {"org": org, "num": int(project_number), "after": after}
            )

            cost_info = data.get("rateLimit") or {}
            page_cost = int(cost_info.get("cost") or 0)
            remaining = cost_info.get("remaining")
            total_cost += page_cost
            LOG.info(
                "Project V2 page %d  cost=%d  remaining=%s  resetAt=%s",
                page,
                page_cost,
                remaining,
                cost_info.get("resetAt"),
            )

            project = (data.get("organization") or {}).get("projectV2") or {}
            items = project.get("items") or {}
            for node in items.get("nodes") or []:
                yield node

            page_info = items.get("pageInfo") or {}
            if not page_info.get("hasNextPage"):
                break
            after = page_info.get("endCursor")

        LOG.info("Project V2 fetch complete. total cost=%d, pages=%d", total_cost, page)

    # ---------------------------------------------------------------- helpers

    def _post(self, variables: dict[str, Any]) -> dict[str, Any]:
        try:
            resp = self.session.post(
                ENDPOINT,
                data=json.dumps({"query": _QUERY, "variables": variables}),
                timeout=30,
            )
        except requests.RequestException as exc:
            raise GraphQLError(f"POST {ENDPOINT} failed: {exc}") from exc

        if resp.status_code >= 400:
            raise GraphQLError(f"GraphQL HTTP {resp.status_code}: {resp.text[:200]}")

        try:
            body = resp.json()
        except ValueError as exc:
            raise GraphQLError("GraphQL returned non-JSON body") from exc

        if body.get("errors"):
            first = body["errors"][0]
            raise GraphQLError(f"GraphQL: {first.get('message', 'unknown error')}")

        return body.get("data") or {}


def get_field_value(item: dict[str, Any], field_name: str) -> str:
    """Return the SingleSelect/Text value of a Project field, or '' if absent."""
    target = field_name.strip().lower()
    field_values = (item.get("fieldValues") or {}).get("nodes") or []
    for node in field_values:
        field = (node or {}).get("field") or {}
        name = (field.get("name") or "").strip().lower()
        if name != target:
            continue
        # SingleSelect → "name"; TextValue → "text"
        if "name" in node and isinstance(node.get("name"), str):
            return node["name"]
        if "text" in node and isinstance(node.get("text"), str):
            return node["text"]
    return ""
