# Changelog

## 1.0.2 - 2026-09-16

- Prefer strongly identified article-body containers over broad page wrappers
  during full-text extraction, avoiding page headers and related-content tails.
- Change new-feed defaults to check every 240 minutes and publish one queued
  item every 360 minutes; existing feed schedules remain unchanged.

## 1.0.1 - 2026-09-16

- Add a per-feed title-removal regex applied before the existing plain title
  prefix, allowing repetitive source text to be replaced by concise labels.

## 1.0.0 - 2026-09-16

- Validate Initial recent count only when the Recent count initial-import
  policy is selected.
- Rename feed media controls to Extra feed media; body images and links remain
  unaffected by extra media handling.
- Enable the body-text eligibility check by default so title-only feed entries
  are filtered unless an admin opts into allowing them; image-only entries need
  that option disabled.
- Remove common linked-article chrome during full-article extraction, including
  image lightbox/download controls, share widgets, newsletter/ad blocks, and
  trailing related/story navigation while preserving article images and ordinary
  links.
- Add an Extra feed media mode that appends the first discovered image only when
  the converted body has no image, and make eligibility rejection reasons report
  whether body text and media were present.
- Add per-feed extra-media placement so appended feed media can appear before or
  after the imported body, defaulting new feeds to before the body.
- Default new feeds and connection suggestions to the image-fallback extra media
  mode.
- Default linked full-article retrieval to summary mode and treat explicit
  read-more/full-article feed teasers as needing linked-article retrieval.
- Suggest retrying teaser entries a few times before marking them seen without
  publishing when their linked full article cannot be extracted.
- Split the add/edit feed form into consistent sections and move Publication
  mode next to Pause publishing.
- Separate OPML and full-configuration export actions on the Import / export
  page with clearer descriptions.
- Harden imported content conversion by escaping raw feed-text MyCode brackets,
  rejecting private/local content and media URLs, and disabling cURL proxy use
  for DNS-pinned fetches.
- Use a Firefox-style request identity for remote feed, discovery, and linked
  article fetches while keeping the existing public-address and no-proxy limits.
- Convert YouTube video links in imported body content and feed media to MyBB
  `[video=youtube]` embeds.

## 0.1.32 - 2026-09-13

- Calculate the Feed Publisher scheduled task's next run when creating it,
  repair missing or stale next-run metadata on activation, and add a
  Diagnostics action to reschedule and unlock the task.
- Show task file, last run, lock, and overdue task-runner guidance in
  Diagnostics.
- Keep connection-test and feed-discovery feedback on the add/edit form instead
  of sending administrators to a separate results page; apply detected defaults
  automatically when an exact URL works or discovery finds one usable feed.
- Put the feed URL before Name on the add/edit form and clarify full-article
  detection wording in connection results.
- Default new feed source attribution to none.
- Clarify initial-import policy labels and show Initial recent count only when
  the Recent count policy is selected.
- Show the next queued post time in the feed-list Queue column and Queue tab,
  and keep initial policy details on the dedicated Queue tab.
- Prepare a safe cron publication request context so MyBB post creation has a
  loopback IP address and posting-user context during scheduled tasks.
- Include publication exception details in task summaries and show queued retry
  errors in the feed list and Operations page before they become permanent
  failures.

## 0.1.31 - 2026-09-13

- Let connection-test and feed-discovery results apply detected feed defaults
  back to the add/edit form, including feed title and summary-only linked
  full-article retrieval.
- Enable new feed configurations by default.
- Add a dedicated Queue tab for per-feed active, review, attention, terminal,
  initial-scan, and publication-pacing status.
- Reorganize the README into task-focused setup, workflow, configuration,
  operations, security, and troubleshooting sections.
- Correct stale limitations that still described full-article retrieval and
  approval moderation as unavailable.

## 0.1.30 - 2026-09-10

- Add server-side sorting to the configured-feeds table by name, feed URL,
  destination forum, posting user, interval, status, or last result.
- Keep Name and Feed sort controls together above their shared identity column.
- Generate a readable feed name from its URL when the Name field is left blank.

## 0.1.29 - 2026-09-10

- Enable newly installed Feed Publisher scheduled tasks by default so configured
  feeds begin automatic discovery and paced publishing without a hidden setup step.
- Preserve the enabled or disabled state of tasks that already exist.

## 0.1.28 - 2026-09-02

- Complete issue #24 configuration restore by matching every feed's exported
  forum name and posting username to safe local IDs during preview and import.
- Add optional global forum/user fallbacks for OPML, renamed, missing, or
  ambiguous saved targets instead of forcing one mapping onto the entire backup.
- Validate each resolved user against its resolved destination before importing,
  and report feeds skipped because no safe mapping was available.

## 0.1.27 - 2026-09-02

- Add opt-in summary-only or always-on linked-article retrieval with explicit
  feed-content, mark-seen, or fail-and-retry fallback behavior.
- Reuse DNS pinning, public-address validation, TLS verification, redirect
  rejection, 15-second timeouts, strict HTML MIME checks, and 2 MiB limits.
- Add deterministic DOM-based article extraction with 20,000-node and
  200-character substance limits, unsafe-element removal, and resolved links.
- Bound article requests to 1-10 new entries per discovery run, defer overflow
  safely, skip already-known identities, and show exact content source in dry run.
- Normalize linked-page HTTP/meta charsets to UTF-8 and correct directory-style
  HTML base and XML xml:base resolution for extracted and feed-relative links.

## 0.1.26 - 2026-09-02

- Add standards-compatible OPML export for feed names, URLs, and enabled-state
  hints plus a versioned JSON export for MyBB-specific feed configuration.
- Add bounded OPML/JSON import with a no-write preview classifying new, existing,
  repeated, invalid, and unsupported entries before any records are created.
- Require explicit destination-forum and posting-user mapping, validate posting
  permission, reset nonportable native prefixes, and default imports to disabled.
- Limit imports to 256 KiB and 500 feeds, reject unsafe rule/template settings,
  avoid all network fetching during import, and log successful administrator use.

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
