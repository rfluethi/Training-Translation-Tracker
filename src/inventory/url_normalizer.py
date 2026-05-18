"""URL normalization.

Canonical form for any URL used in matching (see Arbeitsplan §A.1.1):

- lowercase host and path
- scheme = https
- trailing slash
- no query parameters, no fragment
- no www. subdomain

Applied symmetrically to inventory URLs and URLs extracted from issue bodies.
"""

from __future__ import annotations

from urllib.parse import urlparse, urlunparse


def normalize(url: str) -> str:
    """Return the canonical form of a URL.

    Raises ValueError on input that cannot be parsed as an http/https URL.
    """
    if not isinstance(url, str) or not url.strip():
        raise ValueError("URL must be a non-empty string")

    parsed = urlparse(url.strip())

    if parsed.scheme.lower() not in ("http", "https"):
        raise ValueError(f"Unsupported scheme: {parsed.scheme!r}")

    host = parsed.netloc.lower()
    if host.startswith("www."):
        host = host[4:]

    path = parsed.path.lower() or "/"
    if not path.endswith("/"):
        path = path + "/"

    return urlunparse(("https", host, path, "", "", ""))
