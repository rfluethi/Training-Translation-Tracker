"""Item-level statistics (Arbeitsplan §A.2.2).

Only `overall_status` per item is counted — component statuses are NOT
aggregated. The sum of (done + review + wip + open + na) equals total_items.
"""

from __future__ import annotations

from typing import Any


def calculate_stats(groups: list[dict[str, Any]]) -> dict[str, int]:
    """Walk the groups tree and count items by overall_status."""
    counts = {"done": 0, "review": 0, "wip": 0, "open": 0, "na": 0}
    total = 0

    for item in _iter_items(groups):
        total += 1
        status = item.get("overall_status") or "open"
        if status not in counts:
            status = "open"
        counts[status] += 1

    return {"total_items": total, **counts}


def _iter_items(groups: list[dict[str, Any]]):
    """Yield every leaf item across all group types."""
    for group in groups:
        gtype = group.get("type")
        if gtype == "pathway":
            for course in group.get("courses", []):
                for section in course.get("sections", []):
                    yield from section.get("items", [])
        elif gtype == "handbook":
            for section in group.get("sections", []):
                yield from section.get("items", [])
        elif gtype == "orphan":
            yield from group.get("items", [])
