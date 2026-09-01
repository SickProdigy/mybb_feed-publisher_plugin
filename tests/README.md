# Feed Publisher tests

Run the complete local check from the repository root:

```sh
./tests/lint.sh
```

The runner has no Composer or PHPUnit dependency. It checks PHP syntax and Git
whitespace first, then runs regression tests for identity normalization, URL
safety, cleanup rules, title limits, attribution, source-date policies,
deterministic scheduling, concurrent reservations, publication rollback,
lifecycle guards, and documentation.

RSS/Atom parsing and HTML sanitization tests require PHP DOM and SimpleXML. They
are reported as explicit skips when those extensions are unavailable, rather
than being mistaken for passes. A release candidate must run with zero skips on
the same PHP major/minor and extensions used by the target MyBB installation.

The queue tests use an in-memory MyBB database test double. Complete the manual
MyBB checks in `docs/RELEASE_CHECKLIST.md` before a stable release.
