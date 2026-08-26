# Plugin unit tests

Pure PHPUnit + [Brain Monkey](https://giuseppe-mazzapica.gitbook.io/brain-monkey/)
unit tests for the WordPress plugin in `../wp-plugin`. WordPress functions
are mocked — no Docker, no wp-env, no database needed.

```bash
cd plugin-tests
composer install
composer test        # or: vendor/bin/phpunit
```

The suite lives outside `wp-plugin/` on purpose so it never ends up in the
release ZIP. It covers the fetcher (cache, 5-second timeout, failure
backoff, schema guards), the status logic (board-status normalization,
stats counting incl. `published` and `untouched`, component ordering,
markers) and the settings (host allow-list, cache clamp, avatar checkbox).
Integration tests against a real WordPress would additionally need
`wp-env` (Docker) and are intentionally out of scope here.
