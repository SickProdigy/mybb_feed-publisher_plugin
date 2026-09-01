# MyBB Feed Publisher

MyBB Feed Publisher imports RSS and Atom entries into a MyBB forum. It is an
independent implementation and does not require raw HTML to be enabled in
posts.

## Current status

Early development preview. The initial foundation includes:

- plugin install/uninstall lifecycle;
- feed and imported-item database tables;
- a MyBB scheduled task entry point;
- RSS 2.0 and Atom parsing;
- deterministic HTML cleanup and HTML-to-MyCode conversion;
- URL validation, response-size limits, timeouts, and duplicate detection;
- Admin CP feed management with destination forum, posting user, enabled state,
  cleanup configuration, status visibility, and per-feed intervals;
- persistent feed-entry staging with per-feed publication pacing, queue ordering,
  pause controls, bounded retries, and queue status counts;
- per-feed initial import policies for all available entries, most recent only,
  a bounded recent count, or starting after the current backlog;
- permission-aware MyBB thread creation through the official post data handler,
  with configurable source attribution.

## Requirements

- MyBB 1.8.x
- PHP 7.4 or newer
- PHP cURL, DOM, SimpleXML, and libxml extensions

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

Cleanup runs on source HTML before safe MyCode conversion. Each feed can remove
common byline/source blocks, up to 50 simple element selectors, and up to 20
validated removal-only regular expressions. Supported selectors are `tag`,
`.class`, `#id`, `tag.class`, `[attribute]`, and `tag[attribute]`, one
per line. The initial-selection preview displays the resulting cleaned MyCode.

Each feed is limited to 1,000 active queued, processing, or failed entries.
Published queue rows are retained for 90 days; the permanent imported-item
record remains for deduplication. If a task is interrupted after reserving an
item, the entry is marked **uncertain** instead of being automatically published
again. Review the destination forum before manually resolving such an entry.

## Security model

Feed content is converted to MyCode rather than posted as trusted HTML. Feed
URLs must use HTTP or HTTPS. Requests to loopback, private, reserved, and
link-local IP addresses are rejected to reduce SSRF risk.

## License

GNU General Public License v3.0 or later. See [LICENSE](LICENSE).
