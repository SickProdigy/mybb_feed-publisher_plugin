# Release checklist

## Automated checks

- [x] Run `./tests/lint.sh` with the target PHP version.
- [x] Confirm zero failures and zero skipped extension-dependent tests.
- [x] Confirm `git status --short` is empty after the test run.
- [x] Confirm the plugin version and newest changelog version match.

## Fresh installation

- [x] Install on a clean MyBB 1.8 database and activate the plugin.
- [x] Confirm the feed, imported-item, and queue tables are created once.
- [x] Confirm the Feed Publisher task is created once, enabled, and has a future
      next-run time.
- [x] Visit the Feed Publisher ACP page and confirm required PHP extensions and
      administrator permission behavior.
- [x] Add and dry-run both an RSS fixture and an Atom fixture.

## Upgrade

- [x] Upgrade from the previous tagged beta with existing feeds and queue rows.
- [x] Confirm visiting the Feed Publisher ACP page adds missing columns without
      deleting or resetting existing configuration or queue state.
- [x] Repeat activation and ACP loading to confirm schema/task setup is
      idempotent.

## End-to-end publication

- [x] Publish through the scheduled task and through Publish next batch.
- [x] Confirm destination-forum and posting-user permissions are enforced.
- [x] Confirm custom title text, native MyBB prefix, attribution, and the
      85-character subject limit match dry run.
- [x] Confirm source-time, future hold/clamp/skip/reject, queue pacing, and batch
      limits behave as previewed.
- [x] Run discovery/task execution concurrently and confirm one MyBB thread is
      created for a single feed identity.
- [x] Force a publication failure and confirm the reservation is released and
      the item is retried without a partial thread.
- [x] Simulate an interrupted post-result update and confirm the item becomes
      uncertain rather than being automatically duplicated.

## Uninstall and documentation

- [x] Back up the database, uninstall, and confirm plugin tables and the owned
      scheduled task are removed without touching unrelated MyBB data.
- [x] Confirm README requirements, upload path, task setup, configuration,
      security boundaries, and known limitations match the release.
- [x] Confirm CHANGELOG and the Gitea roadmap are current.
