"""Write tracker.json and last-run.md.

When the Action fails before this point, the old tracker.json on the data
branch stays in place (callers must not call write_outputs in that case —
see build.py for the policy).
"""

from __future__ import annotations

import json
import logging
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

LOG = logging.getLogger(__name__)


def write_outputs(
    output_dir: Path,
    *,
    schema_version: int,
    generated_at: datetime | None,
    scope_version: str | None,
    stats: dict[str, int],
    groups: list[dict[str, Any]],
    warnings: list[str],
) -> tuple[Path, Path]:
    """Write tracker.json and last-run.md into output_dir. Returns their paths."""
    output_dir.mkdir(parents=True, exist_ok=True)

    generated = (generated_at or datetime.now(timezone.utc)).astimezone(timezone.utc)

    payload: dict[str, Any] = {
        "schema_version": schema_version,
        "generated_at": generated.strftime("%Y-%m-%dT%H:%M:%SZ"),
        "stats": stats,
        "groups": groups,
    }
    if scope_version:
        payload["scope_version"] = scope_version

    tracker_path = output_dir / "tracker.json"
    tracker_path.write_text(
        json.dumps(payload, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )

    report_path = output_dir / "last-run.md"
    report_path.write_text(_render_report(stats, warnings, generated), encoding="utf-8")

    return tracker_path, report_path


def _render_report(stats: dict[str, int], warnings: list[str], generated: datetime) -> str:
    lines = [
        "# Last run",
        "",
        f"Generated: `{generated.strftime('%Y-%m-%dT%H:%M:%SZ')}`",
        "",
        "## Stats",
        "",
        f"- total_items: {stats.get('total_items', 0)}",
        f"- done: {stats.get('done', 0)}",
        f"- review: {stats.get('review', 0)}",
        f"- wip: {stats.get('wip', 0)}",
        f"- open: {stats.get('open', 0)}",
        f"- na: {stats.get('na', 0)}",
        "",
    ]

    if warnings:
        lines.append("## Warnings")
        lines.append("")
        for warning in warnings:
            lines.append(f"- {warning}")
    else:
        lines.append("## Warnings")
        lines.append("")
        lines.append("_No warnings._")

    lines.append("")
    return "\n".join(lines)
