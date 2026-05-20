"""Shared test plumbing.

Inserts the repo root into sys.path so `import src...` works without an
editable install.
"""
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))
