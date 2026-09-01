# Changelog

## Unreleased

## 0.1.6 - 2026-09-01

- Apply per-feed element selectors and removal-only regular expressions before
  HTML-to-MyCode conversion.
- Add optional common cleanup for author/byline and source/read-more blocks.
- Validate every selector and regular expression in ACP so malformed rules
  cannot interrupt scheduled imports.
- Show the cleaned MyCode result in the non-writing initial-selection preview.

## 0.1.5 - 2026-09-01

- Normalize GUID and canonical-link identities before deriving stable item keys.
- Reserve each item durably before MyBB publication so concurrent or stale task
  runs cannot publish the same entry twice.
- Release reservations after ordinary failures so they remain retryable, while
  interrupted reservations are surfaced as uncertain for administrator review.
- Show uncertain publication outcomes separately in ACP queue status.

## 0.1.4 - 2026-09-01

- Publish due queue entries as MyBB threads through the official post data
  handler and retain the resulting thread and post IDs.
- Revalidate the configured forum, author, and effective posting permissions at
  publication time.
- Add configurable source-link attribution and restore the scheduled task's
  original MyBB user context after every publication attempt.

## 0.1.3 - 2026-09-01

- Add per-feed initial import policies for all available entries, the most recent
  entry, a bounded recent count, or starting after the current backlog.
- Persist skipped initial entries as seen so later scans do not unexpectedly
  queue them.
- Add a non-writing ACP preview of the selected initial policy.
- Require explicit confirmation before resetting an applied initial policy.
- Display the applied policy, initialization status, and skipped count in ACP.

## 0.1.2 - 2026-09-01

- Add a persistent discovery queue with per-feed pacing, batch limits, ordering,
  pause controls, bounded retries, stale-claim recovery, and ACP queue counts.

## 0.1.1 - 2026-09-01

- Add permission-protected Admin CP feed management with CSRF-safe create,
  update, and delete workflows.
- Add validated destination forum, posting user, enabled state, cleanup settings,
  status visibility, and per-feed import intervals.

## 0.1.0 - 2026-09-01

- License the independent implementation under GPL-3.0-or-later.
- Add initial MyBB plugin lifecycle and database schema.
- Add RSS/Atom fetch and parse foundation.
- Add safe HTML-to-MyCode conversion and import deduplication.
- Add scheduled task entry point.
