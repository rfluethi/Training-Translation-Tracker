"""Inventory modules — one per item type, plus a dispatcher."""

from .base import InventoryItem, InventorySource
from .dispatcher import fetch, classify
from .url_normalizer import normalize

__all__ = ["InventoryItem", "InventorySource", "fetch", "classify", "normalize"]
