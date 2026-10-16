<?php
/**
 * Dependency-free Feed Publisher regression checks.
 * Run: php tests/run.php
 */

define('IN_MYBB', 1);
define('IN_ADMINCP', 1);
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
if (!function_exists('delete_thread')) {
    function delete_thread($tid) {
        $GLOBALS['feedpublisher_test_deleted_threads'][] = (int) $tid;
        return !in_array((int) $tid, $GLOBALS['feedpublisher_test_failed_threads'], true);
    }
}

require_once __DIR__ . '/../Upload/inc/plugins/feedpublisher/core.php';
require_once __DIR__ . '/../Upload/inc/plugins/feedpublisher/queue.php';
require_once __DIR__ . '/../Upload/inc/plugins/feedpublisher/publisher.php';
require_once __DIR__ . '/../Upload/inc/plugins/feedpublisher/fulltext.php';
require_once __DIR__ . '/../Upload/inc/plugins/feedpublisher/portability.php';
require_once __DIR__ . '/../Upload/inc/plugins/feedpublisher/operations.php';
require_once __DIR__ . '/../Upload/inc/plugins/feedpublisher/webhook.php';
require_once __DIR__ . '/../Upload/inc/plugins/feedpublisher/ai.php';

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
                $requireDue = strpos($condition, 'available_at<=') !== false;
                $rows = array_values(array_filter($rows, function ($row) use ($requireDue) {
                    return $row['state'] === 'queued' && (!$requireDue || $row['available_at'] <= TIME_NOW);
                }));
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

class FeedPublisherThreadDb extends FeedPublisherQueueDb
{
    public $threads = array();

    public function simple_select($table, $fields = '*', $condition = '', $options = array())
    {
        if ($table === 'threads') {
            preg_match('/tid=(\d+)/', $condition, $match);
            $tid = isset($match[1]) ? (int) $match[1] : 0;
            return new FeedPublisherArrayResult(isset($this->threads[$tid]) ? array(array('tid' => $tid)) : array());
        }
        if ($table === 'feedpublisher_items' || $table === 'feedpublisher_queue') {
            $rows = array_values($table === 'feedpublisher_items' ? $this->items : $this->queue);
            preg_match('/feed_id=(\d+)/', $condition, $feedMatch);
            preg_match('/tid=(\d+)/', $condition, $tidMatch);
            $rows = array_values(array_filter($rows, function ($row) use ($condition, $feedMatch, $tidMatch) {
                if ($feedMatch && (int) $row['feed_id'] !== (int) $feedMatch[1]) { return false; }
                if (strpos($condition, 'tid>0') !== false && empty($row['tid'])) { return false; }
                if ($tidMatch && (int) $row['tid'] !== (int) $tidMatch[1]) { return false; }
                return true;
            }));
            return new FeedPublisherArrayResult($rows);
        }
        return parent::simple_select($table, $fields, $condition, $options);
    }

    public function update_query($table, $data, $condition = '')
    {
        if ($table === 'feedpublisher_items' || $table === 'feedpublisher_queue') {
            if ($table === 'feedpublisher_items') {
                $rows =& $this->items;
            } else {
                $rows =& $this->queue;
            }
            preg_match('/feed_id=(\d+)/', $condition, $feedMatch);
            preg_match('/tid=(\d+)/', $condition, $tidMatch);
            foreach ($rows as $key => $row) {
                if ((int) $row['feed_id'] === (int) $feedMatch[1] && (int) $row['tid'] === (int) $tidMatch[1]) {
                    $rows[$key] = array_merge($row, $data);
                }
            }
            return;
        }
        parent::update_query($table, $data, $condition);
    }
}

class FeedPublisherRetryDb extends FeedPublisherQueueDb
{
    public $retries = array();
    private $nextRetryId = 1;

    public function simple_select($table, $fields = '*', $condition = '', $options = array())
    {
        if ($table !== 'feedpublisher_fulltext_retries') return parent::simple_select($table, $fields, $condition, $options);
        preg_match('/feed_id=(\d+)/', $condition, $feedMatch);
        preg_match("/item_key='([^']+)'/", $condition, $keyMatch);
        $rows = array_values(array_filter($this->retries, function ($row) use ($feedMatch, $keyMatch) {
            return (!$feedMatch || (int) $row['feed_id'] === (int) $feedMatch[1])
                && (!$keyMatch || $row['item_key'] === stripslashes($keyMatch[1]));
        }));
        if (strpos($fields, 'MIN(next_attempt_at)') !== false) {
            $values = array_map(function ($row) { return (int) $row['next_attempt_at']; }, $rows);
            return new FeedPublisherArrayResult(array(array('next_retry' => $values ? min($values) : 0)));
        }
        return new FeedPublisherArrayResult($rows);
    }

    public function insert_query($table, $record)
    {
        if ($table === 'feedpublisher_fulltext_retries') {
            $record['id'] = $this->nextRetryId++;
            $this->retries[$record['feed_id'] . ':' . $record['item_key']] = $record;
        }
    }

    public function update_query($table, $data, $condition = '')
    {
        if ($table !== 'feedpublisher_fulltext_retries') return parent::update_query($table, $data, $condition);
        preg_match('/id=(\d+)/', $condition, $match);
        foreach ($this->retries as $key => $row) {
            if ((int) $row['id'] === (int) $match[1]) $this->retries[$key] = array_merge($row, $data);
        }
    }

    public function delete_query($table, $condition = '')
    {
        if ($table !== 'feedpublisher_fulltext_retries') return parent::delete_query($table, $condition);
        preg_match('/feed_id=(\d+)/', $condition, $feedMatch);
        preg_match("/item_key='([^']+)'/", $condition, $keyMatch);
        foreach ($this->retries as $key => $row) {
            if ((!$feedMatch || (int) $row['feed_id'] === (int) $feedMatch[1])
                && (!$keyMatch || $row['item_key'] === stripslashes($keyMatch[1]))) unset($this->retries[$key]);
        }
    }
}

class FeedPublisherWebhookDb extends FeedPublisherQueueDb
{
    public $deliveries = array();
    private $webhookAffected = 0;

    public function affected_rows() { return $this->webhookAffected; }

    public function simple_select($table, $fields = '*', $condition = '', $options = array())
    {
        if ($table !== 'feedpublisher_webhook_queue') return parent::simple_select($table, $fields, $condition, $options);
        preg_match('/feed_id=(\d+)/', $condition, $feedMatch);
        preg_match("/state='([^']+)'/", $condition, $stateMatch);
        $rows = array_values(array_filter($this->deliveries, function ($row) use ($condition, $feedMatch, $stateMatch) {
            if ($feedMatch && (int) $row['feed_id'] !== (int) $feedMatch[1]) return false;
            if ($stateMatch && $row['state'] !== $stateMatch[1]) return false;
            if (strpos($condition, 'next_attempt_at<=') !== false && (int) $row['next_attempt_at'] > TIME_NOW) return false;
            return true;
        }));
        if (strpos($fields, 'COUNT(') !== false) return new FeedPublisherArrayResult(array(array('total' => count($rows))));
        if (isset($options['limit'])) $rows = array_slice($rows, 0, (int) $options['limit']);
        return new FeedPublisherArrayResult($rows);
    }

    public function update_query($table, $data, $condition = '')
    {
        if ($table !== 'feedpublisher_webhook_queue') return parent::update_query($table, $data, $condition);
        $this->webhookAffected = 0;
        preg_match('/id=(\d+)/', $condition, $idMatch);
        preg_match("/state='([^']+)'/", $condition, $stateMatch);
        foreach ($this->deliveries as $key => $row) {
            if ($idMatch && (int) $row['id'] !== (int) $idMatch[1]) continue;
            if ($stateMatch && $row['state'] !== $stateMatch[1]) continue;
            $this->deliveries[$key] = array_merge($row, $data);
            ++$this->webhookAffected;
        }
    }

    public function delete_query($table, $condition = '')
    {
        if ($table !== 'feedpublisher_webhook_queue') return parent::delete_query($table, $condition);
        $this->webhookAffected = 0;
        foreach ($this->deliveries as $key => $row) {
            if (strpos($condition, "state='delivered'") !== false && $row['state'] !== 'delivered') continue;
            if (preg_match('/delivered_at<(\d+)/', $condition, $match) && (int) $row['delivered_at'] >= (int) $match[1]) continue;
            unset($this->deliveries[$key]);
            ++$this->webhookAffected;
        }
    }

    public function insert_query($table, $record) {}
}

$suite = new FeedPublisherTestSuite;

$suite->test('feed thread deletion deduplicates IDs and preserves published history', function ($t) {
    global $db;
    $db = new FeedPublisherThreadDb;
    $db->threads = array(11 => true, 22 => true);
    $db->items = array(
        array('feed_id' => 7, 'item_key' => 'a', 'tid' => 11, 'pid' => 101, 'imported_at' => TIME_NOW),
        array('feed_id' => 7, 'item_key' => 'b', 'tid' => 22, 'pid' => 102, 'imported_at' => TIME_NOW),
        array('feed_id' => 7, 'item_key' => 'c', 'tid' => 33, 'pid' => 103, 'imported_at' => TIME_NOW),
        array('feed_id' => 8, 'item_key' => 'other', 'tid' => 44, 'pid' => 104, 'imported_at' => TIME_NOW),
    );
    $db->queue = array(
        array('id' => 1, 'feed_id' => 7, 'tid' => 11, 'pid' => 101),
        array('id' => 2, 'feed_id' => 7, 'tid' => 33, 'pid' => 103),
    );
    $GLOBALS['feedpublisher_test_deleted_threads'] = array();
    $GLOBALS['feedpublisher_test_failed_threads'] = array(22);

    $t->assertSame(array(11, 22, 33), feedpublisher_feed_thread_ids(7));
    $result = feedpublisher_delete_feed_threads(7);
    $t->assertSame(array('deleted' => 1, 'missing' => 1, 'failed' => 1), $result);
    $t->assertSame(array(11, 22), $GLOBALS['feedpublisher_test_deleted_threads']);
    $t->assertSame(0, $db->items[0]['tid']);
    $t->assertSame(22, $db->items[1]['tid']);
    $t->assertSame(0, $db->items[2]['tid']);
    $t->assertSame(44, $db->items[3]['tid']);
    $t->assertSame('a', $db->items[0]['item_key']);
});

$suite->test('Discord webhook configuration is restricted and templates are bounded', function ($t) {
    global $mybb;
    $mybb = new stdClass;
    $mybb->settings = array('bburl' => 'https://forum.example.com');
    $valid = 'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz_ABCDEF-123';
    $t->assertSame(true, feedpublisher_discord_webhook_url_valid($valid));
    $t->assertSame(false, feedpublisher_discord_webhook_url_valid('http://discord.com/api/webhooks/123/token'));
    $t->assertSame(false, feedpublisher_discord_webhook_url_valid('https://evil.example/api/webhooks/123/abcdefghijklmnopqrstuvwxyz'));
    $t->assertSame(false, feedpublisher_discord_webhook_url_valid($valid . '?wait=true'));
    $t->assertSame(array(), feedpublisher_discord_template_errors("{title}\n{thread_url}"));
    $t->assertSame(1, count(feedpublisher_discord_template_errors('{unknown}')));
    $rendered = feedpublisher_discord_render(
        array('name' => 'News', 'discord_message_template' => "{title}\n{thread_url}\n{source_url}"),
        array('title' => 'Published title', 'tid' => 42, 'source_url' => 'https://source.example/story')
    );
    $t->assertContains('Published title', $rendered);
    $t->assertContains('https://forum.example.com/showthread.php?tid=42', $rendered);
    $t->assertTrue(my_strlen(feedpublisher_discord_render(
        array('discord_message_template' => '{title}'),
        array('title' => str_repeat('x', 2500), 'tid' => 1)
    )) <= 2000);
});

$suite->test('Discord delivery succeeds or backs off without changing MyBB publication state', function ($t) {
    global $db, $mybb;
    $mybb = new stdClass;
    $mybb->settings = array('bburl' => 'https://forum.example.com');
    $url = 'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz_ABCDEF-123';
    $feed = array('id' => 7, 'name' => 'News', 'discord_enabled' => 1, 'discord_webhook_url' => $url,
        'discord_message_template' => "{title}\n{thread_url}");
    $base = array('feed_id' => 7, 'item_key' => 'one', 'tid' => 42, 'title' => 'Title',
        'source_url' => 'https://source.example/story', 'state' => 'queued', 'attempts' => 0,
        'next_attempt_at' => TIME_NOW, 'last_attempt_at' => 0, 'last_error' => '', 'delivered_at' => 0);

    $db = new FeedPublisherWebhookDb;
    $db->deliveries[1] = array_merge(array('id' => 1), $base);
    $sent = array();
    $success = function ($target, $content) use (&$sent) {
        $sent = array($target, $content);
        return array('ok' => true, 'status' => 200, 'retry_after' => 0);
    };
    $result = feedpublisher_webhook_dispatch($feed, 5, $success);
    $t->assertSame(array('delivered' => 1, 'failed' => 0), $result);
    $t->assertSame('delivered', $db->deliveries[1]['state']);
    $t->assertSame($url, $sent[0]);
    $t->assertContains('showthread.php?tid=42', $sent[1]);

    $db = new FeedPublisherWebhookDb;
    $db->deliveries[2] = array_merge(array('id' => 2), $base);
    $limited = function () { return array('ok' => false, 'status' => 429, 'retry_after' => 7); };
    $result = feedpublisher_webhook_dispatch($feed, 5, $limited);
    $t->assertSame(array('delivered' => 0, 'failed' => 1), $result);
    $t->assertSame('queued', $db->deliveries[2]['state']);
    $t->assertSame(1, $db->deliveries[2]['attempts']);
    $t->assertSame(TIME_NOW + 7, $db->deliveries[2]['next_attempt_at']);
    $t->assertContains('HTTP 429', $db->deliveries[2]['last_error']);
    $t->assertSame(42, $db->deliveries[2]['tid']);
});

$suite->test('feed names can be generated safely from URLs', function ($t) {
    $t->assertSame(
        'news.example.com - topics / space news',
        feedpublisher_name_from_url('https://www.News.Example.com/topics/space-news/feed.xml?token=secret')
    );
    $t->assertSame('feeds.example.com', feedpublisher_name_from_url('https://feeds.example.com/rss.php'));
    $t->assertSame('example.com - news today', feedpublisher_name_from_url('https://example.com/news%00today/feed.xml'));
    $t->assertSame('', feedpublisher_name_from_url('not a URL'));
});

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
    $t->assertSame(false, feedpublisher_safe_content_url('https://user:pass@example.com/private'));
    $t->assertSame(false, feedpublisher_safe_content_url('http://127.0.0.1/private'));
    $t->assertSame(false, feedpublisher_safe_content_url('http://localhost/private'));
    $t->assertSame(false, feedpublisher_safe_media_url('http://service.local/image.jpg'));
    $t->assertSame(true, feedpublisher_safe_content_url('https://example.com/a'));
});

$suite->test('remote fetches use browser-style request headers', function ($t) {
    $t->assertContains('Firefox/', feedpublisher_fetch_user_agent());
    $headers = feedpublisher_fetch_headers('text/html, application/xhtml+xml');
    $t->assertContains('Accept: text/html, application/xhtml+xml', implode("\n", $headers));
    $t->assertContains('Accept-Language: en-US,en;q=0.9', implode("\n", $headers));
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

$suite->test('entry eligibility explains include, exclude, age, and content decisions', function ($t) {
    $item = array('title' => 'Free weekend game', 'url' => 'https://example.com/game', 'content' => '<p>Play now</p>',
        'categories' => array('Giveaways'), 'has_media' => true, 'published' => TIME_NOW - 86400);
    $feed = array('eligibility_rules' => "include category: giveaways\nexclude title: sponsored",
        'minimum_source_age_hours' => 12, 'maximum_source_age_days' => 7, 'require_entry_body' => 1, 'require_entry_media' => 1);
    $pass = feedpublisher_entry_eligibility($feed, $item);
    $t->assertSame(true, $pass['eligible']);
    $blocked = $item; $blocked['title'] = 'Sponsored free weekend';
    $blockedResult = feedpublisher_entry_eligibility($feed, $blocked);
    $t->assertSame(false, $blockedResult['eligible']);
    $t->assertContains('exclude title: sponsored', $blockedResult['reason']);
    $missingMedia = $item; $missingMedia['has_media'] = false;
    $t->assertContains('body text present: yes', feedpublisher_entry_eligibility($feed, $missingMedia)['reason']);
    $imageOnly = $item; $imageOnly['content'] = '<img src="https://example.com/image.jpg" alt="">';
    $imageOnly['media'] = array(array('url' => 'https://example.com/image.jpg', 'kind' => 'image'));
    $t->assertContains('media present: yes', feedpublisher_entry_eligibility($feed, $imageOnly)['reason']);
    $errors = array(); feedpublisher_eligibility_rules('include-regex title: ~[~', $errors);
    $t->assertSame(1, count($errors));
});

$suite->test('feed test suggests title and summary full text defaults', function ($t) {
    $defaults = feedpublisher_suggest_feed_defaults(array('title' => 'Example Feed'), array(
        array('url' => 'https://example.com/a', 'content' => '<p>Short teaser</p>', 'media' => array(
            array('url' => 'https://example.com/image.jpg', 'kind' => 'image'),
        )),
        array('url' => 'https://example.com/b', 'content' => str_repeat('complete ', 100), 'media' => array()),
        array('url' => 'https://example.com/c', 'content' => '<p>' . str_repeat('teaser ', 100) . '</p><p>Read the full article on example.com</p>', 'media' => array()),
    ));
    $t->assertSame('Example Feed', $defaults['name']);
    $t->assertSame('fallback_image', $defaults['media_mode']);
    $t->assertSame('summary', $defaults['fulltext_mode']);
    $t->assertSame('retry_skip', $defaults['fulltext_fallback']);
    $t->assertSame(1, $defaults['media_items']);
    $t->assertSame(1, $defaults['media_urls']);
    $t->assertSame(2, $defaults['short_items']);
    $t->assertSame(1, $defaults['teaser_items']);
});

$suite->test('title prefix preserves MyBB 85-character subject limit', function ($t) {
    $subject = feedpublisher_build_subject(str_repeat('x', 100), '[RSS]');
    $t->assertSame(85, my_strlen($subject));
    $t->assertContains('[RSS] ', $subject);
    $t->assertSame('[RSS] News Headline', feedpublisher_build_subject('News Headline', " [RSS]\n"));
});

$suite->test('source title text can be removed by regex before adding a feed prefix', function ($t) {
    $regex = '~^Now Available on Steam\s*-\s*~i';
    $t->assertSame('', feedpublisher_title_strip_regex_error($regex));
    $t->assertSame('SCUM', feedpublisher_transform_title('Now Available on Steam - SCUM', $regex));
    $t->assertSame('Unrelated release', feedpublisher_transform_title('Unrelated release', $regex));
    $t->assertSame('Now Available on Steam -', feedpublisher_transform_title('Now Available on Steam -', $regex));
    $t->assertContains('valid PHP-compatible', feedpublisher_title_strip_regex_error('~[~'));
    $t->assertSame('[Steam] SCUM', feedpublisher_build_subject(
        feedpublisher_transform_title('Now Available on Steam - SCUM', $regex),
        '[Steam]'
    ));
});

$suite->test('source attribution rejects unsafe links', function ($t) {
    $message = feedpublisher_add_source_attribution('Body', array('source_url' => 'javascript:bad', 'title' => 'Title'), 'link');
    $t->assertSame('Body', $message);
    $safe = feedpublisher_add_source_attribution('Body', array('source_url' => 'https://example.com/a', 'title' => 'Title'), 'title_link');
    $t->assertContains('[url=https://example.com/a]Title[/url]', $safe);
});

$suite->test('post templates, placeholders, excerpts, and attribution compose deterministically', function ($t) {
    $feed = array('name' => 'Release feed', 'title_prefix' => '[News]', 'title_strip_regex' => '~^Release:\\s*~', 'post_header' => '[b]{feed_name}[/b]',
        'post_footer' => 'By {author} on {published_date}', 'body_length_limit' => 12,
        'continuation_mode' => 'source_link', 'continuation_text' => 'Read the rest', 'attribution_mode' => 'link');
    $item = array('title' => 'Release: A title', 'content' => 'One two three four five', 'source_url' => 'https://example.com/post',
        'author' => 'Writer', 'source_published' => 1700000000);
    $post = feedpublisher_compose_post($feed, $item);
    $t->assertSame('[News] A title', $post['title']);
    $t->assertSame(true, $post['truncated']);
    $t->assertContains('[b]Release feed[/b]', $post['body']);
    $t->assertContains('One two...', $post['body']);
    $t->assertContains('[url=https://example.com/post]Read the rest[/url]', $post['body']);
    $t->assertContains('By Writer on 2023-11-14 22:13:20', $post['body']);
    $t->assertContains('Source: [url=https://example.com/post]', $post['body']);
    $t->assertSame(1, count(feedpublisher_template_errors('{unknown}', '')));
});

$suite->test('default post composition preserves the existing body behavior', function ($t) {
    $post = feedpublisher_compose_post(array('attribution_mode' => 'none'), array('title' => 'Title', 'content' => '[b]Complete body[/b]'));
    $t->assertSame('[b]Complete body[/b]', $post['body']);
    $t->assertSame(false, $post['truncated']);
    $media = array(array('url' => 'https://example.com/image.jpg', 'kind' => 'image'));
    $bottom = feedpublisher_compose_post(array('attribution_mode' => 'none', 'media_mode' => 'fallback_image', 'media_position' => 'bottom'), array('title' => 'Title', 'content' => 'Body', 'media' => $media));
    $top = feedpublisher_compose_post(array('attribution_mode' => 'none', 'media_mode' => 'fallback_image', 'media_position' => 'top'), array('title' => 'Title', 'content' => 'Body', 'media' => $media));
    $t->assertSame("Body\n\n[img]https://example.com/image.jpg[/img]", $bottom['body']);
    $t->assertSame("[img]https://example.com/image.jpg[/img]\n\nBody", $top['body']);
});

$suite->test('post composition safely shortens content beyond the MyBB message limit', function ($t) {
    global $mybb;
    $originalMybb = isset($mybb) ? $mybb : null;
    $mybb = (object) array('settings' => array('maxmessagelength' => 1200));
    $post = feedpublisher_compose_post(array('attribution_mode' => 'link'), array(
        'title' => 'Oversized article',
        'content' => '[b]' . str_repeat('large article content — ', 22000) . '[/b]',
        'source_url' => 'https://example.com/oversized-article',
    ));
    $mybb = $originalMybb;
    $t->assertSame(true, $post['truncated']);
    $t->assertTrue(strlen($post['body']) <= 1200);
    $t->assertContains('This article was shortened', $post['body']);
    $t->assertContains('Source: [url=https://example.com/oversized-article]', $post['body']);
    $t->assertNotContains('[b]', $post['body']);
});

$suite->test('a zero MyBB message limit leaves generated posts unlimited', function ($t) {
    global $mybb;
    $originalMybb = isset($mybb) ? $mybb : null;
    $mybb = (object) array('settings' => array('maxmessagelength' => 0));
    $post = feedpublisher_compose_post(array('attribution_mode' => 'none'), array(
        'title' => 'Unlimited article', 'content' => str_repeat('content ', 10000),
    ));
    $mybb = $originalMybb;
    $t->assertSame(false, $post['truncated']);
    $t->assertTrue(my_strlen($post['body']) > 65535);
});

$suite->test('media composition hotlinks only images and safely links other media', function ($t) {
    $media = array(
        array('url' => 'https://example.com/image.jpg', 'kind' => 'image'),
        array('url' => 'https://example.com/video.mp4', 'kind' => 'video'),
        array('url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'kind' => 'video'),
        array('url' => 'javascript:alert(1)', 'kind' => 'image'),
        array('url' => 'http://127.0.0.1/private.jpg', 'kind' => 'image'),
    );
    $hotlinks = feedpublisher_compose_media($media, 'hotlink');
    $t->assertContains('[img]https://example.com/image.jpg[/img]', $hotlinks);
    $t->assertContains('[url=https://example.com/video.mp4]View video[/url]', $hotlinks);
    $t->assertContains('[video=youtube]https://www.youtube.com/watch?v=dQw4w9WgXcQ[/video]', $hotlinks);
    $t->assertNotContains('javascript:', $hotlinks);
    $t->assertNotContains('127.0.0.1', $hotlinks);
    $links = feedpublisher_compose_media($media, 'links');
    $t->assertContains('[url=https://example.com/image.jpg]View image[/url]', $links);
    $fallback = feedpublisher_compose_media($media, 'fallback_image', 'Body without an image');
    $t->assertSame('[img]https://example.com/image.jpg[/img]', $fallback);
    $t->assertSame('', feedpublisher_compose_media($media, 'fallback_image', '[img]https://example.com/existing.jpg[/img]'));
    $t->assertSame('', feedpublisher_compose_media($media, 'ignore'));
});

$suite->test('portability validates targets and normalizes imported configuration', function ($t) {
    $t->assertSame(true, feedpublisher_portability_url_valid('https://example.com/feed.xml'));
    $t->assertSame(false, feedpublisher_portability_url_valid('http://127.0.0.1/feed'));
    $t->assertSame(false, feedpublisher_portability_url_valid('http://localhost/feed'));
    $t->assertSame(false, feedpublisher_portability_url_valid('http://service.local/feed'));
    $t->assertSame(false, feedpublisher_portability_url_valid('https://user:pass@example.com/feed'));
    $parsed = feedpublisher_portability_parse(json_encode(array('format' => 'mybb-feed-publisher-config', 'version' => 1,
        'feeds' => array(array('name' => 'Imported', 'url' => 'https://example.com/feed', 'interval_minutes' => 1, 'enabled' => 1)))));
    $t->assertSame('config', $parsed['type']);
    $record = feedpublisher_portability_defaults($parsed['entries'][0], 5, 9, false);
    $t->assertSame(5, $record['fid']);
    $t->assertSame(9, $record['uid']);
    $t->assertSame(5, $record['interval_minutes']);
    $t->assertSame(0, $record['enabled']);
    $t->assertSame(0, $record['thread_prefix_id']);
    $t->assertSame(1, $record['require_entry_body']);
    $t->assertSame('fallback_image', $record['media_mode']);
    $t->assertSame('top', $record['media_position']);
    $defaults = feedpublisher_portability_defaults(array('name' => 'Defaults', 'url' => 'https://example.com/defaults.xml'), 5, 9, false);
    $t->assertSame(240, $defaults['interval_minutes']);
    $t->assertSame(360, $defaults['publish_interval_minutes']);
    $t->assertSame(1, $defaults['max_posts_per_run']);
    $mapping = feedpublisher_portability_resolve_mapping(
        array('destination_forum' => 'Gaming News', 'posting_username' => 'FeedBot'),
        array(0 => 'Fallback', 5 => 'Gaming News'), array(0 => 'Fallback', 9 => 'FeedBot'), 2, 3, true
    );
    $t->assertSame(5, $mapping['fid']);
    $t->assertSame(9, $mapping['uid']);
    $fallback = feedpublisher_portability_resolve_mapping(
        array('destination_forum' => 'Renamed', 'posting_username' => 'Missing'),
        array(0 => 'Fallback', 5 => 'Gaming News'), array(0 => 'Fallback', 9 => 'FeedBot'), 2, 3, true
    );
    $t->assertSame(2, $fallback['fid']);
    $t->assertSame(3, $fallback['uid']);
});

$suite->test('full-text planning applies fallback and defers beyond the request bound', function ($t) {
    $feed = array('id' => 0, 'identity_strategy' => 'guid_link', 'fulltext_mode' => 'always',
        'fulltext_fallback' => 'feed', 'fulltext_summary_chars' => 600, 'fulltext_max_per_run' => 1);
    $summaryFeed = $feed;
    $summaryFeed['fulltext_mode'] = 'summary';
    $longTeaser = array('state' => 'queued', 'item' => array('key' => 'teaser', 'title' => 'Teaser', 'url' => 'javascript:bad',
        'content' => str_repeat('teaser ', 100) . ' Read the full article on example.com'));
    $summaryResult = feedpublisher_fulltext_prepare_plan($summaryFeed, array($longTeaser));
    $t->assertSame('fallback', $summaryResult[0]['item']['_fulltext']['status']);
    $plan = array(
        array('state' => 'queued', 'item' => array('key' => 'one', 'title' => 'One', 'url' => 'javascript:bad', 'content' => 'Summary')),
        array('state' => 'queued', 'item' => array('key' => 'two', 'title' => 'Two', 'url' => 'https://example.com/two', 'content' => 'Summary')),
    );
    $result = feedpublisher_fulltext_prepare_plan($feed, $plan);
    $t->assertSame('fallback', $result[0]['item']['_fulltext']['status']);
    $t->assertSame('queued', $result[0]['state']);
    $t->assertSame('deferred_fulltext', $result[1]['state']);
    $t->assertSame('deferred', $result[1]['item']['_fulltext']['status']);
    $t->assertSame(feedpublisher_derive_item_identity($feed, $plan[0]['item'])['key'], $result[0]['item']['_identity_override']['key']);
    $feed['fulltext_fallback'] = 'skip';
    $skipped = feedpublisher_fulltext_prepare_plan($feed, array($plan[0]));
    $t->assertSame('skipped', $skipped[0]['state']);
    $feed['fulltext_fallback'] = 'retry_skip';
    $retryScheduled = feedpublisher_fulltext_prepare_plan($feed, array($plan[0]));
    $t->assertSame('deferred_fulltext', $retryScheduled[0]['state']);
    $t->assertSame('retry-scheduled', $retryScheduled[0]['item']['_fulltext']['status']);
    $feed['fulltext_fallback'] = 'retry';
    $retryForever = feedpublisher_fulltext_prepare_plan($feed, array($plan[0]));
    $t->assertSame('deferred_fulltext', $retryForever[0]['state']);
});

$suite->test('full-text retries are isolated per entry and exhaust after three retries', function ($t) {
    global $db;
    $db = new FeedPublisherRetryDb;
    $feed = array('id' => 9, 'identity_strategy' => 'guid_link', 'fulltext_mode' => 'always',
        'fulltext_fallback' => 'retry_skip', 'fulltext_summary_chars' => 600, 'fulltext_max_per_run' => 2);
    $plan = array(
        array('state' => 'queued', 'item' => array('key' => 'one', 'title' => 'One', 'url' => 'javascript:one', 'content' => 'Summary one')),
        array('state' => 'queued', 'item' => array('key' => 'two', 'title' => 'Two', 'url' => 'javascript:two', 'content' => 'Summary two')),
    );
    $first = feedpublisher_fulltext_prepare_plan($feed, $plan);
    $t->assertSame('deferred_fulltext', $first[0]['state']);
    $t->assertSame('deferred_fulltext', $first[1]['state']);
    $t->assertSame(2, count($db->retries));
    $keys = array_keys($db->retries);
    for ($attempt = 2; $attempt <= 4; ++$attempt) {
        $db->retries[$keys[0]]['next_attempt_at'] = TIME_NOW;
        $result = feedpublisher_fulltext_prepare_plan($feed, $plan);
    }
    $t->assertSame('skipped', $result[0]['state']);
    $t->assertSame('retry-exhausted', $result[0]['item']['_fulltext']['status']);
    $t->assertSame(1, count($db->retries));
    $remaining = reset($db->retries);
    $t->assertSame(1, $remaining['attempts']);
    $t->assertSame((int) $remaining['next_attempt_at'], feedpublisher_fulltext_next_retry_at(9));
});

$suite->test('full-text URL rewriting is bounded, validated, and preserves the original identity', function ($t) {
    $feed = array('identity_strategy' => 'guid_link',
        'fulltext_url_regex' => '~^https://editors\\.example\\.com/~i',
        'fulltext_url_replacement' => 'https://www.example.com/');
    $original = 'https://editors.example.com/call-of-duty/article-338208/';
    $identity = feedpublisher_derive_item_identity($feed, array('url' => $original, 'title' => 'Article', 'content' => 'Teaser'));
    $metadata = array();
    $rewritten = feedpublisher_fulltext_rewrite_url($feed, $original, $metadata);
    $t->assertSame('https://www.example.com/call-of-duty/article-338208/', $rewritten);
    $t->assertSame(true, $metadata['rewritten']);
    $t->assertSame($original, $metadata['original_url']);
    $t->assertSame($identity['key'], feedpublisher_derive_item_identity($feed, array('url' => $original, 'title' => 'Article', 'content' => 'Teaser'))['key']);

    $unchanged = feedpublisher_fulltext_rewrite_url($feed, 'https://example.com/article', $metadata);
    $t->assertSame('https://example.com/article', $unchanged);
    $t->assertSame(false, $metadata['rewritten']);
    $t->assertTrue(count(feedpublisher_fulltext_url_rewrite_errors('~example~', '')) === 1);
    $t->assertTrue(count(feedpublisher_fulltext_url_rewrite_errors('~[~', 'https://example.com/')) === 1);
    $unsafe = $feed;
    $unsafe['fulltext_url_replacement'] = 'http://127.0.0.1/';
    $t->expectException('FeedPublisherException', function () use ($unsafe, $original) {
        feedpublisher_fulltext_rewrite_url($unsafe, $original);
    });
});

$suite->test('full-text extraction selects article content and resolves safe relative links', function ($t) {
    if (!extension_loaded('dom')) { $t->skip('PHP DOM is not installed.'); }
    $html = '<html><body><header>Navigation</header><article class="post-content"><h1>Story</h1>'
        . '<p>This is a substantial first paragraph containing enough useful article words for deterministic extraction and testing.</p>'
        . '<p>This second paragraph adds more meaningful article content and includes <a href="../source">a source link</a>.</p>'
        . '<script>alert(1)</script></article><aside>Related links</aside></body></html>';
    $metadata = array();
    $article = feedpublisher_fulltext_extract($html, 'https://example.com/news/story/page.html', $metadata);
    $t->assertContains('substantial first paragraph', $article);
    $t->assertContains('https://example.com/news/source', $article);
    $t->assertNotContains('alert(1)', $article);
    $t->assertTrue($metadata['text_characters'] >= 200);
    $latin = "<p>Caf\xE9 article</p>";
    $t->assertContains('Caf' . "\xC3\xA9", feedpublisher_fulltext_normalize_encoding($latin, 'ISO-8859-1'));
});

$suite->test('full-text extraction prefers a focused article body over page furniture', function ($t) {
    if (!extension_loaded('dom')) { $t->skip('PHP DOM is not installed.'); }
    $html = '<html><body><main><article><div class="article-header"><p>Hero caption and source metadata should not be imported into the post body.</p></div>'
        . '<div class="mc-rich-content"><p>This focused article body contains the real opening paragraph with enough useful words to qualify as substantial readable content.</p>'
        . '<p>A second meaningful paragraph keeps the focused body eligible and should remain in the extracted article output for readers.</p></div>'
        . '<section class="more-news"><p>More Official News</p><p>Unrelated article cards should not be imported.</p></section>'
        . '</article></main></body></html>';
    $metadata = array();
    $article = feedpublisher_fulltext_extract($html, 'https://example.com/news/story.html', $metadata);
    $t->assertContains('focused article body', $article);
    $t->assertNotContains('Hero caption', $article);
    $t->assertNotContains('More Official News', $article);
});

$suite->test('full-text extraction removes image lightbox controls without dropping article media', function ($t) {
    if (!extension_loaded('dom')) { $t->skip('PHP DOM is not installed.'); }
    $html = '<html><body><article><h1>Story</h1>'
        . '<p>This article starts with enough useful words to be selected as the main story content without depending on unrelated page furniture or navigation blocks.</p>'
        . '<figure><img src="/image.jpg" alt="Cast member"><a href="/image.jpg">View and download image</a><button>Download the image</button><button>close</button><a href="/image.jpg">Download this image</a></figure>'
        . '<p>This second paragraph keeps the article substantial and includes a legitimate <a href="/download">download here</a> link that should remain available.</p>'
        . '</article></body></html>';
    $metadata = array();
    $article = feedpublisher_fulltext_extract($html, 'https://example.com/news/story.html', $metadata);
    $t->assertContains('https://example.com/image.jpg', $article);
    $t->assertContains('download here', $article);
    $t->assertNotContains('View and download image', $article);
    $t->assertNotContains('Download the image', $article);
    $t->assertNotContains('Download this image', $article);
    $t->assertNotContains('>close<', strtolower($article));
});

$suite->test('full-text extraction promotes lazy images and removes placeholders', function ($t) {
    if (!extension_loaded('dom')) { $t->skip('PHP DOM is not installed.'); }
    $html = '<html><body><article><h1>Story</h1>'
        . '<p>This article starts with enough useful words to select the article body while testing lazy image normalization safely.</p>'
        . '<figure><img src="/placeholder.svg" data-src="/media/real.jpg" alt="Real image"><figcaption>Real caption</figcaption></figure>'
        . '<img src="/transparent.gif" data-srcset="/media/small.jpg 320w, /media/large.jpg 1280w" alt="Responsive image">'
        . '<img src="/blank.png" data-src="http://127.0.0.1/private.jpg" alt="Unsafe image">'
        . '<img src="/media/ordinary.jpg" alt="Ordinary image">'
        . '<p>A second substantial paragraph ensures extraction remains deterministic and keeps the surrounding article content intact.</p>'
        . '</article></body></html>';
    $article = feedpublisher_fulltext_extract($html, 'https://example.com/news/story.html');
    $t->assertContains('https://example.com/media/real.jpg', $article);
    $t->assertContains('https://example.com/media/large.jpg', $article);
    $t->assertContains('https://example.com/media/ordinary.jpg', $article);
    $t->assertContains('Real caption', $article);
    $t->assertNotContains('placeholder.svg', $article);
    $t->assertNotContains('transparent.gif', $article);
    $t->assertNotContains('blank.png', $article);
    $t->assertNotContains('127.0.0.1', $article);
    $t->assertNotContains('data-src', $article);
});

$suite->test('full-text extraction removes common article chrome while preserving real links', function ($t) {
    if (!extension_loaded('dom')) { $t->skip('PHP DOM is not installed.'); }
    $html = '<html><body><main><article><h1>Story</h1>'
        . '<p>This article begins with a useful paragraph that should remain because it explains the story in normal prose for readers and has enough words to be clearly article content.</p>'
        . '<div class="share-tools"><a href="/share">Share this article</a><button>Copy link</button></div>'
        . '<p>This second paragraph includes an ordinary <a href="/source">source link</a> and should not be damaged by cleanup of social widgets or ad placeholders.</p>'
        . '<div id="newsletter-signup">Subscribe for more stories</div><div class="advertisement">Advertisement</div>'
        . '<h2>Filed under</h2><p>Tags that should not become part of the imported article.</p><h2>Keep reading</h2><p>Related links should be omitted.</p>'
        . '</article></main></body></html>';
    $metadata = array();
    $article = feedpublisher_fulltext_extract($html, 'https://example.com/news/story.html', $metadata);
    $t->assertContains('useful paragraph', $article);
    $t->assertContains('https://example.com/source', $article);
    $t->assertNotContains('Share this article', $article);
    $t->assertNotContains('Copy link', $article);
    $t->assertNotContains('Subscribe for more stories', $article);
    $t->assertNotContains('Advertisement', $article);
    $t->assertNotContains('Filed under', $article);
    $t->assertNotContains('Related links should be omitted', $article);
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

$suite->test('forced manual publication bypasses a queued retry delay', function ($t) {
    global $db;
    $db = new FeedPublisherQueueDb;
    $db->queue[1] = array('id' => 1, 'feed_id' => 7, 'item_key' => hash('sha256', 'manual-retry'), 'source_url' => 'https://example.com/a',
        'state' => 'queued', 'available_at' => TIME_NOW + 300, 'attempts' => 1, 'claim_token' => '', 'source_published' => 0, 'discovered_at' => 1);
    $feed = array('id' => 7, 'publishing_paused' => 0, 'publish_interval_minutes' => 5, 'last_published' => TIME_NOW,
        'max_posts_per_run' => 1, 'queue_order' => 'oldest');
    $claimed = feedpublisher_queue_claim_due($feed, true);
    $t->assertSame(1, count($claimed));
    $t->assertSame('processing', $db->queue[1]['state']);
});

$suite->test('moderated entries are excluded from scheduled publication', function ($t) {
    global $db;
    $db = new FeedPublisherQueueDb;
    $db->queue[1] = array('id' => 1, 'feed_id' => 7, 'item_key' => hash('sha256', 'moderated'), 'source_url' => 'https://example.com/a',
        'state' => 'pending_approval', 'available_at' => 0, 'attempts' => 0, 'claim_token' => '', 'source_published' => 0, 'discovered_at' => 1);
    $feed = array('id' => 7, 'publishing_paused' => 0, 'publish_interval_minutes' => 5, 'last_published' => 0,
        'max_posts_per_run' => 1, 'queue_order' => 'oldest');
    $called = false;
    $result = feedpublisher_queue_dispatch($feed, function () use (&$called) { $called = true; });
    $t->assertSame(false, $called);
    $t->assertSame(0, $result['published']);
    $t->assertSame('pending_approval', $db->queue[1]['state']);
});

$suite->test('RSS, RDF, and Atom fixtures parse with format metadata', function ($t) {
    if (!extension_loaded('SimpleXML') || !extension_loaded('dom')) { $t->skip('PHP SimpleXML and DOM are not installed.'); }
    $metadata = array();
    $rss = feedpublisher_parse(file_get_contents(__DIR__ . '/fixtures/rss.xml'), array(), $metadata);
    $t->assertSame('fixture-rss-1', $rss[0]['key']);
    $t->assertSame(array('Giveaways'), $rss[0]['categories']);
    $t->assertSame(true, $rss[0]['has_media']);
    $t->assertSame('image', $rss[0]['media'][0]['kind']);
    $t->assertSame('enclosure', $rss[0]['media'][0]['source']);
    $t->assertSame('image/jpeg', $rss[0]['media'][0]['type']);
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
    $injected = feedpublisher_html_to_mycode('<p>[img]http://127.0.0.1/private.jpg[/img]</p><p><a href="https://example.com/a]bad">ok</a></p>');
    $t->assertNotContains('[img]http://127.0.0.1/private.jpg[/img]', $injected);
    $t->assertContains('&#91;img&#93;http://127.0.0.1/private.jpg&#91;/img&#93;', $injected);
    $t->assertContains('[url=https://example.com/a%5Dbad]ok[/url]', $injected);
    $nintendo = feedpublisher_html_to_mycode('<p><strong>New hero, map and more!</strong></p><p>Apart from the <a href="https://www.nintendolife.com/games/nintendo-switch-2/diablo_iv_age_of_hatred_collection">Diablo</a> news, the team shared more.</p><p>Read the <a href="https://www.nintendolife.com/news/2026/09/overwatch-announces-whats-next-as-it-reaches-the-end-of-its-first-year-long-story-arc">full article on nintendolife.com</a></p>');
    $t->assertContains('[url=https://www.nintendolife.com/games/nintendo-switch-2/diablo_iv_age_of_hatred_collection]Diablo[/url]', $nintendo);
    $t->assertContains('[url=https://www.nintendolife.com/news/2026/09/overwatch-announces-whats-next-as-it-reaches-the-end-of-its-first-year-long-story-arc]full article on nintendolife.com[/url]', $nintendo);
    $youtube = feedpublisher_html_to_mycode('<p>Watch <a href="https://youtu.be/dQw4w9WgXcQ">the trailer</a> and visit <a href="https://www.youtube.com/@NintendoAmerica">the channel</a>.</p>');
    $t->assertContains('[video=youtube]https://youtu.be/dQw4w9WgXcQ[/video]', $youtube);
    $t->assertContains('[url=https://www.youtube.com/@NintendoAmerica]the channel[/url]', $youtube);
    $t->assertContains('[b]text[/b]', $mycode);
    $t->assertContains('[url=https://example.com/good]good link[/url]', $mycode);
});

$suite->test('feed HTML resolves relative URLs, removes hidden text, and spaces body images', function ($t) {
    if (!extension_loaded('dom')) { $t->skip('PHP DOM is not installed.'); }
    $html = file_get_contents(__DIR__ . '/fixtures/relative-embedded.html');
    $cleaned = feedpublisher_cleanup_html($html, array(), 'https://example.com/news/articles/story.html');
    $mycode = feedpublisher_html_to_mycode($cleaned);
    $t->assertContains('[url=https://example.com/news/source?q=1#details]source', $mycode);
    $t->assertContains("[img]https://example.com/images/photo.jpg[/img]\n\nFollowing text", $mycode);
    $t->assertNotContains('lightboxConfig', $mycode);
    $t->assertNotContains('hidden style text', $mycode);
    $t->assertSame(array(), feedpublisher_suspicious_output_warnings($mycode));
    $warnings = feedpublisher_suspicious_output_warnings('http://thread-123.html lightbox config={x:1} <script>');
    $t->assertSame(3, count($warnings));
});

$suite->test('lifecycle and upgrade guards remain present', function ($t) {
    $source = file_get_contents(__DIR__ . '/../Upload/inc/plugins/feedpublisher.php');
    $adminSource = file_get_contents(__DIR__ . '/../Upload/inc/plugins/feedpublisher/admin.php');
    $publisherSource = file_get_contents(__DIR__ . '/../Upload/inc/plugins/feedpublisher/publisher.php');
    $queueSource = file_get_contents(__DIR__ . '/../Upload/inc/plugins/feedpublisher/queue.php');
    $t->assertContains("if (!\$db->table_exists('feedpublisher_feeds'))", $source);
    $operationsSource = file_get_contents(__DIR__ . '/../Upload/inc/plugins/feedpublisher/operations.php');
    $t->assertContains("'version' => '1.0.8'", $source);
    $t->assertContains("if (!\$db->field_exists(\$name, 'feedpublisher_feeds'))", $source);
    $t->assertContains("file='feedpublisher'", $source);
    $t->assertContains("drop_table('feedpublisher_queue')", $source);
    $t->assertContains("feedpublisher_install_fulltext_retries_table", $source);
    $t->assertContains("drop_table('feedpublisher_fulltext_retries')", $source);
    $t->assertContains("feedpublisher_install_webhook_queue_table", $source);
    $t->assertContains("drop_table('feedpublisher_webhook_queue')", $source);
    $t->assertNotContains('discord_webhook_url', implode(',', feedpublisher_portability_config_fields()));
    $webhookSource = file_get_contents(__DIR__ . '/../Upload/inc/plugins/feedpublisher/webhook.php');
    $t->assertContains("'allowed_mentions' => array('parse' => array())", $webhookSource);
    $t->assertContains("CURLOPT_PROXY => ''", $webhookSource);
    $t->assertContains('?wait=true', $webhookSource);
    $t->assertContains("'test_webhook'", $adminSource);
    $t->assertContains('Discord confirmed the test message.', $adminSource);
    $t->assertContains("feedpublisher_install_logs_table", $source);
    $t->assertContains("'disposition'", $source);
    $t->assertContains("'publication_mode'", $source);
    $t->assertContains("drop_table('feedpublisher_logs')", $source);
    $t->assertContains("delete_query('tasks'", $source);
    $t->assertContains("'enabled' => 1", $source);
    $t->assertContains("`require_entry_body` tinyint(1) NOT NULL DEFAULT 1", $source);
    $t->assertContains("'require_entry_body' => 1", $adminSource);
    $t->assertContains("`media_mode` varchar(16) NOT NULL DEFAULT 'fallback_image'", $source);
    $t->assertContains("'media_mode' => 'fallback_image'", $adminSource);
    $t->assertContains("`media_position` varchar(8) NOT NULL DEFAULT 'top'", $source);
    $t->assertContains("'media_position' => 'top'", $adminSource);
    $t->assertContains("fetch_next_run", $source);
    $t->assertContains("(int) \$existing['nextrun'] < TIME_NOW", $source);
    $t->assertContains("feedpublisher_reschedule_task", $adminSource);
    $t->assertContains("feedpublisher_admin_next_post_time", $adminSource);
    $t->assertContains("feedpublisher_admin_recent_publication_error", $adminSource);
    $t->assertContains("Automatic retry scheduled", $adminSource);
    $t->assertContains("\$values['initial_policy'] !== 'recent'", $adminSource);
    $t->assertContains("feedpublisher_prepare_publication_request_context", $publisherSource);
    $t->assertContains("!empty(\$item['source_url']) ? \$item['source_url'] : \$item['url']", $queueSource);
    $t->assertContains("\$_SERVER['REMOTE_ADDR'] = '127.0.0.1'", $publisherSource);
    $t->assertContains("'url' => 'f.url'", $adminSource);
    $t->assertContains("'forum' => 'fo.name'", $adminSource);
    $t->assertContains("feedpublisher_admin_sort_link('Name'", $adminSource);
    $t->assertContains("name=\"confirm_really\"", $adminSource);
    $t->assertContains("name=\"confirm_last_chance\"", $adminSource);
    $t->assertContains("'publishing_paused' => 1", $adminSource);
    $t->assertContains("'ai_api_key', 'discord_webhook_url'", $adminSource);
    $t->assertNotContains('ai_api_key', implode(',', feedpublisher_portability_config_fields()));
    $t->assertNotContains('ai_endpoint', implode(',', feedpublisher_portability_config_fields()));
    $t->assertContains('function feedpublisher_delete_feed_threads', $operationsSource);
    $t->assertContains('delete_thread($tid)', $operationsSource);
    $t->assertContains("array('tid' => 0, 'pid' => 0)", $operationsSource);
});

$suite->test('AI cleanup is optional, bounded, strictly validated, and falls back', function ($t) {
    $feed=array('id'=>901,'ai_enabled'=>1,'ai_endpoint'=>'http://192.168.1.2:8080/v1/chat/completions','ai_api_key'=>'secret','ai_model'=>'local-model','ai_prompt'=>'Tighten wording.','ai_timeout_seconds'=>10,'ai_max_tokens'=>512,'ai_max_per_run'=>2,'ai_json_mode'=>1,'ai_no_think'=>1);
    $item=array('title'=>'Original','content'=>'Original body');
    $sender=function($f,$payload) use($t){$t->assertSame(0,$payload['temperature']);return array('choices'=>array(array('message'=>array('content'=>'{"title":"Clean title","body":"Clean body"}'))));};
    $result=feedpublisher_ai_apply($feed,$item,$sender);$t->assertTrue($result['applied']);$t->assertSame('Clean title',$result['item']['title']);
    $bad=feedpublisher_ai_apply($feed,$item,function(){return array('choices'=>array(array('message'=>array('content'=>'{"title":"Bad","body":"[script]x[/script]"}'))));});
    $t->assertTrue(!$bad['applied']);$t->assertSame($item,$bad['item']);
    $limited=feedpublisher_ai_apply($feed,$item,$sender);$t->assertTrue(!$limited['applied']);$t->assertContains('limit reached',$limited['message']);
    $disabled=feedpublisher_ai_apply(array('ai_enabled'=>0),$item,function(){throw new RuntimeException('must not run');});$t->assertSame($item,$disabled['item']);
    $t->assertTrue(feedpublisher_ai_endpoint_valid('http://127.0.0.1:8080/v1/chat/completions'));
    $t->assertTrue(!feedpublisher_ai_endpoint_valid('http://user:pass@host/v1/chat/completions'));
    $t->assertSame(array('title'=>'Fenced','body'=>'Body'), feedpublisher_ai_validate_result(feedpublisher_ai_json_content("```json\n{\"title\":\"Fenced\",\"body\":\"Body\"}\n```")));
    $t->expectException('RuntimeException', function(){feedpublisher_ai_validate_result(array('title'=>'Injected','body'=>'[url=https://evil.example]bad[/url]'),'Original');});
    $t->assertContains("'test_ai'", file_get_contents(__DIR__ . '/../Upload/inc/plugins/feedpublisher/admin.php'));
});

$suite->test('documentation covers deployment and known safety limits', function ($t) {
    $readme = file_get_contents(__DIR__ . '/../README.md');
    foreach (array('## Requirements', '## Install', 'scheduled task', 'Admin CP', '## Security model', '2 MiB') as $text) {
        $t->assertContains($text, $readme);
    }
});

exit($suite->finish());
