"""Join inventory items and parsed issues into the tracker.json group tree.

Builds three buckets:
  - pathway groups (Lessons + Lesson Plans + Tutorials, grouped per groups.yml)
  - handbook groups (Handbook items, grouped by parent_path)
  - orphan group (issues without matching inventory OR items outside scope)

Hierarchy comes from groups.yml (DACH-maintained pathway → course → section
structure). Items in scope.yml but not mentioned in groups.yml fall back to
a pseudo-pathway 'Ohne Gruppe'.
"""

from __future__ import annotations

import logging
from collections import defaultdict
from dataclasses import dataclass, field
from typing import Any

from ..github.issues import ParsedIssue
from ..inventory.base import InventoryItem
from .hygiene import HygieneReport, collect_hygiene

LOG = logging.getLogger(__name__)


# ---------------------------------------------------------------------------

@dataclass
class JoinerResult:
    groups: list[dict[str, Any]] = field(default_factory=list)
    warnings: list[str] = field(default_factory=list)
    hygiene: HygieneReport = field(default_factory=HygieneReport)


def build_groups(
    inventory: list[InventoryItem],
    issues: list[ParsedIssue],
    component_templates: dict[str, list[str]],
    scope_config: dict[str, Any] | None = None,
) -> JoinerResult:
    """Top-level entry point. Returns groups + a list of human-readable warnings.

    `scope_config` is the parsed groups.yml (a dict with key "pathways").
    Pass None to fall back to a single 'Ohne Gruppe' pathway containing all
    inventory items in scope.yml order.
    """

    # 1) Map issues by normalized original URL (and detect duplicates)
    issue_index: dict[str, list[ParsedIssue]] = defaultdict(list)
    for issue in issues:
        if issue.normalized_original:
            issue_index[issue.normalized_original].append(issue)

    matched_inventory_urls: set[str] = set()
    warnings: list[str] = []

    # 2) Group lookup: URL → (pathway_slug, pathway_label, course_slug, course_label, section_slug, section_label)
    url_to_position = _build_url_to_position(scope_config or {})

    # 3) Walk inventory items, attach issue data, sort into pathway/handbook
    pathway_tree, handbook_items = _walk_inventory(
        inventory,
        issue_index,
        matched_inventory_urls,
        component_templates,
        url_to_position,
        warnings,
    )

    # 4) Orphans = issues that didn't match any inventory item
    orphan_items = _collect_orphans(issues, matched_inventory_urls, warnings)

    # 5) Build the actual pathway groups, in the order they appear in groups.yml
    groups: list[dict[str, Any]] = []
    pathway_order = _pathway_order_from_config(scope_config or {})
    pathway_order.append(("__no_group__", "Ohne Gruppe"))  # fallback last

    for pathway_slug, pathway_label in pathway_order:
        courses_data = pathway_tree.get(pathway_slug)
        if not courses_data:
            continue
        course_blocks = []
        course_order = _course_order_for_pathway(scope_config or {}, pathway_slug)
        course_order.append(("__no_course__", "Ohne Kurs"))
        for course_slug, course_label in course_order:
            sections_data = courses_data.get(course_slug)
            if not sections_data:
                continue
            section_blocks = []
            section_order = _section_order_for_course(scope_config or {}, pathway_slug, course_slug)
            section_order.append(("__no_section__", "Ohne Section"))
            for section_slug, section_label in section_order:
                items_in_section = sections_data.get(section_slug)
                if not items_in_section:
                    continue
                section_blocks.append({
                    "slug": section_slug if section_slug != "__no_section__" else "ohne-section",
                    "label": section_label,
                    "items": items_in_section,
                })
            if section_blocks:
                course_blocks.append({
                    "slug": course_slug if course_slug != "__no_course__" else "ohne-kurs",
                    "label": course_label,
                    "sections": section_blocks,
                })
        if course_blocks:
            groups.append({
                "type": "pathway",
                "slug": pathway_slug if pathway_slug != "__no_group__" else "ohne-gruppe",
                "label": pathway_label,
                "courses": course_blocks,
            })

    if handbook_items:
        groups.append({
            "type": "handbook",
            "label": "Training Handbook",
            "sections": _bucket_handbook_items(handbook_items),
        })

    if orphan_items:
        groups.append({
            "type": "orphan",
            "label": "Sonstige",
            "items": orphan_items,
        })

    # 7) Hygiene-Bericht — sammelt pflegerelevante Beobachtungen
    hygiene = collect_hygiene(
        parsed_issues=issues,
        inventory=inventory,
        matched_inventory_urls=matched_inventory_urls,
        issue_index=dict(issue_index),
    )

    return JoinerResult(groups=groups, warnings=warnings, hygiene=hygiene)


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
# groups.yml lookup helpers
# ---------------------------------------------------------------------------

# A "position" describes where an URL sits in the pathway tree.
# Tuple: (pathway_slug, pathway_label, course_slug, course_label, section_slug, section_label)
Position = tuple[str, str, str, str, str, str]


def _build_url_to_position(scope_config: dict[str, Any]) -> dict[str, Position]:
    """Flatten groups.yml into URL → position lookup."""
    out: dict[str, Position] = {}
    for pathway in scope_config.get("pathways", []) or []:
        ps, pl = pathway["slug"], pathway["label"]
        for course in pathway.get("courses", []) or []:
            cs, cl = course["slug"], course["label"]
            for section in course.get("sections", []) or []:
                ss, sl = section["slug"], section["label"]
                for url in section.get("items", []) or []:
                    out[url] = (ps, pl, cs, cl, ss, sl)
    return out


def _pathway_order_from_config(scope_config: dict[str, Any]) -> list[tuple[str, str]]:
    """Pathway order as written in groups.yml."""
    return [(p["slug"], p["label"]) for p in scope_config.get("pathways", []) or []]


def _course_order_for_pathway(scope_config: dict[str, Any], pathway_slug: str) -> list[tuple[str, str]]:
    for pathway in scope_config.get("pathways", []) or []:
        if pathway["slug"] == pathway_slug:
            return [(c["slug"], c["label"]) for c in pathway.get("courses", []) or []]
    return []


def _section_order_for_course(
    scope_config: dict[str, Any],
    pathway_slug: str,
    course_slug: str,
) -> list[tuple[str, str]]:
    for pathway in scope_config.get("pathways", []) or []:
        if pathway["slug"] != pathway_slug:
            continue
        for course in pathway.get("courses", []) or []:
            if course["slug"] == course_slug:
                return [(s["slug"], s["label"]) for s in course.get("sections", []) or []]
    return []


# ---------------------------------------------------------------------------
# Inventory walking
# ---------------------------------------------------------------------------

def _walk_inventory(
    inventory: list[InventoryItem],
    issue_index: dict[str, list[ParsedIssue]],
    matched_urls: set[str],
    component_templates: dict[str, list[str]],
    url_to_position: dict[str, Position],
    warnings: list[str],
) -> tuple[dict, list[dict[str, Any]]]:
    """Build pathway tree dict and a flat list of handbook items.

    pathway_tree[pathway_slug][course_slug][section_slug] = [item_dict, ...]
    """

    pathway_tree: dict = defaultdict(
        lambda: defaultdict(lambda: defaultdict(list))
    )
    handbook_items: list[dict[str, Any]] = []

    for inv_item in inventory:
        matching = issue_index.get(inv_item.url_en, [])
        item_dict = _item_to_dict(inv_item, matching, component_templates, warnings)

        if inv_item.url_en in issue_index:
            matched_urls.add(inv_item.url_en)

        if inv_item.type in ("handbook_text", "handbook_video"):
            handbook_items.append({"_section_path": inv_item.parent_path, **item_dict})
            continue

        position = url_to_position.get(inv_item.url_en)
        if position is None:
            warnings.append(
                f"URL not in groups.yml — placed under 'Ohne Gruppe': {inv_item.url_en}"
            )
            pathway_tree["__no_group__"]["__no_course__"]["__no_section__"].append(item_dict)
        else:
            _, _, course_slug, _, section_slug, _ = position
            pathway_slug = position[0]
            pathway_tree[pathway_slug][course_slug][section_slug].append(item_dict)

    return pathway_tree, handbook_items


def _bucket_handbook_items(items: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Group handbook items by their top-most section in parent_path.

    Pages mit leerem parent_path (= Top-Level-Pages wie "/handbook/about/")
    werden als ihre eigene Section behandelt — ihr Slug wird zum Section-Slug.
    Eine Kind-Seite wie "/handbook/about/team-values/" hat parent_path=["about"]
    und landet in derselben Section "about". So entstehen aus dem flachen
    handbook:-Block in scope.yml saubere Section-Gruppen.
    """
    sections: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for entry in items:
        section_path = entry.pop("_section_path")
        if section_path:
            section_slug = section_path[0]
        else:
            section_slug = entry.get("slug") or "handbook"
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
        defaults = component_templates.get(inv_item.type, []) or []
        item["components"] = [{"name": name, "status": "open"} for name in defaults]
        item["overall_status"] = "open"
        return item

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
    if primary.parsed.title_de:
        item["title_de"] = primary.parsed.title_de
    # Video-URLs: getrennt nach EN/DE; Alias-Felder fürs alte Konsumenten-API.
    if primary.parsed.url_wptv_en:
        item["url_wptv_en"] = primary.parsed.url_wptv_en
    if primary.parsed.url_wptv_de:
        item["url_wptv_de"] = primary.parsed.url_wptv_de
    if primary.parsed.url_wptv:
        item["url_wptv"] = primary.parsed.url_wptv
    if primary.parsed.url_youtube_en:
        item["url_youtube_en"] = primary.parsed.url_youtube_en
    if primary.parsed.url_youtube_de:
        item["url_youtube_de"] = primary.parsed.url_youtube_de
    if primary.parsed.url_youtube:
        item["url_youtube"] = primary.parsed.url_youtube
    if primary.parsed.parse_error:
        item["parse_error"] = True

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
    out: list[dict[str, Any]] = []

    for issue in issues:
        normalized = issue.normalized_original
        if not normalized:
            warnings.append(
                f"Issue #{issue.number} has no parseable original-content URL"
            )
            out.append({
                "type": "lesson",
                "slug": f"issue-{issue.number}",
                "title_en": issue.raw.title,
                "url_en": issue.raw.url,
                "orphan_reason": "outside_scope",
                "issue": issue.to_issue_dict(),
                "components": [c.to_dict() for c in issue.components],
                "overall_status": calculate_overall_status(
                    [c.status for c in issue.components]
                ),
            })
            continue

        if normalized in matched_urls:
            continue

        out.append({
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
        })

    return out


# ---------------------------------------------------------------------------
# Small helpers
# ---------------------------------------------------------------------------

def _humanize(slug: str) -> str:
    if not slug or slug.startswith("ohne-"):
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
