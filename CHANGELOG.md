# Changelog

## Unreleased

## 0.1.15 - 2026-09-01

- Add per-feed MyBB publication-time or valid source-time thread timestamps.
- Add explicit hold, clamp, skip, and reject policies for future-dated entries,
  with a bounded one-year scheduling horizon and safe fallback for missing or
  implausible dates.
- Add deterministic scheduling spread up to 60 minutes, stable queue tie
  ordering, and dry-run source, queue, and intended-thread time details.

## 0.1.14 - 2026-09-01

- Add independent per-feed custom title text and native MyBB thread-prefix
  selection, with forum/user filtering and publication-time revalidation.
- Preview the exact bounded subject and selected native prefix in dry runs.
- Clarify and correctly order Pause publishing and Feed enabled at the bottom
  of the feed settings form.

## 0.1.13 - 2026-09-01

- Add a per-feed ACP Operations page for immediate discovery, forced next-batch
  publication, bounded failed-item retries, and confirmed pause/backoff/queue
  maintenance.
- Add individual failed-item retry and uncertain-item resolution by linking an
  existing MyBB post, certifying safe retry, or permanently rejecting the item.
- Share discovery logic between scheduled and manual runs, retain queue claim
  and reservation safeguards, and record successful and failed operations in
  the MyBB administrator log.
- Display rejected queue counts separately.

## 0.1.12 - 2026-09-01

- Add green and red status indicators and panel styling for entries that will
  be queued or marked as seen.
- Give the currently expanded preview panel a stronger border and shadow.
- Replace ambiguous footer navigation with return/edit, Add feed, and View all
  feeds actions.

## 0.1.11 - 2026-09-01

- Present dry-run entries as collapsible review panels with the first entry open.
- Put the initial action first, followed by entry and publication metadata,
  cleanup byte and percentage measurements, and realistic example title/body
  output including configured source attribution.
- Keep import state secondary by showing it only for already-known entries.

## 0.1.10 - 2026-09-01

- Add visible return-to-form, edit-feed, and feed-list navigation controls to
  the dry-run preview.

## 0.1.9 - 2026-09-01

- Use MyBB's administrator permission API so configured super administrators
  can manage Feed Publisher while custom ACP permissions remain enforceable for
  other administrators.

## 0.1.8 - 2026-09-01

- Resolve and validate every feed address, then pin cURL to a validated public
  target to prevent DNS changes between validation and connection.
- Explicitly reject redirects and unexpected response MIME types while retaining
  strict connection, request, and response-size limits.
- Reject document types and excessively large or deep feed structures.
- Add persistent bounded exponential discovery backoff and stage-specific,
  control-character-safe task errors.

## 0.1.7 - 2026-09-01

- Add a direct dry-run preview for every saved feed and retain the form preview
  for unsaved configuration changes.
- Use one shared preparation pipeline for scheduled queueing and previews.
- Show parsed entry details, cleaned MyCode, cleanup byte counts, intended
  initial action, and imported or queued state without writing any data.

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
