<?php
/**
 * Live feed quality audit. This intentionally performs network requests.
 * Run: php tests/audit_feeds.php [--fulltext] [--limit=3]
 */

define('IN_MYBB', 1);
define('TIME_NOW', time());

if (!function_exists('my_strlen')) {
    function my_strlen($value) { return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value); }
}
if (!function_exists('my_substr')) {
    function my_substr($value, $start, $length = null) {
        if (function_exists('mb_substr')) return $length === null ? mb_substr($value, $start, null, 'UTF-8') : mb_substr($value, $start, $length, 'UTF-8');
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}

require_once __DIR__ . '/../Upload/inc/plugins/feedpublisher/core.php';
require_once __DIR__ . '/../Upload/inc/plugins/feedpublisher/publisher.php';
require_once __DIR__ . '/../Upload/inc/plugins/feedpublisher/fulltext.php';

if (!function_exists('curl_init')) {
    fwrite(STDERR, "The PHP cURL extension is required. Enable it before running this live audit.\n");
    exit(2);
}

$fulltext = in_array('--fulltext', $argv, true);
$limit = 3;
foreach ($argv as $argument) {
    if (preg_match('/^--limit=(\d+)$/', $argument, $match)) $limit = max(1, min(10, (int) $match[1]));
}

$recommendationFile = __DIR__ . '/../LOCAL_RSS_FEED_RECOMMENDATIONS.md';
if (is_file($recommendationFile)) {
    preg_match_all('~`(https?://[^`]+)`~', file_get_contents($recommendationFile), $matches);
    $urls = array_values(array_unique($matches[1]));
} else {
    $urls = array(
        'https://news.xbox.com/en-us/feed/', 'https://blog.playstation.com/feed/',
        'https://store.steampowered.com/feeds/newreleases.xml', 'https://www.nintendolife.com/feeds/latest',
        'https://www.gamespot.com/feeds/news/', 'https://www.nintendo.co.jp/news/whatsnew.xml',
        'https://store.steampowered.com/feeds/news.xml', 'https://www.charlieintel.com/feed/',
        'https://mcbe.news/news/official/rss.xml', 'https://www.wowhead.com/news/rss/all',
        'https://www.wowhead.com/diablo-4/news/rss/all', 'https://www.wowhead.com/news/rss/retail',
        'https://www.bungie.net/en/Rss/NewsByCategory', 'https://fedoramagazine.org/feed/',
        'https://ubuntu.com/blog/feed', 'https://www.phoronix.com/rss.php', 'https://blogs.microsoft.com/feed/',
        'https://appleinsider.com/rss/news/', 'https://devblogs.microsoft.com/dotnet/feed/',
        'https://www.pockettactics.com/mainrss.xml', 'https://gamefromscratch.com/feed/',
        'https://steamcommunity.com/groups/GrabFreeGames/rss/', 'https://steamcommunity.com/groups/indiegala/rss/',
        'https://www.php.net/feed.atom', 'https://blog.python.org/feeds/posts/default',
        'https://inside.java/feed.xml', 'https://developers.redhat.com/blog/feed',
    );
}
$failures = 0;
$warnings = 0;

echo 'Feed Publisher live audit: ' . count($urls) . ' unique feeds; ' . $limit . ' entries each'
    . ($fulltext ? '; one full-article attempt per feed' : '') . "\n\n";

foreach ($urls as $url) {
    $fetch = array();
    try {
        $xml = feedpublisher_fetch($url, 2097152, $fetch);
        $parse = array();
        $items = feedpublisher_parse($xml, $fetch, $parse);
    } catch (Throwable $exception) {
        ++$failures;
        echo "FAIL {$url}\n  " . feedpublisher_safe_log_text($exception->getMessage()) . "\n\n";
        continue;
    }

    $feedWarnings = array();
    if (!$items) $feedWarnings[] = 'Feed parsed but supplied no entries.';
    $sample = array_slice($items, 0, $limit);
    $seenLinks = array();
    $examples = array();
    foreach ($sample as $index => $item) {
        $entryWarnings = feedpublisher_audit_item($item);
        if (!empty($item['url'])) {
            if (isset($seenLinks[$item['url']])) $entryWarnings[] = 'Duplicate entry URL within the sample.';
            $seenLinks[$item['url']] = true;
        }
        try {
            $prepared = feedpublisher_prepare_item(array(), $item);
            $renderItem = $item;
            $renderItem['content'] = $prepared['content'];
            $renderItem['source_url'] = $item['url'];
            $post = feedpublisher_compose_post(array('attribution_mode' => 'link', 'media_mode' => 'fallback_image', 'media_position' => 'top'), $renderItem);
            $rendered = trim(preg_replace('/\s+/u', ' ', strip_tags($post['body'])));
            if (my_strlen($rendered) < 80) $entryWarnings[] = 'Rendered post is unusually thin (' . my_strlen($rendered) . ' characters).';
            if (my_strlen($rendered) > 50000) $entryWarnings[] = 'Rendered post is unusually large (' . my_strlen($rendered) . ' characters); consider a body length limit.';
            if (preg_match('/(?:&#x20;|all synced posts|more official news|cookie preferences|accept all cookies)/i', $post['body'], $match)) {
                $entryWarnings[] = 'Rendered post contains suspicious page furniture: ' . $match[0] . '.';
            }
            $examples[] = ($index + 1) . '. ' . $post['title'] . ' [' . my_strlen($rendered) . ' chars]';
        } catch (Throwable $exception) {
            $entryWarnings[] = 'Preparation failed: ' . feedpublisher_safe_log_text($exception->getMessage());
        }
        foreach ($entryWarnings as $warning) $feedWarnings[] = 'Entry ' . ($index + 1) . ': ' . $warning;
    }

    if ($fulltext && $sample) {
        $entry = array('state' => 'queued', 'item' => $sample[0]);
        $auditFeed = array('id' => 0, 'identity_strategy' => 'guid_link', 'fulltext_mode' => 'always',
            'fulltext_fallback' => 'feed', 'fulltext_summary_chars' => 600, 'fulltext_max_per_run' => 1,
            'fulltext_url_regex' => '', 'fulltext_url_replacement' => '');
        if (parse_url($sample[0]['url'], PHP_URL_HOST) === 'editors.charlieintel.com') {
            $auditFeed['fulltext_url_regex'] = '~^https://editors\\.charlieintel\\.com/~i';
            $auditFeed['fulltext_url_replacement'] = 'https://www.charlieintel.com/';
        }
        $result = feedpublisher_fulltext_prepare_plan($auditFeed, array($entry));
        $status = $result[0]['item']['_fulltext'];
        if ($status['source'] !== 'fulltext') $feedWarnings[] = 'Full article: ' . $status['message'];
        else {
            $extractedCharacters = (int) $status['extract']['text_characters'];
            $examples[] = '   Full article: ' . $extractedCharacters . ' extracted chars';
            if ($extractedCharacters > 100000) $feedWarnings[] = 'Full article is unusually large; configure a body length limit.';
        }
    }

    $format = isset($parse['format']) ? $parse['format'] : 'unknown format';
    echo ($feedWarnings ? 'WARN' : 'PASS') . " {$url}\n  {$format}; " . count($items) . ' entries; HTTP '
        . (isset($fetch['http_status']) ? $fetch['http_status'] : '?') . "\n";
    foreach ($examples as $example) echo "  {$example}\n";
    foreach ($feedWarnings as $warning) { ++$warnings; echo "  ! {$warning}\n"; }
    echo "\n";
}

echo "Summary: {$failures} feed failures; {$warnings} warnings.\n";
exit($failures ? 1 : 0);

function feedpublisher_audit_item($item)
{
    $warnings = array();
    $title = trim((string) (isset($item['title']) ? $item['title'] : ''));
    $url = trim((string) (isset($item['url']) ? $item['url'] : ''));
    $content = (string) (isset($item['content']) ? $item['content'] : '');
    $plain = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    if ($title === '') $warnings[] = 'Missing title.';
    if ($url === '' || !feedpublisher_safe_content_url($url)) $warnings[] = 'Missing or unsafe article URL.';
    if ($plain === '') $warnings[] = 'Missing body content.';
    elseif (my_strlen($plain) < 120) $warnings[] = 'Feed body is very short (' . my_strlen($plain) . ' characters).';
    if (strpos($content, '&#x20;') !== false) $warnings[] = 'Feed body contains literal &#x20; spacing artifacts.';
    if (preg_match('/(?:all synced posts|more official news|cookie preferences|accept all cookies)/i', $plain, $match)) {
        $warnings[] = 'Feed body contains likely page furniture: ' . $match[0] . '.';
    }
    if (!empty($item['published']) && (int) $item['published'] > TIME_NOW + 86400) $warnings[] = 'Publication date is more than one day in the future.';
    return $warnings;
}
