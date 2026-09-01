# Release checklist

## Automated checks

- [ ] Run `./tests/lint.sh` with the target PHP version.
- [ ] Confirm zero failures and zero skipped extension-dependent tests.
- [ ] Confirm `git status --short` is empty after the test run.
- [ ] Confirm the plugin version and newest changelog version match.

## Fresh installation

- [ ] Install on a clean MyBB 1.8 database and activate the plugin.
- [ ] Confirm the feed, imported-item, and queue tables are created once.
- [ ] Confirm the Feed Publisher task is created once and is initially disabled.
- [ ] Visit the Feed Publisher ACP page and confirm required PHP extensions and
      administrator permission behavior.
- [ ] Add and dry-run both an RSS fixture and an Atom fixture.

## Upgrade

- [ ] Upgrade from the previous tagged beta with existing feeds and queue rows.
- [ ] Confirm visiting the Feed Publisher ACP page adds missing columns without
      deleting or resetting existing configuration or queue state.
- [ ] Repeat activation and ACP loading to confirm schema/task setup is
      idempotent.

## End-to-end publication

- [ ] Publish through the scheduled task and through Publish next batch.
- [ ] Confirm destination-forum and posting-user permissions are enforced.
- [ ] Confirm custom title text, native MyBB prefix, attribution, and the
      85-character subject limit match dry run.
- [ ] Confirm source-time, future hold/clamp/skip/reject, queue pacing, and batch
      limits behave as previewed.
- [ ] Run discovery/task execution concurrently and confirm one MyBB thread is
      created for a single feed identity.
- [ ] Force a publication failure and confirm the reservation is released and
      the item is retried without a partial thread.
- [ ] Simulate an interrupted post-result update and confirm the item becomes
      uncertain rather than being automatically duplicated.

## Uninstall and documentation

- [ ] Back up the database, uninstall, and confirm plugin tables and the owned
      scheduled task are removed without touching unrelated MyBB data.
- [ ] Confirm README requirements, upload path, task setup, configuration,
      security boundaries, and known limitations match the release.
- [ ] Confirm CHANGELOG and the Gitea roadmap are current.
