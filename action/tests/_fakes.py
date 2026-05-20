"""Tiny fake requests.Session for unit tests.

Pre-registers (url, params-tuple) → JSON pairs. Any unknown call raises
to make tests loud about unexpected traffic.
"""

from __future__ import annotations

import json
from typing import Any


class FakeResponse:
    def __init__(
        self,
        payload: Any,
        status_code: int = 200,
        headers: dict[str, str] | None = None,
    ) -> None:
        self._payload = payload
        self.status_code = status_code
        self.text = json.dumps(payload) if payload is not None else ""
        self.headers = headers or {}

    def json(self) -> Any:
        if self._payload is None:
            raise ValueError("no body")
        return self._payload


class FakeSession:
    """requests.Session look-alike that returns predefined responses.

    Each route can be a single response or a sequence — useful for testing
    retry logic (e.g. two 429s followed by a 200).
    """

    def __init__(self) -> None:
        self.headers: dict[str, str] = {}
        self._routes: dict[tuple[str, frozenset], list[tuple[Any, int, dict]]] = {}
        self.calls: list[tuple[str, dict[str, Any] | None]] = []

    def register(
        self,
        url: str,
        payload: Any,
        *,
        params: dict[str, Any] | None = None,
        status: int = 200,
        headers: dict[str, str] | None = None,
    ) -> None:
        key = (url, _params_key(params))
        self._routes[key] = [(payload, status, headers or {})]

    def register_sequence(
        self,
        url: str,
        responses: list[tuple[Any, int]],
        *,
        params: dict[str, Any] | None = None,
    ) -> None:
        """Register a sequence of (payload, status_code) tuples.

        Each call to .get() returns the next entry. The last entry is repeated
        forever once the sequence is exhausted.
        """
        key = (url, _params_key(params))
        self._routes[key] = [(p, s, {}) for (p, s) in responses]

    def get(self, url: str, params: dict[str, Any] | None = None, timeout: int | None = None):
        self.calls.append((url, params))
        key = (url, _params_key(params))
        responses = self._routes.get(key)
        if responses is None:
            # Fall back to a URL-only match for endpoints that don't take params.
            loose = (url, frozenset())
            responses = self._routes.get(loose)
        if responses is None:
            raise AssertionError(f"Unregistered fake GET: {url} params={params!r}")

        if len(responses) > 1:
            payload, status, headers = responses.pop(0)
        else:
            payload, status, headers = responses[0]
        return FakeResponse(payload, status, headers)


def _params_key(params: dict[str, Any] | None) -> frozenset:
    if not params:
        return frozenset()
    return frozenset((k, str(v)) for k, v in params.items())
