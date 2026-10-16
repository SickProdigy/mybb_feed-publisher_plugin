<?php
/** Opt-in live AI compatibility check; never runs in the normal test suite. */
define('IN_MYBB', 1);
if (!function_exists('my_strlen')) {
    function my_strlen($v) { return function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v); }
}
require_once __DIR__ . '/../Upload/inc/plugins/feedpublisher/core.php';
require_once __DIR__ . '/../Upload/inc/plugins/feedpublisher/ai.php';

$options = getopt('', array('endpoint:', 'model:', 'timeout::', 'max-tokens::', 'no-think', 'no-json-mode'));
if (empty($options['endpoint']) || empty($options['model'])) {
    fwrite(STDERR, "Usage: php tests/test_ai.php --endpoint=http://host:port/v1/chat/completions --model=alias [--no-think] [--no-json-mode]\nAPI key: FEEDPUBLISHER_AI_API_KEY environment variable.\n");
    exit(2);
}
$feed = array('ai_enabled'=>1, 'ai_endpoint'=>$options['endpoint'], 'ai_api_key'=>getenv('FEEDPUBLISHER_AI_API_KEY') ?: '',
    'ai_model'=>$options['model'], 'ai_prompt'=>'Preserve meaning and punctuation.', 'ai_timeout_seconds'=>isset($options['timeout'])?(int)$options['timeout']:60,
    'ai_max_tokens'=>isset($options['max-tokens'])?(int)$options['max-tokens']:512, 'ai_max_per_run'=>1,
    'ai_json_mode'=>!isset($options['no-json-mode']), 'ai_no_think'=>isset($options['no-think']));
$started = microtime(true);
try {
    $result = feedpublisher_ai_request($feed, array('title'=>'Feed Publisher live test', 'content'=>'A harmless synthetic body with [url=https://example.com/story]one existing link[/url].'));
    echo 'PASS model=' . $feed['ai_model'] . ' elapsed_ms=' . (int)((microtime(true)-$started)*1000) . "\n";
    echo 'title=' . $result['title'] . "\nbody=" . $result['body'] . "\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL elapsed_ms=' . (int)((microtime(true)-$started)*1000) . ' error=' . feedpublisher_safe_log_text($exception->getMessage()) . "\n");
    exit(1);
}
