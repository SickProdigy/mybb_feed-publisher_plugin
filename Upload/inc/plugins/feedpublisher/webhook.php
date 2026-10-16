<?php
/**
 * Optional, failure-isolated Discord webhook delivery.
 * Copyright (c) 2026 SickProdigy
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!defined('IN_MYBB')) {
    die('Direct access is not allowed.');
}

function feedpublisher_discord_webhook_url_valid($url)
{
    $parts = parse_url(trim((string) $url));
    if (!$parts || strtolower(isset($parts['scheme']) ? $parts['scheme'] : '') !== 'https'
        || strtolower(isset($parts['host']) ? $parts['host'] : '') !== 'discord.com'
        || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
        || !empty($parts['query']) || !empty($parts['fragment'])) return false;
    return preg_match('~^/api(?:/v[0-9]+)?/webhooks/[0-9]+/[A-Za-z0-9._-]{20,}$~', isset($parts['path']) ? $parts['path'] : '') === 1;
}

function feedpublisher_discord_template_errors($template)
{
    $template = (string) $template;
    $errors = array();
    if (trim($template) === '') $errors[] = 'Discord message template cannot be empty.';
    if (strlen($template) > 2000) $errors[] = 'Discord message template must not exceed 2,000 bytes before placeholders are expanded.';
    preg_match_all('/\{([^{}]+)\}/', $template, $matches);
    foreach (array_unique($matches[1]) as $placeholder) {
        if (!in_array($placeholder, array('title', 'thread_url', 'source_url', 'feed_name'), true)) {
            $errors[] = 'Discord message template contains unsupported placeholder {' . $placeholder . '}.';
        }
    }
    return $errors;
}

function feedpublisher_discord_thread_url($tid)
{
    global $mybb;
    $base = isset($mybb->settings['bburl']) ? rtrim($mybb->settings['bburl'], '/') : '';
    $link = function_exists('get_thread_link') ? get_thread_link((int) $tid) : 'showthread.php?tid=' . (int) $tid;
    return $base . '/' . ltrim($link, '/');
}

function feedpublisher_discord_render($feed, $delivery)
{
    $template = isset($feed['discord_message_template']) ? $feed['discord_message_template'] : "{title}\n{thread_url}";
    $content = strtr($template, array(
        '{title}' => isset($delivery['title']) ? $delivery['title'] : '',
        '{thread_url}' => feedpublisher_discord_thread_url(isset($delivery['tid']) ? $delivery['tid'] : 0),
        '{source_url}' => isset($delivery['source_url']) ? $delivery['source_url'] : '',
        '{feed_name}' => isset($feed['name']) ? $feed['name'] : '',
    ));
    $content = trim(preg_replace('/\r\n?|\x00/', "\n", $content));
    if (my_strlen($content) > 2000) $content = rtrim(my_substr($content, 0, 1997)) . '...';
    return $content;
}

function feedpublisher_webhook_enqueue($feed, $item, $tid)
{
    global $db;
    if (empty($feed['discord_enabled']) || !feedpublisher_discord_webhook_url_valid(isset($feed['discord_webhook_url']) ? $feed['discord_webhook_url'] : '')) return false;
    $record = array(
        'feed_id' => (int) $feed['id'],
        'item_key' => $db->escape_string($item['item_key']),
        'tid' => (int) $tid,
        'title' => $db->escape_string(my_substr(isset($item['title']) ? $item['title'] : '', 0, 255)),
        'source_url' => $db->escape_string(substr(isset($item['source_url']) ? $item['source_url'] : '', 0, 2048)),
        'state' => 'queued',
        'attempts' => 0,
        'next_attempt_at' => TIME_NOW,
    );
    $db->write_query('INSERT IGNORE INTO ' . TABLE_PREFIX . 'feedpublisher_webhook_queue'
        . ' (feed_id,item_key,tid,title,source_url,state,attempts,next_attempt_at) VALUES ('
        . $record['feed_id'] . ",'" . $record['item_key'] . "'," . $record['tid'] . ",'" . $record['title'] . "','"
        . $record['source_url'] . "','queued',0," . TIME_NOW . ')');
    return $db->affected_rows() === 1;
}

function feedpublisher_discord_execute($url, $content)
{
    if (!feedpublisher_discord_webhook_url_valid($url)) throw new RuntimeException('The saved Discord webhook URL is invalid.');
    if (!function_exists('curl_init')) throw new RuntimeException('The PHP cURL extension is required for Discord delivery.');
    if ($content === '') throw new RuntimeException('The rendered Discord message is empty.');
    $payload = json_encode(array('content' => $content, 'allowed_mentions' => array('parse' => array())),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) throw new RuntimeException('The Discord message could not be encoded.');

    $body = '';
    $curl = curl_init($url . '?wait=true');
    curl_setopt_array($curl, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Accept: application/json'),
        CURLOPT_USERAGENT => 'MyBB Feed Publisher/1.0',
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
        CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body) {
            if (strlen($body) < 262144) $body .= substr($chunk, 0, 262144 - strlen($body));
            return strlen($chunk);
        },
    ));
    $ok = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $retryAfter = 0;
    if ($status === 429) {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['retry_after'])) $retryAfter = max(1, min(3600, (int) ceil((float) $decoded['retry_after'])));
    }
    return array('ok' => $ok !== false && $status >= 200 && $status < 300, 'status' => $status, 'retry_after' => $retryAfter);
}

function feedpublisher_webhook_dispatch($feed, $limit = 5, $sender = null)
{
    global $db;
    $result = array('delivered' => 0, 'failed' => 0);
    if (empty($feed['discord_enabled']) || !feedpublisher_discord_webhook_url_valid(isset($feed['discord_webhook_url']) ? $feed['discord_webhook_url'] : '')) return $result;
    $sender = $sender ?: 'feedpublisher_discord_execute';
    $db->update_query('feedpublisher_webhook_queue', array('state' => 'queued', 'claimed_at' => 0),
        'feed_id=' . (int) $feed['id'] . " AND state='processing' AND claimed_at<" . (TIME_NOW - 900));
    $query = $db->simple_select('feedpublisher_webhook_queue', '*',
        'feed_id=' . (int) $feed['id'] . " AND state='queued' AND next_attempt_at<=" . TIME_NOW,
        array('order_by' => 'id', 'order_dir' => 'ASC', 'limit' => max(1, min(25, (int) $limit))));
    while ($delivery = $db->fetch_array($query)) {
        $db->update_query('feedpublisher_webhook_queue', array('state' => 'processing', 'claimed_at' => TIME_NOW),
            'id=' . (int) $delivery['id'] . " AND state='queued'");
        if ($db->affected_rows() !== 1) continue;
        try {
            $response = call_user_func($sender, $feed['discord_webhook_url'], feedpublisher_discord_render($feed, $delivery));
            if (empty($response['ok'])) {
                $status = isset($response['status']) ? (int) $response['status'] : 0;
                throw new RuntimeException($status ? 'Discord returned HTTP ' . $status . '.' : 'Discord did not confirm delivery.');
            }
            $db->update_query('feedpublisher_webhook_queue', array(
                'state' => 'delivered', 'attempts' => (int) $delivery['attempts'] + 1,
                'last_attempt_at' => TIME_NOW, 'claimed_at' => 0, 'last_error' => '', 'delivered_at' => TIME_NOW,
            ), 'id=' . (int) $delivery['id'] . " AND state='processing'");
            ++$result['delivered'];
        } catch (Throwable $exception) {
            $attempts = (int) $delivery['attempts'] + 1;
            $retryAfter = isset($response['retry_after']) ? (int) $response['retry_after'] : 0;
            $delay = $retryAfter ?: min(21600, 300 * (2 ** min(6, $attempts - 1)));
            $state = $attempts >= 5 ? 'failed' : 'queued';
            $message = feedpublisher_safe_log_text($exception->getMessage());
            $db->update_query('feedpublisher_webhook_queue', array(
                'state' => $state, 'attempts' => $attempts, 'next_attempt_at' => TIME_NOW + $delay,
                'last_attempt_at' => TIME_NOW, 'claimed_at' => 0, 'last_error' => $db->escape_string($message),
            ), 'id=' . (int) $delivery['id'] . " AND state='processing'");
            feedpublisher_log_event((int) $feed['id'], 'webhook', $state === 'failed' ? 'error' : 'warning', $message);
            ++$result['failed'];
        }
    }
    $db->delete_query('feedpublisher_webhook_queue',
        'feed_id=' . (int) $feed['id'] . " AND state='delivered' AND delivered_at<" . (TIME_NOW - 90 * 86400));
    return $result;
}
