<?php
/**
 * Persistent discovery queue and paced dispatch helpers.
 * Copyright (c) 2026 SickProdigy
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!defined('IN_MYBB')) {
    die('Direct access is not allowed.');
}

function feedpublisher_queue_stage($feed, $item, $state = 'queued')
{
    global $db;
    $state = $state === 'skipped' ? 'skipped' : 'queued';

    $feedId = (int) $feed['id'];
    $itemKey = feedpublisher_item_key($item['key']);
    $condition = "feed_id={$feedId} AND item_key='" . $db->escape_string($itemKey) . "'";
    $existing = $db->fetch_field($db->simple_select(
        'feedpublisher_queue',
        'id',
        $condition,
        array('limit' => 1)
    ), 'id');
    if ($existing) {
        return 'existing';
    }

    $imported = $db->fetch_field($db->simple_select(
        'feedpublisher_items',
        'id',
        $condition,
        array('limit' => 1)
    ), 'id');
    if ($imported) {
        return 'existing';
    }

    $queued = (int) $db->fetch_field($db->simple_select(
        'feedpublisher_queue',
        'COUNT(id) AS total',
        "feed_id={$feedId} AND state IN ('queued','processing','failed')"
    ), 'total');
    if ($state === 'queued' && $queued >= 1000) {
        return 'full';
    }

    $record = array(
        'feed_id' => $feedId,
        'item_key' => $db->escape_string($itemKey),
        'title' => $db->escape_string(my_substr($item['title'], 0, 255)),
        'source_url' => $db->escape_string(substr($item['url'], 0, 2048)),
        'raw_content' => $state === 'skipped' ? '' : $db->escape_string($item['content']),
        'content' => $state === 'skipped' ? '' : $db->escape_string(feedpublisher_html_to_mycode($item['content'])),
        'source_published' => max(0, (int) $item['published']),
        'discovered_at' => TIME_NOW,
        'available_at' => TIME_NOW,
        'state' => $state,
        'published_at' => $state === 'skipped' ? TIME_NOW : 0,
    );
    $db->insert_query('feedpublisher_queue', $record);

    return $state;
}

function feedpublisher_queue_counts($feedId)
{
    global $db;

    $counts = array('queued' => 0, 'processing' => 0, 'published' => 0, 'failed' => 0, 'skipped' => 0, 'uncertain' => 0);
    $query = $db->simple_select(
        'feedpublisher_queue',
        'state, COUNT(id) AS total',
        'feed_id=' . (int) $feedId,
        array('group_by' => 'state')
    );
    while ($row = $db->fetch_array($query)) {
        if (isset($counts[$row['state']])) {
            $counts[$row['state']] = (int) $row['total'];
        }
    }
    return $counts;
}

function feedpublisher_queue_release_stale_claims($feedId, $timeout = 900)
{
    global $db;

    $cutoff = TIME_NOW - max(300, (int) $timeout);
    $db->update_query('feedpublisher_queue', array(
        'state' => 'queued',
        'claim_token' => '',
        'claimed_at' => 0,
    ), "feed_id=" . (int) $feedId . " AND state='processing' AND claimed_at<{$cutoff}");
}

function feedpublisher_queue_claim_due($feed)
{
    global $db;

    if (!empty($feed['publishing_paused'])) {
        return array();
    }

    $publishInterval = max(5, (int) $feed['publish_interval_minutes']) * 60;
    if ((int) $feed['last_published'] > TIME_NOW - $publishInterval) {
        return array();
    }

    $feedId = (int) $feed['id'];
    feedpublisher_queue_release_stale_claims($feedId);
    $limit = max(1, min(25, (int) $feed['max_posts_per_run']));
    $direction = $feed['queue_order'] === 'newest' ? 'DESC' : 'ASC';
    $sortTime = 'CASE WHEN source_published=0 THEN discovered_at ELSE source_published END';
    $query = $db->simple_select(
        'feedpublisher_queue',
        '*',
        "feed_id={$feedId} AND state='queued' AND available_at<=" . TIME_NOW,
        array('order_by' => $sortTime, 'order_dir' => $direction, 'limit' => $limit)
    );

    $claimed = array();
    while ($item = $db->fetch_array($query)) {
        $token = hash('sha256', random_bytes(32));
        $db->update_query('feedpublisher_queue', array(
            'state' => 'processing',
            'claim_token' => $db->escape_string($token),
            'claimed_at' => TIME_NOW,
            'last_attempt' => TIME_NOW,
            'attempts' => (int) $item['attempts'] + 1,
        ), "id=" . (int) $item['id'] . " AND state='queued'");
        if ($db->affected_rows() === 1) {
            $item['claim_token'] = $token;
            $item['attempts'] = (int) $item['attempts'] + 1;
            $claimed[] = $item;
        }
    }

    return $claimed;
}

function feedpublisher_queue_complete($feed, $item, $tid, $pid)
{
    global $db;

    $id = (int) $item['id'];
    $token = $db->escape_string($item['claim_token']);
    $db->update_query('feedpublisher_items', array(
        'source_url' => $db->escape_string($item['source_url']),
        'tid' => (int) $tid,
        'pid' => (int) $pid,
        'imported_at' => TIME_NOW,
    ), 'feed_id=' . (int) $feed['id'] . " AND item_key='" . $db->escape_string($item['item_key']) . "'");
    if ($db->affected_rows() !== 1) {
        throw new RuntimeException('The publication reservation could not be finalized.');
    }

    $db->update_query('feedpublisher_queue', array(
        'state' => 'published',
        'claim_token' => '',
        'claimed_at' => 0,
        'last_error' => '',
        'tid' => (int) $tid,
        'pid' => (int) $pid,
        'published_at' => TIME_NOW,
    ), "id={$id} AND state='processing' AND claim_token='{$token}'");
    if ($db->affected_rows() !== 1) {
        throw new RuntimeException('The queue claim was lost after publication; the imported-item record was preserved.');
    }
    $db->update_query('feedpublisher_feeds', array('last_published' => TIME_NOW), 'id=' . (int) $feed['id']);
}

function feedpublisher_queue_reserve($feed, $item)
{
    global $db;

    $feedId = (int) $feed['id'];
    $itemKey = $db->escape_string($item['item_key']);
    $db->write_query(
        'INSERT IGNORE INTO ' . TABLE_PREFIX . 'feedpublisher_items'
        . ' (feed_id, item_key, source_url, tid, pid, imported_at) VALUES ('
        . $feedId . ", '" . $itemKey . "', '" . $db->escape_string($item['source_url']) . "', 0, 0, 0)"
    );
    return $db->affected_rows() === 1;
}

function feedpublisher_queue_release_reservation($feed, $item)
{
    global $db;

    $db->delete_query(
        'feedpublisher_items',
        'feed_id=' . (int) $feed['id'] . " AND item_key='" . $db->escape_string($item['item_key'])
        . "' AND tid=0 AND pid=0 AND imported_at=0"
    );
}

function feedpublisher_queue_mark_uncertain($item)
{
    global $db;

    $db->update_query('feedpublisher_queue', array(
        'state' => 'uncertain',
        'claim_token' => '',
        'claimed_at' => 0,
        'last_error' => 'A previous publication was interrupted after reservation; review MyBB before retrying.',
    ), 'id=' . (int) $item['id'] . " AND state='processing'");
}

function feedpublisher_queue_fail($item, $message, $retryDelay = 300)
{
    global $db;

    $attempts = (int) $item['attempts'];
    $state = $attempts >= 5 ? 'failed' : 'queued';
    $db->update_query('feedpublisher_queue', array(
        'state' => $state,
        'available_at' => TIME_NOW + max(60, (int) $retryDelay),
        'claim_token' => '',
        'claimed_at' => 0,
        'last_error' => $db->escape_string(substr($message, 0, 1000)),
    ), "id=" . (int) $item['id'] . " AND claim_token='" . $db->escape_string($item['claim_token']) . "'");
}

function feedpublisher_queue_dispatch($feed, $publisher)
{
    $result = array('published' => 0, 'failed' => 0);
    foreach (feedpublisher_queue_claim_due($feed) as $item) {
        if (!feedpublisher_queue_reserve($feed, $item)) {
            feedpublisher_queue_mark_uncertain($item);
            ++$result['failed'];
            continue;
        }
        $publication = null;
        try {
            $publication = call_user_func($publisher, $feed, $item);
            if (!is_array($publication) || empty($publication['tid']) || empty($publication['pid'])) {
                throw new RuntimeException('The publisher did not return valid thread and post IDs.');
            }
            feedpublisher_queue_complete($feed, $item, $publication['tid'], $publication['pid']);
            ++$result['published'];
        } catch (Throwable $exception) {
            if (is_array($publication) && !empty($publication['tid']) && !empty($publication['pid'])) {
                feedpublisher_queue_mark_uncertain($item);
            } else {
                feedpublisher_queue_release_reservation($feed, $item);
                feedpublisher_queue_fail($item, $exception->getMessage());
            }
            ++$result['failed'];
        }
    }
    return $result;
}

function feedpublisher_queue_prune($feedId, $retentionDays = 90)
{
    global $db;

    $cutoff = TIME_NOW - max(1, (int) $retentionDays) * 86400;
    $db->delete_query(
        'feedpublisher_queue',
        'feed_id=' . (int) $feedId . " AND state='published' AND published_at>0 AND published_at<{$cutoff}"
    );
}

function feedpublisher_initial_stage_plan($feed, $items)
{
    $policy = isset($feed['initial_policy']) ? $feed['initial_policy'] : 'latest';
    if (!in_array($policy, array('all', 'latest', 'recent', 'start_now'), true)) {
        $policy = 'latest';
    }

    if ($policy === 'all') {
        return array_map(function ($item) {
            return array('item' => $item, 'state' => 'queued');
        }, $items);
    }
    if ($policy === 'start_now') {
        return array_map(function ($item) {
            return array('item' => $item, 'state' => 'skipped');
        }, $items);
    }

    $newest = array_values($items);
    usort($newest, function ($left, $right) {
        $leftTime = (int) $left['published'];
        $rightTime = (int) $right['published'];
        if ($leftTime === $rightTime) {
            return strcmp($right['key'], $left['key']);
        }
        return $rightTime <=> $leftTime;
    });
    $limit = $policy === 'latest' ? 1 : max(1, min(100, (int) $feed['initial_limit']));
    $selected = array();
    foreach (array_slice($newest, 0, $limit) as $item) {
        $selected[$item['key']] = true;
    }

    $plan = array();
    foreach ($items as $item) {
        $plan[] = array(
            'item' => $item,
            'state' => isset($selected[$item['key']]) ? 'queued' : 'skipped',
        );
    }
    return $plan;
}
