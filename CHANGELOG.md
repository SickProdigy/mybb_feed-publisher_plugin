# Changelog

## Unreleased

## 0.1.25 - 2026-09-02

- Add automatic and administrator-approval publication modes per feed, with
  prepared entries held outside scheduled dispatch until explicitly approved.
- Add a permission-protected, CSRF-protected ACP review queue that reads only
  stored queue snapshots and shows final composed output without re-fetching.
- Support approve-original, edit-and-approve, 24-hour defer, and permanent
  rejection actions with administrator and diagnostic logging.
- Return approvals to the normal paced publisher and its existing permission,
  validation, and deduplication reservation flow; persist rejections durably.

## 0.1.24 - 2026-09-02

- Parse up to 10 distinct RSS enclosures, Media RSS content/thumbnail records,
  and Atom enclosure links per entry using resolved safe HTTP/HTTPS URLs.
- Add per-feed media handling to ignore metadata, append ordinary media links,
  or hotlink images while always degrading videos and unknown files to links.
- Persist parsed media with the prepared queue snapshot so publication performs
  no new remote request and uses the exact metadata shown by dry run.
- Show media source, kind, supplied MIME type, URL, and intended treatment in
  previews; never download attachments or emit raw iframe/embed HTML.

## 0.1.23 - 2026-09-01

- Add optional per-feed MyCode headers and footers with a strict placeholder
  allowlist for entry, feed, author, source URL, and publication-date values.
- Add word-safe body excerpt limits with optional configurable continuation
  links while preserving the existing unlimited-body default.
- Use one post-composition path for dry-run previews and live publication so
  the displayed final title and body match what MyBB receives.
- Persist parsed entry authors with queued content and keep source attribution
  as a separate final composition step that templates cannot replace.

## 0.1.22 - 2026-09-01

- Add per-feed include and exclude filters for title, source URL, category/tag,
  and body text using bounded plain substring or validated regex rules.
- Add minimum/maximum source age, required body, and required image/media
  eligibility options before initial selection and queue staging.
- Show exact pass/reject reasons in dry run and persist filter rejection as a
  distinct durable disposition that survives terminal queue cleanup.
- Require explicit re-evaluation when filters change and reset only prior
  filter-rejected history, without reviving initial-policy skips.
- Persist skipped and other terminal identities independently of queue retention
  so duplicate source entries remain known after their display rows are pruned.

## 0.1.21 - 2026-09-01

- Add an ACP Diagnostics page with plugin, MyBB, PHP, extension, scheduled-task,
  per-feed, queue, retry, error, and last-publication health information.
- Add fetch/parse-only checks for at most 10 feeds without staging, cleanup,
  reconciliation, configuration changes, or publication.
- Add filterable diagnostic events by feed, stage, severity, and time, bounded
  to 30 days and 1,000 rows with 100-row cleanup batches.
- Add a copyable support report that excludes usernames, credentials, tokens,
  cookies, post content, and response bodies, with feed URLs redacted by default.

## 0.1.20 - 2026-09-01

- Add per-feed age and per-state count retention for terminal queue records,
  with permanent imported-item deduplication history as the safe default.
- Add explicitly confirmed optional deduplication-history pruning, clear
  republishing-risk warnings, dry-run impact counts, and a confirmed/logged
  manual cleanup operation.
- Bound automatic and manual cleanup to 100 records per run and add guarded
  strict reconciliation that rejects only unpublished queued entries after a
  successful non-empty, non-truncated feed scan.
- Preserve all published MyBB threads and posts; no automatic published-content
  deletion or modification is provided.

## 0.1.19 - 2026-09-01

- Add bounded website feed discovery from declared RSS, RDF, and Atom alternate
  links without crawling pages or executing remote content.
- Add a no-write connection test reporting fetch/parse stage, HTTP status,
  content type, redirect status, feed title, format, encoding, item count, and
  newest valid source date.
- Reuse DNS pinning, SSRF, TLS, redirect, timeout, response-size, and XML safety
  controls for website and candidate requests, with safe error summaries and a
  direct action for selecting a validated candidate.

## 0.1.18 - 2026-09-01

- Add per-feed GUID/link, normalized-title, content-fingerprint, and combined
  duplicate identity strategies with explicit versioned normalization.
- Preserve existing GUID/link keys by default, accept otherwise valid keyless
  entries for fallback strategies, and keep queue/import uniqueness safeguards.
- Require explicit queue/history reset confirmation for strategy changes and
  explain the derived identity and existing match in dry-run previews.

## 0.1.17 - 2026-09-01

- Expand parsing across RSS 0.9x, RSS 1.0 RDF, RSS 2.0, and Atom while handling
  common namespaces, CDATA content, date variants, Atom alternate/enclosure
  links, inherited `xml:base`, and relative entry URLs.
- Detect HTTP, BOM, and XML character encodings, normalize supported input to
  UTF-8, and reject unsupported or conflicting declarations before staging.
- Permit mislabeled feed responses only after bounded XML-shape validation,
  retain entity, document-size, depth, and node protections, and show detected
  feed format and source encoding in dry-run previews.

## 0.1.16 - 2026-09-01

- Add dependency-free regression tests for parsing fixtures, sanitization and
  URL safety, cleanup rules, identity keys, title/date policies, concurrent
  reservations, publication rollback, lifecycle guards, and documentation.
- Add explicit extension-based skips, a one-command lint/test runner, testing
  documentation, known limitations, and a real-MyBB release checklist.

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
