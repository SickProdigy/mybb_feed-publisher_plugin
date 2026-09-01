<?php
/**
 * Shared discovery and ACP operational helpers.
 * Copyright (c) 2026 SickProdigy
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!defined('IN_MYBB')) {
    die('Direct access is not allowed.');
}

function feedpublisher_discover_feed($feed)
{
    global $db;

    $totals = array('staged' => 0, 'skipped' => 0, 'rejected' => 0, 'existing' => 0, 'full' => 0);
    try {
        $fetchMetadata = array();
        $xml = feedpublisher_fetch($feed['url'], 2097152, $fetchMetadata);
        $items = feedpublisher_parse($xml, $fetchMetadata);
        $initializing = empty($feed['initialized_at']);
        $plan = $initializing
            ? feedpublisher_initial_stage_plan($feed, $items)
            : array_map(function ($item) {
                return array('item' => $item, 'state' => 'queued');
            }, $items);
        $initialComplete = true;
        foreach ($plan as $entry) {
            $result = feedpublisher_queue_stage($feed, $entry['item'], $entry['state']);
            if ($result === 'queued') {
                ++$totals['staged'];
            } elseif (isset($totals[$result])) {
                ++$totals[$result];
            }
            if ($result === 'full') {
                $initialComplete = false;
            }
        }
        $reconciled = feedpublisher_reconcile_missing_queued($feed, $items);
        $totals['reconciled'] = $reconciled;
        $update = array(
            'last_checked' => TIME_NOW,
            'fetch_failures' => 0,
            'next_fetch_at' => 0,
            'last_error' => '',
            'last_feed_item_count' => count($items),
        );
        if ($initializing && $initialComplete) {
            $update['initialized_at'] = TIME_NOW;
        }
        $db->update_query('feedpublisher_feeds', $update, 'id=' . (int) $feed['id']);
        return $totals;
    } catch (Throwable $exception) {
        $failures = min(10, (int) $feed['fetch_failures'] + 1);
        $retryDelay = min(21600, 300 * (2 ** min(6, $failures - 1)));
        $message = feedpublisher_safe_log_text($exception->getMessage());
        $db->update_query('feedpublisher_feeds', array(
            'last_checked' => TIME_NOW,
            'fetch_failures' => $failures,
            'next_fetch_at' => TIME_NOW + $retryDelay,
            'last_error' => $db->escape_string($message),
        ), 'id=' . (int) $feed['id']);
        throw $exception;
    }
}

function feedpublisher_queue_retry_failed($feedId, $limit = 100)
{
    global $db;

    $ids = array();
    $query = $db->simple_select('feedpublisher_queue', 'id', 'feed_id=' . (int) $feedId . " AND state='failed'", array(
        'order_by' => 'last_attempt',
        'order_dir' => 'ASC',
        'limit' => max(1, min(100, (int) $limit)),
    ));
    while ($row = $db->fetch_array($query)) {
        $ids[] = (int) $row['id'];
    }
    if (!$ids) {
        return 0;
    }
    $db->update_query('feedpublisher_queue', array(
        'state' => 'queued',
        'attempts' => 0,
        'available_at' => TIME_NOW,
        'last_error' => '',
    ), 'id IN (' . implode(',', $ids) . ") AND state='failed'");
    return $db->affected_rows();
}

function feedpublisher_queue_resolve_uncertain($feed, $queueId, $resolution, $tid = 0, $pid = 0)
{
    global $db;

    $item = $db->fetch_array($db->simple_select(
        'feedpublisher_queue',
        '*',
        'id=' . (int) $queueId . ' AND feed_id=' . (int) $feed['id'] . " AND state='uncertain'",
        array('limit' => 1)
    ));
    if (!$item) {
        throw new RuntimeException('The uncertain queue entry no longer exists.');
    }
    $condition = 'feed_id=' . (int) $feed['id'] . " AND item_key='" . $db->escape_string($item['item_key']) . "'";
    $reservation = $db->fetch_array($db->simple_select(
        'feedpublisher_items',
        'id,tid,pid,imported_at',
        $condition,
        array('limit' => 1)
    ));
    if (!$reservation) {
        throw new RuntimeException('The publication reservation no longer exists.');
    }

    if ($resolution === 'link') {
        $post = $db->fetch_array($db->simple_select('posts', 'pid,tid,fid', 'pid=' . (int) $pid . ' AND tid=' . (int) $tid, array('limit' => 1)));
        if (!$post || (int) $post['fid'] !== (int) $feed['fid']) {
            throw new RuntimeException('The supplied thread and post do not belong to the destination forum.');
        }
        $db->update_query('feedpublisher_items', array(
            'source_url' => $db->escape_string($item['source_url']),
            'tid' => (int) $tid,
            'pid' => (int) $pid,
            'imported_at' => TIME_NOW,
        ), $condition);
        $db->update_query('feedpublisher_queue', array(
            'state' => 'published',
            'tid' => (int) $tid,
            'pid' => (int) $pid,
            'published_at' => TIME_NOW,
            'last_error' => '',
        ), 'id=' . (int) $item['id'] . " AND state='uncertain'");
        return 'linked';
    }
    if ($resolution === 'retry') {
        if (!empty($reservation['tid']) || !empty($reservation['pid']) || !empty($reservation['imported_at'])) {
            throw new RuntimeException('This reservation already contains publication IDs and cannot be retried; link the existing post instead.');
        }
        $db->delete_query('feedpublisher_items', $condition . ' AND tid=0 AND pid=0 AND imported_at=0');
        if ($db->affected_rows() !== 1) {
            throw new RuntimeException('The pending publication reservation could not be released.');
        }
        $db->update_query('feedpublisher_queue', array(
            'state' => 'queued',
            'attempts' => 0,
            'available_at' => TIME_NOW,
            'last_error' => '',
        ), 'id=' . (int) $item['id'] . " AND state='uncertain'");
        return 'queued';
    }
    if ($resolution === 'reject') {
        if (!empty($reservation['tid']) || !empty($reservation['pid']) || !empty($reservation['imported_at'])) {
            throw new RuntimeException('This reservation already contains publication IDs and cannot be rejected; link the existing post instead.');
        }
        $db->update_query('feedpublisher_queue', array(
            'state' => 'rejected',
            'published_at' => TIME_NOW,
            'last_error' => '',
        ), 'id=' . (int) $item['id'] . " AND state='uncertain'");
        return 'rejected';
    }
    throw new RuntimeException('Select a valid uncertain-item resolution.');
}
