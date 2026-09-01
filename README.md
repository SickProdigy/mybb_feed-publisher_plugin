# MyBB Feed Publisher

MyBB Feed Publisher imports RSS and Atom entries into a MyBB forum. It is an
independent implementation and does not require raw HTML to be enabled in
posts.

## Current status

Early development preview. The initial foundation includes:

- plugin install/uninstall lifecycle;
- feed and imported-item database tables;
- a MyBB scheduled task entry point;
- RSS 0.9x/1.0 RDF/2.0 and Atom parsing with bounded encoding normalization;
- deterministic HTML cleanup and HTML-to-MyCode conversion;
- URL validation, response-size limits, timeouts, and duplicate detection;
- Admin CP feed management with destination forum, posting user, enabled state,
  cleanup configuration, status visibility, and per-feed intervals;
- persistent feed-entry staging with per-feed publication pacing, queue ordering,
  pause controls, bounded retries, and queue status counts;
- per-feed initial import policies for all available entries, most recent only,
  a bounded recent count, or starting after the current backlog;
- permission-aware MyBB thread creation through the official post data handler,
  with configurable source attribution, custom title text, and optional native
  MyBB thread prefixes.

## Requirements

- MyBB 1.8.x
- PHP 7.4 or newer
- PHP cURL, DOM, SimpleXML, and libxml extensions
- PHP mbstring or iconv for non-UTF-8 feeds

## Install

1. Upload the contents of `Upload` to the root of the MyBB installation.
2. Activate **Feed Publisher** under **Admin CP → Configuration → Plugins**.
3. Configure feeds under **Admin CP → Configuration → Feed Publisher**.
4. Enable the installed **Feed Publisher imports** scheduled task. The task runs
   every 5 minutes and each feed is checked only when its own interval is due.

Discovery stores entries in a persistent queue rather than publishing an entire
feed at once. Queue release uses each feed publication interval, batch limit, and
ordering preference. Due queue entries are published as threads by the configured
MyBB user after the destination forum and effective posting permissions are
validated at runtime.

Duplicate detection defaults to normalized GUID/link identity for backward
compatibility. Feeds with missing or unstable identifiers may instead use a
versioned normalized title, normalized content fingerprint, or a conservative
title-plus-content fingerprint. Title matching can merge unrelated entries with
the same headline; content matching treats meaningful edits as new entries; the
combined strategy avoids more false matches but will not recognize edited
copies. Changing strategy requires an explicit queue/import-history reset and
may make previously published source entries eligible again.

Terminal queue history has configurable age and per-state count limits and is
cleaned in batches of at most 100 records. Imported-item duplicate history is
retained forever by default; enabling age-based pruning requires explicit risk
confirmation because an old source entry may then become eligible again. Strict
source reconciliation is optional and rejects only unpublished queued entries
missing from a successful, non-empty feed scan. It is suppressed whenever the
feed's entry count unexpectedly shrinks. These policies never delete or modify
published MyBB threads or posts.

Per-feed eligibility rules can include or exclude entries by title, source URL,
category/tag, or body using plain case-insensitive substrings or bounded regular
expressions. Optional source-age, non-empty-body, and image/media requirements
run before initial-policy selection and queue staging. Dry run reports the exact
decision. Filtered and initially skipped identities are retained independently
of removable queue history; changing filters requires explicit re-evaluation
and resets only prior filter rejections.

Cleanup runs on source HTML before safe MyCode conversion. Each feed can remove
common byline/source blocks, up to 50 simple element selectors, and up to 20
validated removal-only regular expressions. Supported selectors are `tag`,
`.class`, `#id`, `tag.class`, `[attribute]`, and `tag[attribute]`, one
per line. The initial-selection preview displays the resulting cleaned MyCode.
Saved feeds also have a direct **Preview** action. Both preview paths are dry
runs: they use the production fetch, parse, cleanup, and conversion pipeline,
show detected feed format and source encoding plus existing import/queue state,
and do not write plugin or forum data.

When the exact endpoint is unknown, **Find feeds** fetches one website page and
lists only its declared RSS/RDF/Atom alternate links; it never crawls the site.
**Test connection** validates an exact endpoint without saving or queueing and
reports the safe HTTP/fetch/parse metadata, detected format and encoding, item
count, and newest source date. Both actions use the production network and XML
safety controls and never display response bodies.

Each feed may prepend arbitrary safe text such as `[RSS]` to generated subjects
and may independently select a built-in MyBB thread prefix available to its
destination forum and posting user. Prefix eligibility is checked again at
publication time, and dry run shows the exact subject plus the native prefix.

Thread dates can use the time Feed Publisher posts to MyBB or a valid source
publication time. Future-dated entries can be held, published using the current
time, marked as seen, or permanently rejected. Source dates before 1980 or more
than one year ahead are treated as invalid and safely fall back to MyBB posting
time. An optional deterministic spread of up to 60 minutes can stagger newly
queued entries without bypassing normal publication pacing.

Each feed is limited to 1,000 active queued, processing, or failed entries.
Published queue rows are retained for 90 days; the permanent imported-item
record remains for deduplication. If a task is interrupted after reserving an
item, the entry is marked **uncertain** instead of being automatically published
again. Review the destination forum before manually resolving such an entry.

The **Operations** action beside each saved feed can run discovery immediately,
publish the next bounded batch, retry failures, pause or resume publishing,
reset fetch backoff, clear eligible queue rows, and resolve uncertain outcomes.
State-changing maintenance and recovery actions require confirmation and are
recorded in MyBB's administrator log.

The **Diagnostics** tab reports PHP/MyBB/plugin versions, required extensions,
scheduled-task timing, per-feed checks/backoff/errors/queues, and recent bounded
events. Its optional diagnostic run fetches and parses at most 10 feeds without
changing feed, queue, cleanup, reconciliation, or publication state. The
copyable support report excludes usernames, secrets, content, and response
bodies; feed URLs are omitted unless explicitly requested.

On DirectAdmin, create a temporary protected PHP information page or use a
small extension check to confirm `curl`, `dom`, `libxml`, and `SimpleXML`; remove
the page afterward because full PHP information exposes server details. If the
Feed Publisher task is disabled or overdue, enable **Feed Publisher imports**
under **Admin CP -> Tools & Maintenance -> Task Manager**, confirm MyBB's task
runner is being triggered, and use Diagnostics to compare its next and last run.

## Security model

Feed content is converted to MyCode rather than posted as trusted HTML. Feed
URLs must use HTTP or HTTPS. Requests to loopback, private, reserved, and
link-local IP addresses are rejected to reduce SSRF risk. The validated address
is pinned for the connection, redirects are rejected, XML response types are
required, and responses are capped at 2 MiB. Failed discovery uses persistent
exponential backoff from 5 minutes up to 6 hours.

## Testing

Run `./tests/lint.sh` from the repository root. PHP DOM and SimpleXML are needed
for the parser and sanitizer fixtures; missing extensions are shown as skipped
tests. Stable releases also follow [the release checklist](docs/RELEASE_CHECKLIST.md)
on a real MyBB installation.

## Known limitations

- Feed redirects are rejected rather than followed.
- Feed responses are limited to 2 MiB. Incorrect content-type headers are
  tolerated only when the bounded response begins as a supported XML feed;
  HTML and unsupported XML remain rejected.
- Supported source encodings are UTF-8, BOM-marked UTF-16, ISO-8859-1, and
  Windows-1252. Conflicting HTTP, BOM, and XML declarations are rejected.
- Remote media attachments, full-article extraction, moderation, and AI cleanup
  are not part of the current Core MVP.
- Automated test doubles cannot replace the documented MyBB database, task,
  permission, and end-to-end publication checks.

## License

GNU General Public License v3.0 or later. See [LICENSE](LICENSE).
