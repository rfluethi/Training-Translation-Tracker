"""Join inventory items and parsed issues into the tracker.json group tree.

Builds three buckets:
  - pathway groups (Lessons + Lesson Plans + Tutorials, grouped by parent_path)
  - handbook groups (Handbook items, grouped by parent_path)
  - orphan group (issues without matching inventory OR items outside scope)

Pulls component defaults from component-templates.yml when an item has no issue.
"""

from __future__ import annotations

import logging
from collections import defaultdict
from dataclasses import dataclass, field
from typing import Any

from ..github.issues import ParsedIssue
from ..inventory.base import InventoryItem

LOG = logging.getLogger(__name__)


# ---------------------------------------------------------------------------

@dataclass
class JoinerResult:
    groups: list[dict[str, Any]] = field(default_factory=list)
    warnings: list[str] = field(default_factory=list)


def build_groups(
    inventory: list[InventoryItem],
    issues: list[ParsedIssue],
    component_templates: dict[str, list[str]],
) -> JoinerResult:
    """Top-level entry point. Returns groups + a list of human-readable warnings."""

    # 1) Map issues by normalized original URL (and detect duplicates)
    issue_index: dict[str, list[ParsedIssue]] = defaultdict(list)
    for issue in issues:
        if issue.normalized_original:
            issue_index[issue.normalized_original].append(issue)
        # Issues without URL or with bad URL go straight to orphans below.

    matched_inventory_urls: set[str] = set()
    warnings: list[str] = []

    # 2) Walk inventory items, attach issue data, build group tree
    pathway_groups, handbook_items = _walk_inventory(
        inventory, issue_index, matched_inventory_urls, component_templates, warnings
    )

    # 3) Orphans = issues that didn't match any inventory item
    orphan_items = _collect_orphans(issues, matched_inventory_urls, warnings)

    groups: list[dict[str, Any]] = []
    if pathway_groups:
        groups.extend(pathway_groups)
    if handbook_items:
        groups.append(
            {
                "type": "handbook",
                "label": "Training Handbook",
                "sections": _bucket_handbook_items(handbook_items),
            }
        )
    if orphan_items:
        groups.append(
            {
                "type": "orphan",
                "label": "Sonstige (außerhalb Scope / verwaist)",
                "items": orphan_items,
            }
        )

    return JoinerResult(groups=groups, warnings=warnings)


# ---------------------------------------------------------------------------
# overall_status algorithm (Arbeitsplan §A.2.1)
# ---------------------------------------------------------------------------

def calculate_overall_status(component_statuses: list[str]) -> str:
    """Reduce a list of component statuses to a single overall status."""
    if not component_statuses:
        return "open"

    non_na = [s for s in component_statuses if s != "na"]
    if not non_na:
        return "na"
    if all(s == "done" for s in non_na):
        return "done"
    if "review" in non_na:
        return "review"
    if "wip" in non_na:
        return "wip"
    return "open"


# ---------------------------------------------------------------------------
# Inventory walking
# ---------------------------------------------------------------------------

def _walk_inventory(
    inventory: list[InventoryItem],
    issue_index: dict[str, list[ParsedIssue]],
    matched_urls: set[str],
    component_templates: dict[str, list[str]],
    warnings: list[str],
) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    """Build the pathway group tree and a flat list of handbook items.

    Sensei items use parent_path = [course_slug, section_slug?]; handbook items
    use parent_path = [...ancestor_slugs]. For pathway grouping we treat all
    learn.wordpress.org items as belonging to one pseudo-pathway named after
    the course slug — pathway taxonomy resolution is a Phase 3 concern.
    """

    pathway_tree: dict[str, dict[str, dict[str, list[dict[str, Any]]]]] = defaultdict(
        lambda: defaultdict(lambda: defaultdict(list))
    )
    handbook_items: list[dict[str, Any]] = []

    for inv_item in inventory:
        matching = issue_index.get(inv_item.url_en, [])
        item_dict = _item_to_dict(inv_item, matching, component_templates, warnings)

        if inv_item.url_en in issue_index:
            matched_urls.add(inv_item.url_en)

        if inv_item.type in ("handbook_text", "handbook_video"):
            handbook_items.append(
                {
                    "_section_path": inv_item.parent_path,
                    **item_dict,
                }
            )
        else:
            course_slug = inv_item.parent_path[0] if inv_item.parent_path else "(no course)"
            section_slug = inv_item.parent_path[1] if len(inv_item.parent_path) > 1 else "(no section)"
            pathway_tree[course_slug][section_slug]["items"].append(item_dict)

    # Flatten pathway tree into the groups structure that tracker.schema expects.
    # For Phase 1 we emit one pathway group per top-level course slug.
    out: list[dict[str, Any]] = []
    for course_slug, sections in pathway_tree.items():
        out.append(
            {
                "type": "pathway",
                "slug": course_slug,
                "label": _humanize(course_slug),
                "courses": [
                    {
                        "slug": course_slug,
                        "label": _humanize(course_slug),
                        "sections": [
                            {
                                "slug": section_slug,
                                "label": _humanize(section_slug),
                                "items": payload["items"],
                            }
                            for section_slug, payload in sections.items()
                        ],
                    }
                ],
            }
        )

    return out, handbook_items


def _bucket_handbook_items(items: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Group handbook items by their top-most section in parent_path."""
    sections: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for entry in items:
        section_path = entry.pop("_section_path")
        section_slug = section_path[0] if section_path else "(no section)"
        sections[section_slug].append(entry)

    return [
        {"slug": slug, "label": _humanize(slug), "items": items}
        for slug, items in sections.items()
    ]


# ---------------------------------------------------------------------------
# Item construction
# ---------------------------------------------------------------------------

def _item_to_dict(
    inv_item: InventoryItem,
    matching_issues: list[ParsedIssue],
    component_templates: dict[str, list[str]],
    warnings: list[str],
) -> dict[str, Any]:
    item = inv_item.to_minimal_dict()

    if not matching_issues:
        # No issue → all default components as "open"
        defaults = component_templates.get(inv_item.type, []) or []
        item["components"] = [{"name": name, "status": "open"} for name in defaults]
        item["overall_status"] = "open"
        return item

    # Choose the primary issue (most recent state-change is hard to derive without
    # `updatedAt` in the query — for now: the lowest issue number wins, others go
    # into duplicate_issues). Duplicate detection itself is still raised here.
    matching_sorted = sorted(matching_issues, key=lambda i: i.number)
    primary = matching_sorted[0]
    duplicates = matching_sorted[1:]

    if duplicates:
        warnings.append(
            f"Duplicate issues for {inv_item.url_en}: "
            f"primary #{primary.number}, others {[i.number for i in duplicates]}"
        )

    item["issue"] = primary.to_issue_dict()
    if duplicates:
        item["duplicate_issues"] = [d.to_issue_dict() for d in duplicates]

    if primary.parsed.url_translated:
        item["url_de"] = primary.parsed.url_translated
    if primary.parsed.url_wptv:
        item["url_wptv"] = primary.parsed.url_wptv
    if primary.parsed.url_youtube:
        item["url_youtube"] = primary.parsed.url_youtube

    if primary.parsed.parse_error:
        item["parse_error"] = True

    # Components: prefer the issue's table; fall back to defaults if empty.
    if primary.components:
        item["components"] = [c.to_dict() for c in primary.components]
        statuses = [c.status for c in primary.components]
    else:
        defaults = component_templates.get(inv_item.type, []) or []
        item["components"] = [{"name": name, "status": "open"} for name in defaults]
        statuses = ["open"] * len(defaults)

    item["overall_status"] = calculate_overall_status(statuses)
    return item


# ---------------------------------------------------------------------------
# Orphans
# ---------------------------------------------------------------------------

def _collect_orphans(
    issues: list[ParsedIssue],
    matched_urls: set[str],
    warnings: list[str],
) -> list[dict[str, Any]]:
    """Return orphan-bucket items for issues that didn't match the inventory."""
    out: list[dict[str, Any]] = []

    for issue in issues:
        normalized = issue.normalized_original
        if not normalized:
            # Issue without an extractable Original URL
            warnings.append(
                f"Issue #{issue.number} has no parseable original-content URL"
            )
            out.append(
                {
                    "type": "lesson",  # best guess; not used for Sensei lookup
                    "slug": f"issue-{issue.number}",
                    "title_en": issue.raw.title,
                    "url_en": issue.raw.url,
                    "orphan_reason": "outside_scope",
                    "issue": issue.to_issue_dict(),
                    "components": [c.to_dict() for c in issue.components],
                    "overall_status": calculate_overall_status(
                        [c.status for c in issue.components]
                    ),
                }
            )
            continue

        if normalized in matched_urls:
            continue

        # The issue points at a URL that the inventory didn't yield → outside_scope
        out.append(
            {
                "type": _guess_type_from_url(normalized),
                "slug": _last_path_segment(normalized),
                "title_en": issue.raw.title,
                "url_en": normalized,
                "orphan_reason": "outside_scope",
                "issue": issue.to_issue_dict(),
                "components": [c.to_dict() for c in issue.components],
                "overall_status": calculate_overall_status(
                    [c.status for c in issue.components]
                ),
            }
        )

    return out


# ---------------------------------------------------------------------------
# Small helpers
# ---------------------------------------------------------------------------

def _humanize(slug: str) -> str:
    """Turn a slug like 'beginner-wordpress-user' into 'Beginner Wordpress User'."""
    if not slug or slug.startswith("("):
        return slug
    return " ".join(part.capitalize() for part in slug.split("-"))


def _last_path_segment(url: str) -> str:
    return url.rstrip("/").rsplit("/", 1)[-1] or "unknown"


def _guess_type_from_url(url: str) -> str:
    if "/lesson-plan/" in url:
        return "lesson_plan"
    if "/tutorial/" in url:
        return "tutorial"
    if "/lesson/" in url:
        return "lesson"
    if "/training/handbook/" in url:
        return "handbook_text"
    return "lesson"
