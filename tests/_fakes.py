"""Tiny fake requests.Session for unit tests.

Pre-registers (url, params-tuple) → JSON pairs. Any unknown call raises
to make tests loud about unexpected traffic.
"""

from __future__ import annotations

import json
from typing import Any


class FakeResponse:
    def __init__(self, payload: Any, status_code: int = 200) -> None:
        self._payload = payload
        self.status_code = status_code
        self.text = json.dumps(payload) if payload is not None else ""

    def json(self) -> Any:
        if self._payload is None:
            raise ValueError("no body")
        return self._payload


class FakeSession:
    """requests.Session look-alike that returns predefined responses."""

    def __init__(self) -> None:
        self.headers: dict[str, str] = {}
        self._routes: dict[tuple[str, frozenset], Any] = {}
        self.calls: list[tuple[str, dict[str, Any] | None]] = []

    def register(
        self,
        url: str,
        payload: Any,
        *,
        params: dict[str, Any] | None = None,
        status: int = 200,
    ) -> None:
        key = (url, _params_key(params))
        self._routes[key] = (payload, status)

    def get(self, url: str, params: dict[str, Any] | None = None, timeout: int | None = None):
        self.calls.append((url, params))
        key = (url, _params_key(params))
        if key in self._routes:
            payload, status = self._routes[key]
            return FakeResponse(payload, status)
        # Fall back to a URL-only match for endpoints that don't take params.
        loose = (url, frozenset())
        if loose in self._routes:
            payload, status = self._routes[loose]
            return FakeResponse(payload, status)
        raise AssertionError(f"Unregistered fake GET: {url} params={params!r}")


def _params_key(params: dict[str, Any] | None) -> frozenset:
    if not params:
        return frozenset()
    return frozenset((k, str(v)) for k, v in params.items())
