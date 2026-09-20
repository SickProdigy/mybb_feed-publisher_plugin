# MyBB Feed Publisher

Feed Publisher imports RSS, RDF, and Atom entries as MyBB threads. It uses
MyBB's post data handler, converts remote HTML to safe MyCode, and does not
require raw HTML to be enabled in posts.

> **Status:** Stable release (`1.0.5`). Test upgrades and feed behavior on a
> non-production MyBB installation before deployment.

## Highlights

- RSS 0.9x, RSS 1.0/RDF, RSS 2.0, and Atom parsing
- Per-feed destination forum, posting user, schedule, and publication pacing
- Persistent queue with retries, approval workflow, and recovery controls
- Duplicate detection, entry filters, source cleanup, and MyCode conversion
- Optional linked full-article retrieval and media handling
- Dry-run previews, feed discovery, connection testing, and diagnostics
- OPML feed-list export and versioned JSON configuration backups
- Bounded network, parsing, storage, and cleanup operations

## Requirements

- MyBB 1.8.x
- PHP 7.4 or newer
- PHP cURL, DOM, SimpleXML, and libxml extensions
- PHP mbstring or iconv for non-UTF-8 feeds

## Install

1. Upload the contents of `Upload/` to the MyBB installation root.
2. Activate **Feed Publisher** under **Admin CP -> Configuration -> Plugins**.
3. Open **Admin CP -> Configuration -> Feed Publisher**.
4. Add a feed URL, destination forum, and posting user.
5. Use **Test connection** or **Preview** before enabling the feed.
6. Confirm **Feed Publisher imports** is enabled under **Tools & Maintenance ->
   Task Manager**.

New installations enable the scheduled task automatically. It runs every five
minutes, but each feed is fetched and published only when its own intervals are
due. New feed configurations are enabled by default and initially check every
240 minutes (about six times daily), publish every 360 minutes (about four times
daily), and release at most one queued item per publishing run. Existing feed
schedules are preserved during upgrades.

## How Publishing Works

Feed Publisher separates discovery from publication:

1. The scheduled task fetches feeds that are due.
2. Parsed entries pass through eligibility, cleanup, and duplicate checks.
3. Accepted entries are stored in a persistent queue.
4. Due queue entries are released according to each feed's publication
   interval, batch limit, and oldest/newest ordering preference.
5. MyBB validates the destination forum and effective posting permissions.
6. The entry is published through MyBB's official post data handler.

This prevents a large source feed from publishing its entire backlog at once.
Each feed is limited to 1,000 active queued, processing, or failed entries.

## Managing Feeds

The configured-feeds table can be sorted by name, feed URL, destination forum,
posting user, fetch interval, status, or last result.

Feed names remain editable. When Name is left blank, Feed Publisher generates a
readable name from the URL host and useful path parts.

The **Queue** tab shows per-feed active, review, attention, and terminal queue
counts along with initial-scan policy, publication pacing, and the next queued
post time. The feeds list keeps only compact queue totals and next-post timing.

### Finding and Testing Feeds

Use **Find feeds** when you know a website address but not its feed endpoint. It
fetches one page and lists declared RSS, RDF, and Atom alternate links. It does
not crawl the website. When exactly one usable feed is found, its detected
defaults are applied to the form automatically; when several are found, choose
one from the inline results.

Use **Test connection** to validate an exact endpoint without saving, queueing,
or publishing anything. The result includes safe fetch metadata, detected feed
format and encoding, item count, and newest valid source date. Response bodies
are never displayed. Successful tests apply detected defaults on the same form:
the parsed feed title and summary-only linked full-article retrieval when feed
entries look like short teasers or include "read the full article" style links.
For explicit read-more/full-article teaser feeds, it also defaults full-article
failure to retrying a few times before marking the entry seen without
publishing.

Use **Preview** to run the production fetch, parse, cleanup, conversion, and
composition path without writing plugin or forum data. Preview also reports
existing import and queue state.

## Initial Import and Duplicates

Each feed can begin with one of four initial-import policies:

- Import every available entry
- Import only the most recent entry, exactly one item
- Import a bounded number of recent entries using **Initial recent count**
- Mark the current backlog as seen and begin with future entries

Duplicate detection defaults to normalized GUID/link identity. Feeds with
missing or unstable identifiers can instead use normalized title, normalized
content, or conservative title-plus-content fingerprints.

- Title matching can merge unrelated entries that share a headline.
- Content matching treats meaningful edits as new entries.
- Title-plus-content avoids more false matches but does not recognize edited
  copies.

Changing the identity strategy requires an explicit queue/import-history reset
and can make previously published source entries eligible again.

## Entry Eligibility

Per-feed rules can include or exclude entries by title, source URL, category or
tag, and body content. Rules support plain case-insensitive substrings or bounded
regular expressions.

Optional checks can also require a source-age range, body text, or image or
media metadata. The body-text check looks for text after HTML tags are removed,
so image-only entries need that option disabled. Eligibility runs before
initial-policy selection and queue staging, and dry run reports the exact
decision, including whether rejected entries had body text or media present.

Filtered and initially skipped identities are stored independently from
removable queue history. Changing filters requires explicit re-evaluation and
resets only previous filter rejections.

## Cleanup and Conversion

Source HTML is cleaned before deterministic HTML-to-MyCode conversion. A feed
can remove common byline/source blocks and define:

- Up to 50 simple element selectors
- Up to 20 validated removal-only regular expressions

Supported selectors are `tag`, `.class`, `#id`, `tag.class`, `[attribute]`, and
`tag[attribute]`, with one selector per line. Preview shows the resulting cleaned
MyCode.

## Post Composition

Each feed can configure:

- Plain title text such as `[RSS]`
- A native MyBB thread prefix available to the forum and posting user

Each feed can also remove title text with one validated PHP-compatible regular
expression before the plain title prefix is added. For example, using
`~^Now Available on Steam\s*-\s*~i` and setting the title prefix to `[Steam]`
turns `Now Available on Steam - SCUM` into `[Steam] SCUM`. Titles that do not
match the expression are unchanged.
- Optional MyCode header and footer templates
- A word-safe body character limit
- Optional continuation text and source link
- Source attribution behavior

New feeds default to no separate source-attribution line. Attribution can be
changed per feed to append a plain source link or linked source title.

Header and footer templates accept only `{title}`, `{source_url}`, `{feed_name}`,
`{author}`, and `{published_date}`. Feed values are escaped before substitution;
templates never execute PHP or raw HTML. Prefix eligibility is checked again at
publication time.

## Media

Feed Publisher recognizes RSS enclosures, Media RSS content and thumbnails, and
Atom enclosure links. Each feed can ignore media, append the first extra image
only when the converted body has no `[img]`, append safe ordinary links, or
hotlink images with MyBB's `[img]` code. Videos and unknown file types become
ordinary links in link and hotlink modes, and are ignored by the image-fallback
mode. This setting controls extra feed metadata outside the entry body; body
images and links are converted normally. New feeds default to appending the
first extra image only when the body has no image. Extra media can be placed
before or after the imported body; new feeds default to before the body.

At most 10 distinct HTTP/HTTPS media URLs are retained per entry. The plugin
does not download attachments, inspect remote files, create local media, or emit
iframe/embed HTML.

## Linked Full Articles

Optional linked full-article retrieval can replace short feed summaries with
content extracted from the public article URL. New feeds default to summary
mode, which runs when feed text is below a configurable threshold (600
characters by default) or when the feed content looks like a teaser with a
"read the full article" or "continue reading" link. It can also run for every
new entry. Explicit read-more/full-article teaser feeds discovered by Test
connection default to retrying extraction a few times, then skipping the entry
when the linked article still cannot be extracted.

Feeds whose entry links use a stale article host can optionally rewrite the URL
before full-article retrieval. Supply both a PHP-compatible match regex and a
replacement; only the first match is replaced. For example,
`~^https://editors\.charlieintel\.com/~i` with
`https://www.charlieintel.com/` keeps the original feed URL for duplicate
identity while fetching and attributing a successfully extracted article to
the rewritten URL. Rewritten URLs must still pass the normal public HTTP(S),
DNS, and private-address checks. Feed Publisher never guesses alternate hosts.

Article extraction:

- Reuses DNS pinning, private-address blocking, TLS verification, and the
  no-redirect policy
- Uses a 15-second timeout and 2 MiB response limit
- Requires an HTML response
- Removes executable and navigation elements
- Scores article/main containers and requires substantial content
- Resolves relative links before normal cleanup and MyCode conversion

Failure can retain the feed content, mark the entry seen, retry a few times and
then mark seen, or fail discovery for retry. A configurable limit of 1-10 article
requests per run defers overflow without losing entries.

Maintainers can audit every unique endpoint in the local recommendation list
with `php tests/audit_feeds.php`. Add `--fulltext` to attempt one linked article
per feed, or `--limit=N` to change the number of rendered entries inspected.
This is an opt-in live network check; it is not part of `tests/run.php`.

## Thread Dates

Threads can use the MyBB publication time or a valid source publication time.
Future-dated entries can be held, published with the current time, marked seen,
or permanently rejected. Dates before 1980 or more than one year in the future
are treated as invalid and fall back to MyBB publication time.

An optional deterministic spread of up to 60 minutes can stagger newly queued
entries without bypassing publication pacing.

## Approval Workflow

A feed can publish automatically or require administrator approval. Approval
mode stores cleaned entries as **Awaiting approval**, outside scheduled
publication.

The **Review queue** displays the stored prepared and final output without
another remote request. Authorized administrators can:

- Approve the original entry
- Edit the prepared title/body and approve
- Defer review for 24 hours
- Reject the entry permanently

Approved entries return to normal pacing and publication validation. Rejected
identities remain recorded for duplicate detection.

## Operations and Diagnostics

The **Operations** action for a saved feed can run discovery, publish the next
bounded batch, retry failures, pause or resume publishing, reset fetch backoff,
clear eligible queue rows, and resolve uncertain publication outcomes.
State-changing actions require confirmation and are recorded in MyBB's
administrator log.

The **Diagnostics** tab reports plugin, MyBB, PHP, extension, scheduled-task,
feed, queue, retry, and recent-event health. Its optional diagnostic run fetches
and parses at most 10 feeds without changing queue or publication state.
If the Feed Publisher task shows an overdue next run, use **Reschedule task** to
recalculate its next run time and clear any stale lock. If it becomes overdue
again, confirm the MyBB task runner image or cron job is actually firing.

The copyable support report excludes usernames, secrets, content, and response
bodies. Feed URLs are omitted unless explicitly requested.

## Import and Export

The **Import / export** tab provides standards-compatible OPML feed-list export,
versioned JSON configuration backup, and a no-write import preview.

Imports accept up to 256 KiB and 500 feeds and never fetch feed URLs. Full JSON
restores match saved forum names and posting usernames to safe local IDs;
exported numeric IDs are never trusted. Optional fallback targets handle OPML
and renamed, missing, or ambiguous local names.

Every resolved forum/user pair is permission checked. Native prefix IDs are
reset, unsafe entries are skipped, and feeds start disabled unless preserving
their exported enabled state is explicitly selected. Queue/history records,
operational logs, and credentials are not exported.

## Retention and Recovery

Terminal queue history has configurable age and per-state count limits. Cleanup
processes at most 100 records at a time. Published queue rows are retained for
90 days by default.

Imported-item duplicate history is retained indefinitely by default. Age-based
pruning requires explicit risk confirmation because an old source entry can
become eligible again.

Optional strict source reconciliation rejects only unpublished queued entries
missing from a successful, non-empty scan. It is suppressed when the source
entry count unexpectedly shrinks. Retention and reconciliation never delete or
modify published MyBB threads or posts.

If a task stops after reserving an item, the entry becomes **uncertain** rather
than being published automatically again. Review the destination forum before
resolving it through Operations.

Failed discovery uses persistent exponential backoff from five minutes up to
six hours.

## Security model

- Feed content is converted to MyCode instead of trusted HTML.
- Feed and article URLs must use HTTP or HTTPS.
- Loopback, private, reserved, and link-local addresses are rejected.
- Validated DNS addresses are pinned for the connection.
- Redirects are rejected.
- Feed responses are limited to 2 MiB.
- XML response types are required, with a bounded XML-sniffing fallback for
  incorrectly labeled feeds.
- Configuration actions use MyBB permissions, post-key protection, confirmation,
  and administrator logging where applicable.

## Troubleshooting

If imports are not running:

1. Open **Admin CP -> Tools & Maintenance -> Task Manager**.
2. Confirm **Feed Publisher imports** is enabled.
3. Confirm MyBB's task runner is being triggered.
4. Compare the task's last and next run times in **Diagnostics**.
5. Use **Test connection** or a diagnostic run to isolate fetch/parse failures.

On DirectAdmin, verify the required PHP extensions with a protected temporary
PHP information page or a minimal extension check. Remove full PHP information
pages immediately after use because they expose server details.

## Known limitations

- Feed and article redirects are rejected rather than followed.
- Feed and article responses are limited to 2 MiB.
- Supported source encodings are UTF-8, BOM-marked UTF-16, ISO-8859-1, and
  Windows-1252. Conflicting HTTP, BOM, and XML declarations are rejected.
- Remote attachment downloads, iframe embeds, and AI cleanup are not provided.
- Automated test doubles do not replace real MyBB database, task, permission,
  and end-to-end publication checks.

## Testing

Run:

```bash
./tests/lint.sh
```

PHP DOM and SimpleXML are needed for parser and sanitizer fixtures. Tests that
need unavailable extensions report themselves as skipped. Stable releases also
follow the [release checklist](docs/RELEASE_CHECKLIST.md) on a real MyBB
installation.

## License

GNU General Public License v3.0 or later. See [LICENSE](LICENSE).
