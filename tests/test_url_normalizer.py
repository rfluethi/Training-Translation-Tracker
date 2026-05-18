"""URL normalizer must produce the canonical form documented in Arbeitsplan §A.1.1."""

import pytest

from src.inventory.url_normalizer import normalize


@pytest.mark.parametrize(
    "input_url, expected",
    [
        # Basic — already canonical
        (
            "https://learn.wordpress.org/lesson/what-is-wordpress/",
            "https://learn.wordpress.org/lesson/what-is-wordpress/",
        ),
        # Uppercase host and scheme — must be lowercased, scheme upgraded to https
        (
            "HTTP://Learn.WordPress.Org/lesson/foo",
            "https://learn.wordpress.org/lesson/foo/",
        ),
        # www. is stripped
        (
            "https://www.learn.wordpress.org/lesson/foo/",
            "https://learn.wordpress.org/lesson/foo/",
        ),
        # Missing trailing slash — added
        (
            "https://learn.wordpress.org/lesson/foo",
            "https://learn.wordpress.org/lesson/foo/",
        ),
        # Query and fragment — dropped
        (
            "https://learn.wordpress.org/lesson/foo/?x=1&y=2#section",
            "https://learn.wordpress.org/lesson/foo/",
        ),
        # Surrounding whitespace
        (
            "  https://learn.wordpress.org/lesson/foo/  ",
            "https://learn.wordpress.org/lesson/foo/",
        ),
        # Handbook URL
        (
            "HTTPS://Make.WordPress.Org/training/handbook/Getting-Started/",
            "https://make.wordpress.org/training/handbook/getting-started/",
        ),
    ],
)
def test_normalize(input_url, expected):
    assert normalize(input_url) == expected


@pytest.mark.parametrize("bad", ["", "  ", "ftp://example.com/", "mailto:foo@bar"])
def test_normalize_rejects_bad_input(bad):
    with pytest.raises(ValueError):
        normalize(bad)
