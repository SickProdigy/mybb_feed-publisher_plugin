<?php
/**
 * Dependency-free Feed Publisher regression checks.
 * Run: php tests/run.php
 */

define('IN_MYBB', 1);
define('TIME_NOW', 1700000000);
define('TABLE_PREFIX', 'mybb_');

if (!function_exists('my_strlen')) {
    function my_strlen($value) { return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value); }
}
if (!function_exists('my_substr')) {
    function my_substr($value, $start, $length = null) {
        if (function_exists('mb_substr')) {
            return $length === null ? mb_substr($value, $start, null, 'UTF-8') : mb_substr($value, $start, $length, 'UTF-8');
        }
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}
if (!function_exists('build_prefixes')) {
    function build_prefixes($pid = 0) { return array(); }
}
if (!function_exists('is_member')) {
    function is_member($groups, $user = false) { return array(1); }
}

require_once __DIR__ . '/../Upload/inc/plugins/feedpublisher/core.php';
require_once __DIR__ . '/../Upload/inc/plugins/feedpublisher/queue.php';
require_once __DIR__ . '/../Upload/inc/plugins/feedpublisher/publisher.php';

class FeedPublisherTestSuite
{
    private $passed = 0;
    private $failed = 0;
    private $skipped = 0;

    public function test($name, $callback)
    {
        try {
            call_user_func($callback, $this);
            ++$this->passed;
            echo "PASS  {$name}\n";
        } catch (FeedPublisherTestSkipped $exception) {
            ++$this->skipped;
            echo "SKIP  {$name}: " . $exception->getMessage() . "\n";
        } catch (Throwable $exception) {
            ++$this->failed;
            echo "FAIL  {$name}: " . $exception->getMessage() . "\n";
        }
    }

    public function assertTrue($condition, $message = 'Expected condition to be true.')
    {
        if (!$condition) { throw new RuntimeException($message); }
    }

    public function assertSame($expected, $actual, $message = '')
    {
        if ($expected !== $actual) {
            throw new RuntimeException(($message ? $message . ' ' : '') . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
        }
    }

    public function assertContains($needle, $haystack, $message = '')
    {
        if (strpos($haystack, $needle) === false) {
            throw new RuntimeException(($message ? $message . ' ' : '') . 'Missing ' . var_export($needle, true) . '.');
        }
    }

    public function assertNotContains($needle, $haystack, $message = '')
    {
        if (strpos($haystack, $needle) !== false) {
            throw new RuntimeException(($message ? $message . ' ' : '') . 'Unexpected ' . var_export($needle, true) . '.');
        }
    }

    public function expectException($class, $callback)
    {
        try {
            call_user_func($callback);
        } catch (Throwable $exception) {
            if ($exception instanceof $class) { return; }
            throw new RuntimeException('Expected ' . $class . ', got ' . get_class($exception) . '.');
        }
        throw new RuntimeException('Expected ' . $class . ' to be thrown.');
    }

    public function skip($message) { throw new FeedPublisherTestSkipped($message); }

    public function finish()
    {
        echo "\n{$this->passed} passed, {$this->failed} failed, {$this->skipped} skipped.\n";
        return $this->failed === 0 ? 0 : 1;
    }
}

class FeedPublisherTestSkipped extends RuntimeException {}

class FeedPublisherArrayResult
{
    public $rows;
    public $index = 0;
    public function __construct($rows) { $this->rows = array_values($rows); }
}

class FeedPublisherQueueDb
{
    public $queue = array();
    public $items = array();
    private $affected = 0;

    public function escape_string($value) { return addslashes((string) $value); }
    public function table_exists($table) { return false; }
    public function affected_rows() { return $this->affected; }
    public function fetch_array($result) { return $result->index < count($result->rows) ? $result->rows[$result->index++] : false; }
    public function fetch_field($result, $field) { $row = $this->fetch_array($result); return $row && isset($row[$field]) ? $row[$field] : false; }

    public function simple_select($table, $fields = '*', $condition = '', $options = array())
    {
        if ($table === 'feedpublisher_queue') {
            $rows = array_values($this->queue);
            if (strpos($condition, "state='queued'") !== false) {
                $rows = array_values(array_filter($rows, function ($row) { return $row['state'] === 'queued' && $row['available_at'] <= TIME_NOW; }));
            }
            if (isset($options['limit'])) { $rows = array_slice($rows, 0, (int) $options['limit']); }
            return new FeedPublisherArrayResult($rows);
        }
        if ($table === 'feedpublisher_items') {
            $rows = array_values($this->items);
            return new FeedPublisherArrayResult($rows);
        }
        return new FeedPublisherArrayResult(array());
    }

    public function write_query($sql)
    {
        if (strpos($sql, 'INSERT IGNORE INTO ' . TABLE_PREFIX . 'feedpublisher_items') === 0
            && preg_match("/VALUES \\((\\d+), '([^']+)'/", $sql, $match)) {
            $key = (int) $match[1] . ':' . stripslashes($match[2]);
            if (isset($this->items[$key])) { $this->affected = 0; return true; }
            $this->items[$key] = array('feed_id' => (int) $match[1], 'item_key' => stripslashes($match[2]), 'tid' => 0, 'pid' => 0, 'imported_at' => 0);
            $this->affected = 1;
            return true;
        }
        $this->affected = 0;
        return true;
    }

    public function update_query($table, $data, $condition = '')
    {
        $this->affected = 0;
        if ($table === 'feedpublisher_queue' && preg_match('/id=(\\d+)/', $condition, $match)) {
            $id = (int) $match[1];
            if (isset($this->queue[$id])) {
                if (strpos($condition, "state='queued'") !== false && $this->queue[$id]['state'] !== 'queued') { return; }
                if (preg_match("/claim_token='([^']*)'/", $condition, $token) && $this->queue[$id]['claim_token'] !== stripslashes($token[1])) { return; }
                $this->queue[$id] = array_merge($this->queue[$id], $data);
                $this->affected = 1;
            }
            return;
        }
        if ($table === 'feedpublisher_items') {
            foreach ($this->items as $key => $row) {
                $this->items[$key] = array_merge($row, $data);
                $this->affected = 1;
                break;
            }
            return;
        }
        if ($table === 'feedpublisher_feeds') { $this->affected = 1; }
    }

    public function delete_query($table, $condition = '')
    {
        $this->affected = 0;
        if ($table === 'feedpublisher_items') {
            foreach ($this->items as $key => $row) {
                if ($row['tid'] == 0 && $row['pid'] == 0 && $row['imported_at'] == 0) {
                    unset($this->items[$key]);
                    ++$this->affected;
                }
            }
        }
    }
}

$suite = new FeedPublisherTestSuite;

$suite->test('identity normalization and stable keys', function ($t) {
    $a = feedpublisher_normalize_item_identity('HTTPS://Example.COM:443/post?q=1#fragment');
    $b = feedpublisher_normalize_item_identity('https://example.com/post?q=1');
    $t->assertSame($b, $a);
    $t->assertSame(feedpublisher_item_key($a), feedpublisher_item_key($b));
});

$suite->test('versioned fallback identities are deterministic', function ($t) {
    $item = array('key' => '', 'title' => '  Same &amp; TITLE ', 'content' => '<p>Hello   World</p>');
    $title = feedpublisher_derive_item_identity(array('identity_strategy' => 'title'), $item);
    $content = feedpublisher_derive_item_identity(array('identity_strategy' => 'content'), $item);
    $combined = feedpublisher_derive_item_identity(array('identity_strategy' => 'title_content'), $item);
    $t->assertSame(feedpublisher_item_key('fp-title-v1|same & title'), $title['key']);
    $t->assertContains('v1', $title['basis']);
    $t->assertTrue($content['key'] !== $combined['key']);
    $legacy = feedpublisher_derive_item_identity(array('identity_strategy' => 'guid_link'), array('key' => 'https://example.com/a', 'title' => '', 'content' => ''));
    $t->assertSame(feedpublisher_item_key('https://example.com/a'), $legacy['key']);
});

$suite->test('strict reconciliation fails safe for empty and truncated feeds', function ($t) {
    global $db;
    $db = new FeedPublisherQueueDb;
    $db->queue[1] = array('id' => 1, 'feed_id' => 7, 'item_key' => hash('sha256', 'missing'), 'state' => 'queued', 'available_at' => 0);
    $feed = array('id' => 7, 'identity_strategy' => 'guid_link', 'strict_reconciliation' => 1, 'last_feed_item_count' => 2);
    $t->assertSame(0, feedpublisher_reconcile_missing_queued($feed, array(), 100, false));
    $one = array(array('key' => 'present', 'title' => 'Present', 'content' => 'Body'));
    $t->assertSame(0, feedpublisher_reconcile_missing_queued($feed, $one, 100, false));
    $two = array($one[0], array('key' => 'other', 'title' => 'Other', 'content' => 'Body'));
    $t->assertSame(1, feedpublisher_reconcile_missing_queued($feed, $two, 100, false));
});

$suite->test('safe content URLs reject executable schemes', function ($t) {
    $t->assertSame(false, feedpublisher_safe_content_url('javascript:alert(1)'));
    $t->assertSame(false, feedpublisher_safe_content_url('data:text/html,bad'));
    $t->assertSame(true, feedpublisher_safe_content_url('https://example.com/a'));
});

$suite->test('support diagnostics redact secrets and optional URLs', function ($t) {
    $source = 'token=abc123 cookie=session42 https://user:pass@example.com/private?q=1';
    $safe = feedpublisher_safe_diagnostic_text($source, true);
    $t->assertNotContains('abc123', $safe);
    $t->assertNotContains('session42', $safe);
    $t->assertNotContains('example.com', $safe);
    $t->assertContains('[redacted URL]', $safe);
    $t->assertSame('https://example.com/feed.xml', feedpublisher_safe_report_url('https://user:pass@example.com/feed.xml?unknown_secret=abc#part'));
});

$suite->test('SSRF validation rejects local and credentialed URLs', function ($t) {
    $t->expectException('FeedPublisherException', function () { feedpublisher_resolve_url('http://127.0.0.1/feed'); });
    $t->expectException('FeedPublisherException', function () { feedpublisher_resolve_url('https://user:pass@example.com/feed'); });
});

$suite->test('cleanup rules validate selectors and regexes', function ($t) {
    $t->assertSame(array(), feedpublisher_cleanup_validate_rules(".author\nfooter", "~tracking~i"));
    $errors = feedpublisher_cleanup_validate_rules('div > script', '~[~');
    $t->assertTrue(count($errors) >= 2, 'Invalid selector and regex should both be reported.');
});

$suite->test('title prefix preserves MyBB 85-character subject limit', function ($t) {
    $subject = feedpublisher_build_subject(str_repeat('x', 100), '[RSS]');
    $t->assertSame(85, my_strlen($subject));
    $t->assertContains('[RSS] ', $subject);
    $t->assertSame('[RSS] News Headline', feedpublisher_build_subject('News Headline', " [RSS]\n"));
});

$suite->test('source attribution rejects unsafe links', function ($t) {
    $message = feedpublisher_add_source_attribution('Body', array('source_url' => 'javascript:bad', 'title' => 'Title'), 'link');
    $t->assertSame('Body', $message);
    $safe = feedpublisher_add_source_attribution('Body', array('source_url' => 'https://example.com/a', 'title' => 'Title'), 'title_link');
    $t->assertContains('[url=https://example.com/a]Title[/url]', $safe);
});

$suite->test('future-date policies and dateline fallback', function ($t) {
    $base = array('thread_date_mode' => 'source', 'schedule_jitter_minutes' => 0);
    $future = TIME_NOW + 3600;
    foreach (array('hold' => 'queued', 'clamp' => 'queued', 'skip' => 'skipped', 'reject' => 'rejected') as $policy => $state) {
        $plan = feedpublisher_source_date_plan($base + array('future_date_policy' => $policy), array('key' => $policy, 'published' => $future), TIME_NOW);
        $t->assertSame($state, $plan['state']);
    }
    $t->assertSame(TIME_NOW - 60, feedpublisher_thread_dateline($base, array('source_published' => TIME_NOW - 60), TIME_NOW));
    $t->assertSame(TIME_NOW, feedpublisher_thread_dateline($base, array('source_published' => TIME_NOW + 60), TIME_NOW));
});

$suite->test('deterministic jitter is stable and bounded', function ($t) {
    $feed = array('future_date_policy' => 'clamp', 'thread_date_mode' => 'publish', 'schedule_jitter_minutes' => 60);
    $item = array('key' => 'stable-jitter-entry-2', 'published' => TIME_NOW - 60);
    $a = feedpublisher_source_date_plan($feed, $item, TIME_NOW);
    $b = feedpublisher_source_date_plan($feed, $item, TIME_NOW);
    $t->assertSame($a, $b);
    $t->assertTrue($a['jitter_seconds'] >= 0 && $a['jitter_seconds'] <= 3600);
});

$suite->test('reservation prevents concurrent duplicate publication', function ($t) {
    global $db;
    $db = new FeedPublisherQueueDb;
    $feed = array('id' => 7);
    $item = array('item_key' => hash('sha256', 'same'), 'source_url' => 'https://example.com/a');
    $t->assertTrue(feedpublisher_queue_reserve($feed, $item));
    $t->assertSame(false, feedpublisher_queue_reserve($feed, $item));
});

$suite->test('publication failure releases reservation and requeues item', function ($t) {
    global $db;
    $db = new FeedPublisherQueueDb;
    $db->queue[1] = array('id' => 1, 'feed_id' => 7, 'item_key' => hash('sha256', 'failure'), 'source_url' => 'https://example.com/a',
        'state' => 'queued', 'available_at' => 0, 'attempts' => 0, 'claim_token' => '', 'source_published' => 0, 'discovered_at' => 1);
    $feed = array('id' => 7, 'publishing_paused' => 0, 'publish_interval_minutes' => 5, 'last_published' => 0,
        'max_posts_per_run' => 1, 'queue_order' => 'oldest');
    $result = feedpublisher_queue_dispatch($feed, function () { throw new RuntimeException('publisher failed'); });
    $t->assertSame(1, $result['failed']);
    $t->assertSame(array(), $db->items);
    $t->assertSame('queued', $db->queue[1]['state']);
    $t->assertContains('publisher failed', $db->queue[1]['last_error']);
});

$suite->test('RSS, RDF, and Atom fixtures parse with format metadata', function ($t) {
    if (!extension_loaded('SimpleXML') || !extension_loaded('dom')) { $t->skip('PHP SimpleXML and DOM are not installed.'); }
    $metadata = array();
    $rss = feedpublisher_parse(file_get_contents(__DIR__ . '/fixtures/rss.xml'), array(), $metadata);
    $t->assertSame('fixture-rss-1', $rss[0]['key']);
    $t->assertSame('RSS 2.0', $metadata['format']);
    $legacy = feedpublisher_parse(file_get_contents(__DIR__ . '/fixtures/rss-092.xml'), array(), $metadata);
    $t->assertSame('https://example.com/legacy-entry', $legacy[0]['key']);
    $t->assertSame('RSS 0.92', $metadata['format']);
    $rdf = feedpublisher_parse(file_get_contents(__DIR__ . '/fixtures/rss-rdf.xml'), array('url' => 'https://example.com/root/feed.rdf'), $metadata);
    $t->assertSame('rdf-entry', $rdf[0]['key']);
    $t->assertSame('https://example.com/news/posts/rdf-entry?source=feed', $rdf[0]['url']);
    $t->assertSame('RSS 1.0 (RDF)', $metadata['format']);
    $atom = feedpublisher_parse(file_get_contents(__DIR__ . '/fixtures/atom.xml'), array('url' => 'https://fallback.example/feed'), $metadata);
    $t->assertSame('fixture-atom-1', $atom[0]['key']);
    $t->assertSame('https://example.com/articles/atom-entry?view=full#content', $atom[0]['url']);
    $t->assertSame('Atom', $metadata['format']);
});

$suite->test('feed encodings normalize to UTF-8 and conflicts fail closed', function ($t) {
    $detected = '';
    $latin = "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?><rss version=\"2.0\"><channel><title>Caf\xE9</title></channel></rss>";
    $normalized = feedpublisher_normalize_xml_encoding($latin, 'ISO-8859-1', $detected);
    $t->assertSame('ISO-8859-1', $detected);
    $t->assertContains('Caf' . "\xC3\xA9", $normalized);
    $t->expectException('FeedPublisherException', function () use ($latin) { feedpublisher_normalize_xml_encoding($latin, 'UTF-8'); });
    $t->expectException('FeedPublisherException', function () { feedpublisher_normalize_xml_encoding('<?xml version="1.0" encoding="KOI8-R"?><rss/>'); });
});

$suite->test('relative content URLs preserve query strings and fragments', function ($t) {
    $t->assertSame('https://example.com/a/post?q=1#part', feedpublisher_resolve_relative_content_url('../post?q=1#part', 'https://example.com/a/b/feed.xml'));
});

$suite->test('website discovery reads only declared RSS and Atom links', function ($t) {
    if (!extension_loaded('dom')) { $t->skip('PHP DOM is not installed.'); }
    $feeds = feedpublisher_extract_declared_feeds(file_get_contents(__DIR__ . '/fixtures/feed-links.html'), 'https://example.com/index.html');
    $t->assertSame(2, count($feeds));
    $t->assertSame('https://example.com/public/feeds/news.xml', $feeds[0]['url']);
    $t->assertSame('https://example.com/atom.xml', $feeds[1]['url']);
    $t->assertSame('News Atom', $feeds[1]['declared_title']);
});

$suite->test('malformed and entity-bearing XML fail closed', function ($t) {
    if (!extension_loaded('SimpleXML') || !extension_loaded('dom')) { $t->skip('PHP SimpleXML and DOM are not installed.'); }
    $t->expectException('FeedPublisherException', function () { feedpublisher_parse(file_get_contents(__DIR__ . '/fixtures/malformed.xml')); });
    $t->expectException('FeedPublisherException', function () { feedpublisher_parse(file_get_contents(__DIR__ . '/fixtures/doctype.xml')); });
});

$suite->test('unsafe HTML is removed before MyCode conversion', function ($t) {
    if (!extension_loaded('dom')) { $t->skip('PHP DOM is not installed.'); }
    $html = file_get_contents(__DIR__ . '/fixtures/unsafe.html');
    $clean = feedpublisher_cleanup_html($html, array('remove_bylines' => 0, 'remove_source_links' => 0, 'strip_selectors' => '', 'strip_regexes' => ''), 'https://example.com/source');
    $mycode = feedpublisher_html_to_mycode($clean);
    $t->assertNotContains('script', strtolower($mycode));
    $t->assertNotContains('javascript:', strtolower($mycode));
    $t->assertNotContains('onerror', strtolower($mycode));
    $t->assertContains('[b]text[/b]', $mycode);
    $t->assertContains('[url=https://example.com/good]good link[/url]', $mycode);
});

$suite->test('lifecycle and upgrade guards remain present', function ($t) {
    $source = file_get_contents(__DIR__ . '/../Upload/inc/plugins/feedpublisher.php');
    $t->assertContains("if (!\$db->table_exists('feedpublisher_feeds'))", $source);
    $t->assertContains("if (!\$db->field_exists(\$name, 'feedpublisher_feeds'))", $source);
    $t->assertContains("file='feedpublisher'", $source);
    $t->assertContains("drop_table('feedpublisher_queue')", $source);
    $t->assertContains("feedpublisher_install_logs_table", $source);
    $t->assertContains("drop_table('feedpublisher_logs')", $source);
    $t->assertContains("delete_query('tasks'", $source);
});

$suite->test('documentation covers deployment and known safety limits', function ($t) {
    $readme = file_get_contents(__DIR__ . '/../README.md');
    foreach (array('## Requirements', '## Install', 'scheduled task', 'Admin CP', '## Security model', '2 MiB') as $text) {
        $t->assertContains($text, $readme);
    }
});

exit($suite->finish());
