#!/usr/bin/env python3
"""Keep Konzept/schemas/ and action/schemas/ in sync.

The JSON Schemata live in two physical places:

  Konzept/schemas/   →  authoritative specification (Phase-0 deliverables)
  action/schemas/    →  runtime copy that ships with the GitHub Action

Konzept/ is workspace-local and is NOT pushed to GitHub. The runtime copy
must therefore exist as a separate file, but must match the spec
byte-for-byte. This script enforces that invariant.

Usage
-----

  python sync-schemas.py             # check only; exit 1 on drift
  python sync-schemas.py --apply     # overwrite action/schemas/ from Konzept/schemas/

Run the check before pushing changes to the GitHub repo. Run with --apply
whenever you edit a schema in Konzept/schemas/ (which is the only place
that should be edited by hand).
"""

from __future__ import annotations

import argparse
import shutil
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
SPEC = ROOT / "Konzept" / "schemas"
RUNTIME = ROOT / "action" / "schemas"


def compare(spec: Path, runtime: Path) -> tuple[list[Path], list[Path], list[Path]]:
    """Return (only_in_spec, only_in_runtime, content_diff) as relative paths."""
    spec_files = {p.relative_to(spec) for p in spec.rglob("*") if p.is_file()}
    runtime_files = {p.relative_to(runtime) for p in runtime.rglob("*") if p.is_file()}

    only_in_spec = sorted(spec_files - runtime_files)
    only_in_runtime = sorted(runtime_files - spec_files)

    content_diff: list[Path] = []
    for rel in sorted(spec_files & runtime_files):
        if (spec / rel).read_bytes() != (runtime / rel).read_bytes():
            content_diff.append(rel)

    return only_in_spec, only_in_runtime, content_diff


def apply_spec(spec: Path, runtime: Path) -> list[str]:
    """Make runtime byte-identical to spec. Returns a list of errors (empty on success).

    Implementation note: we don't rmtree(runtime) and recreate it, because on
    some mounts (Nextcloud / FUSE) the directory itself has different
    ownership than the files inside and `rmtree` fails on the rmdir step.
    Instead we walk the runtime tree, delete files/subdirs that don't exist
    in spec, then copy/overwrite files from spec.

    Errors during delete are collected and reported, not raised — the caller
    can still produce a useful "almost-in-sync" result that the user can
    finish manually.
    """
    errors: list[str] = []
    runtime.mkdir(parents=True, exist_ok=True)

    spec_files = {p.relative_to(spec) for p in spec.rglob("*") if p.is_file()}
    runtime_files = {p.relative_to(runtime) for p in runtime.rglob("*") if p.is_file()}

    # 1) Drop files that exist in runtime but not in spec.
    for rel in runtime_files - spec_files:
        target = runtime / rel
        try:
            target.unlink()
        except OSError as exc:
            errors.append(f"Could not remove {rel}: {exc}")

    # 2) Drop subdirectories that exist in runtime but not in spec.
    spec_dirs = {p.relative_to(spec) for p in spec.rglob("*") if p.is_dir()}
    runtime_dirs = {p.relative_to(runtime) for p in runtime.rglob("*") if p.is_dir()}
    for rel in sorted(runtime_dirs - spec_dirs, key=lambda p: -len(p.parts)):
        target = runtime / rel
        try:
            target.rmdir()
        except OSError:
            # Either non-empty or filesystem refuses — caller will see the
            # drift via compare() in non-apply runs.
            pass

    # 3) Create missing directories in runtime.
    for rel in sorted(spec_dirs - runtime_dirs, key=lambda p: len(p.parts)):
        (runtime / rel).mkdir(exist_ok=True)

    # 4) Copy every file from spec to runtime (this overwrites differing ones).
    for rel in sorted(spec_files):
        src = spec / rel
        dst = runtime / rel
        dst.parent.mkdir(parents=True, exist_ok=True)
        try:
            shutil.copyfile(src, dst)
        except OSError as exc:
            errors.append(f"Could not write {rel}: {exc}")

    return errors


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Check or fix the sync between Konzept/schemas/ and action/schemas/.",
    )
    parser.add_argument(
        "--apply",
        action="store_true",
        help="Overwrite action/schemas/ from Konzept/schemas/ (Konzept is authoritative).",
    )
    parser.add_argument(
        "--quiet",
        action="store_true",
        help="Suppress 'in sync' output; only print on drift.",
    )
    args = parser.parse_args(argv)

    if not SPEC.exists():
        print(f"ERROR: {_rel(SPEC)} does not exist", file=sys.stderr)
        return 2
    if not RUNTIME.exists() and not args.apply:
        print(f"ERROR: {_rel(RUNTIME)} does not exist (run with --apply to create)", file=sys.stderr)
        return 2

    if args.apply:
        errors = apply_spec(SPEC, RUNTIME)
        # Re-check post-copy
        only_a, only_b, diff = compare(SPEC, RUNTIME)
        if errors or only_a or only_b or diff:
            print("Partial apply — drift remains:", file=sys.stderr)
            for err in errors:
                print(f"  - {err}", file=sys.stderr)
            if only_b:
                print("  Stale files in action/schemas/ that could not be removed:", file=sys.stderr)
                for p in only_b:
                    print(f"    - {p}", file=sys.stderr)
            if diff:
                print("  Files still differing:", file=sys.stderr)
                for p in diff:
                    print(f"    - {p}", file=sys.stderr)
            return 2
        print(f"Applied: copied {_rel(SPEC)} → {_rel(RUNTIME)}")
        return 0

    only_a, only_b, diff = compare(SPEC, RUNTIME)
    if not (only_a or only_b or diff):
        if not args.quiet:
            print(f"OK: {_rel(SPEC)} and {_rel(RUNTIME)} are in sync.")
        return 0

    print(f"DRIFT between {_rel(SPEC)} and {_rel(RUNTIME)}:")
    if only_a:
        print(f"  Only in {_rel(SPEC)}:")
        for p in only_a:
            print(f"    - {p}")
    if only_b:
        print(f"  Only in {_rel(RUNTIME)}:")
        for p in only_b:
            print(f"    - {p}")
    if diff:
        print("  Different content:")
        for p in diff:
            print(f"    - {p}")
    print()
    print("Fix: edit in Konzept/schemas/ first, then run `python sync-schemas.py --apply`.")
    return 1


def _rel(path: Path) -> str:
    try:
        return str(path.relative_to(ROOT))
    except ValueError:
        return str(path)


if __name__ == "__main__":
    sys.exit(main())
