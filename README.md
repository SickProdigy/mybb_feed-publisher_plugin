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
  cleanup configuration, status visibility, and per-feed intervals.

## Requirements

- MyBB 1.8.x
- PHP 7.4 or newer
- PHP DOM and SimpleXML extensions

## Install

1. Upload the contents of `Upload` to the root of the MyBB installation.
2. Activate **Feed Publisher** under **Admin CP → Configuration → Plugins**.
3. Configure feeds under **Admin CP → Configuration → Feed Publisher**.
4. Enable the installed **Feed Publisher imports** scheduled task. The task runs
   every 5 minutes and each feed is checked only when its own interval is due.

## Security model

Feed content is converted to MyCode rather than posted as trusted HTML. Feed
URLs must use HTTP or HTTPS. Requests to loopback, private, reserved, and
link-local IP addresses are rejected to reduce SSRF risk.

## License

GNU General Public License v3.0 or later. See [LICENSE](LICENSE).
