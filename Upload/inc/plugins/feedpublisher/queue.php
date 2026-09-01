<?php
/**
 * Persistent discovery queue and paced dispatch helpers.
 * Copyright (c) 2026 SickProdigy
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!defined('IN_MYBB')) {
    die('Direct access is not allowed.');
}

function feedpublisher_source_date_plan($feed, $item, $now = null)
{
    $now = $now === null ? TIME_NOW : max(0, (int) $now);
    $source = max(0, (int) (isset($item['published']) ? $item['published'] : 0));
    $valid = $source >= 315532800 && $source <= $now + 31536000;
    if (!$valid) {
        $source = 0;
    }
    $policy = isset($feed['future_date_policy']) ? $feed['future_date_policy'] : 'hold';
    if (!in_array($policy, array('hold', 'clamp', 'skip', 'reject'), true)) {
        $policy = 'hold';
    }
    $future = $source > $now;
    $state = 'queued';
    $availableAt = $future && $policy === 'hold' ? $source : $now;
    if ($future && $policy === 'skip') {
        $state = 'skipped';
    } elseif ($future && $policy === 'reject') {
        $state = 'rejected';
    }
    $jitterMinutes = max(0, min(60, (int) (isset($feed['schedule_jitter_minutes']) ? $feed['schedule_jitter_minutes'] : 0)));
    $jitter = 0;
    if ($state === 'queued' && $jitterMinutes > 0) {
        $identity = isset($item['key']) ? (string) $item['key'] : '';
        $jitter = hexdec(substr(hash('sha256', $identity), 0, 8)) % ($jitterMinutes * 60 + 1);
        $availableAt += $jitter;
    }
    $dateMode = isset($feed['thread_date_mode']) ? $feed['thread_date_mode'] : 'publish';
    $threadTime = $dateMode === 'source' && $source > 0 && (!$future || $policy === 'hold') ? $source : $now;
    return array('state' => $state, 'source_time' => $source, 'source_valid' => $valid, 'future' => $future,
        'available_at' => $availableAt, 'thread_time' => $threadTime, 'jitter_seconds' => $jitter);
}

function feedpublisher_thread_dateline($feed, $item, $now = null)
{
    $now = $now === null ? TIME_NOW : max(0, (int) $now);
    if (!isset($feed['thread_date_mode']) || $feed['thread_date_mode'] !== 'source') {
        return $now;
    }
    $source = max(0, (int) (isset($item['source_published']) ? $item['source_published'] : 0));
    return $source >= 315532800 && $source <= $now ? $source : $now;
}

function feedpublisher_queue_stage($feed, $item, $state = 'queued')
{
    global $db;
    $state = in_array($state, array('skipped', 'rejected'), true) ? $state : 'queued';
    $datePlan = feedpublisher_source_date_plan($feed, $item);
    if ($state === 'queued') {
        $state = $datePlan['state'];
    }

    $feedId = (int) $feed['id'];
    $identity = feedpublisher_derive_item_identity($feed, $item);
    if ($identity['key'] === '') {
        return 'rejected';
    }
    $itemKey = $identity['key'];
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
    $prepared = $state === 'queued' ? feedpublisher_prepare_item($feed, $item) : null;

    $record = array(
        'feed_id' => $feedId,
        'item_key' => $db->escape_string($itemKey),
        'title' => $db->escape_string(my_substr($item['title'], 0, 255)),
        'source_url' => $db->escape_string(substr($item['url'], 0, 2048)),
        'author' => $db->escape_string(my_substr(isset($item['author']) ? $item['author'] : '', 0, 255)),
        'raw_content' => $state === 'queued' ? $db->escape_string($item['content']) : '',
        'content' => $state === 'queued' ? $db->escape_string($prepared['content']) : '',
        'source_published' => $datePlan['source_time'],
        'discovered_at' => TIME_NOW,
        'available_at' => $state === 'queued' ? $datePlan['available_at'] : TIME_NOW,
        'state' => $state,
        'published_at' => $state === 'queued' ? 0 : TIME_NOW,
    );
    $db->insert_query('feedpublisher_queue', $record);
    if ($state !== 'queued') {
        $disposition = isset($item['_disposition']) && in_array($item['_disposition'], array('filtered', 'skipped', 'rejected'), true)
            ? $item['_disposition'] : $state;
        $db->write_query('INSERT IGNORE INTO ' . TABLE_PREFIX . 'feedpublisher_items'
            . ' (feed_id, item_key, source_url, disposition, tid, pid, imported_at) VALUES ('
            . $feedId . ", '" . $db->escape_string($itemKey) . "', '" . $db->escape_string(substr($item['url'], 0, 2048))
            . "', '" . $db->escape_string($disposition) . "', 0, 0, " . TIME_NOW . ')');
    }

    return $state;
}

function feedpublisher_queue_counts($feedId)
{
    global $db;

    $counts = array('queued' => 0, 'processing' => 0, 'published' => 0, 'failed' => 0, 'skipped' => 0, 'uncertain' => 0, 'rejected' => 0);
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

function feedpublisher_queue_claim_due($feed, $force = false)
{
    global $db;

    if (!empty($feed['publishing_paused'])) {
        return array();
    }

    $publishInterval = max(5, (int) $feed['publish_interval_minutes']) * 60;
    if (!$force && (int) $feed['last_published'] > TIME_NOW - $publishInterval) {
        return array();
    }

    $feedId = (int) $feed['id'];
    feedpublisher_queue_release_stale_claims($feedId);
    $limit = max(1, min(25, (int) $feed['max_posts_per_run']));
    $direction = $feed['queue_order'] === 'newest' ? 'DESC' : 'ASC';
    $sortTime = 'CASE WHEN source_published=0 THEN discovered_at ELSE source_published END ' . $direction . ', id ' . $direction;
    $query = $db->simple_select(
        'feedpublisher_queue',
        '*',
        "feed_id={$feedId} AND state='queued' AND available_at<=" . TIME_NOW,
        array('order_by' => $sortTime, 'limit' => $limit)
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
        'disposition' => 'published',
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
        . ' (feed_id, item_key, source_url, disposition, tid, pid, imported_at) VALUES ('
        . $feedId . ", '" . $itemKey . "', '" . $db->escape_string($item['source_url']) . "', 'reserved', 0, 0, 0)"
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
    feedpublisher_log_event((int) $item['feed_id'], 'publication', $state === 'failed' ? 'error' : 'warning', $message);
}

function feedpublisher_queue_dispatch($feed, $publisher, $force = false)
{
    $result = array('published' => 0, 'failed' => 0);
    foreach (feedpublisher_queue_claim_due($feed, $force) as $item) {
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

function feedpublisher_retention_candidate_ids($feed, $limit = 100)
{
    global $db;
    $limit = max(1, min(100, (int) $limit));
    $feedId = (int) $feed['id'];
    $days = max(1, min(3650, (int) $feed['terminal_retention_days']));
    $keep = max(100, min(100000, (int) $feed['terminal_retention_count']));
    $cutoff = TIME_NOW - $days * 86400;
    $ids = array();
    foreach (array('published', 'skipped', 'rejected', 'failed') as $state) {
        if (count($ids) >= $limit) { break; }
        $timeField = $state === 'failed' ? 'last_attempt' : 'published_at';
        $query = $db->simple_select('feedpublisher_queue', 'id', "feed_id={$feedId} AND state='{$state}' AND {$timeField}>0 AND {$timeField}<{$cutoff}", array(
            'order_by' => $timeField, 'order_dir' => 'ASC', 'limit' => $limit - count($ids),
        ));
        while ($row = $db->fetch_array($query)) { $ids[(int) $row['id']] = (int) $row['id']; }
        if (count($ids) >= $limit) { break; }
        $total = (int) $db->fetch_field($db->simple_select('feedpublisher_queue', 'COUNT(id) AS total', "feed_id={$feedId} AND state='{$state}'"), 'total');
        $overflow = max(0, $total - $keep);
        if ($overflow) {
            $query = $db->simple_select('feedpublisher_queue', 'id', "feed_id={$feedId} AND state='{$state}'", array(
                'order_by' => $timeField . ',id', 'order_dir' => 'ASC', 'limit' => min($overflow, $limit - count($ids)),
            ));
            while ($row = $db->fetch_array($query)) { $ids[(int) $row['id']] = (int) $row['id']; }
        }
    }
    return array_values($ids);
}

function feedpublisher_retention_cleanup($feed, $limit = 100)
{
    global $db;
    $limit = max(1, min(100, (int) $limit));
    $queueIds = feedpublisher_retention_candidate_ids($feed, $limit);
    if ($queueIds) {
        $db->delete_query('feedpublisher_queue', 'feed_id=' . (int) $feed['id'] . ' AND id IN (' . implode(',', $queueIds) . ')');
    }
    $dedupeDeleted = 0;
    $remaining = $limit - count($queueIds);
    $dedupeDays = isset($feed['dedupe_retention_days']) ? (int) $feed['dedupe_retention_days'] : 0;
    if ($remaining > 0 && $dedupeDays > 0) {
        $cutoff = TIME_NOW - max(1, min(3650, $dedupeDays)) * 86400;
        $ids = array();
        $query = $db->simple_select('feedpublisher_items', 'id', 'feed_id=' . (int) $feed['id'] . " AND imported_at>0 AND imported_at<{$cutoff}", array(
            'order_by' => 'imported_at', 'order_dir' => 'ASC', 'limit' => $remaining,
        ));
        while ($row = $db->fetch_array($query)) { $ids[] = (int) $row['id']; }
        if ($ids) {
            $db->delete_query('feedpublisher_items', 'feed_id=' . (int) $feed['id'] . ' AND id IN (' . implode(',', $ids) . ')');
            $dedupeDeleted = count($ids);
        }
    }
    return array('queue' => count($queueIds), 'dedupe' => $dedupeDeleted);
}

function feedpublisher_reconcile_missing_queued($feed, $items, $limit = 100, $apply = true)
{
    global $db;
    $currentCount = count($items);
    $previousCount = isset($feed['last_feed_item_count']) ? (int) $feed['last_feed_item_count'] : 0;
    if (empty($feed['strict_reconciliation']) || $currentCount === 0 || $previousCount === 0 || $currentCount < $previousCount) {
        return 0;
    }
    $present = array();
    foreach ($items as $item) {
        $identity = feedpublisher_derive_item_identity($feed, $item);
        if ($identity['key'] !== '') { $present[$identity['key']] = true; }
    }
    if (!$present) { return 0; }
    $missing = array();
    $query = $db->simple_select('feedpublisher_queue', 'id,item_key', 'feed_id=' . (int) $feed['id'] . " AND state='queued'", array(
        'order_by' => 'id', 'order_dir' => 'ASC', 'limit' => max(1, min(100, (int) $limit)),
    ));
    while ($row = $db->fetch_array($query)) {
        if (!isset($present[$row['item_key']])) { $missing[] = (int) $row['id']; }
    }
    if ($missing && $apply) {
        $db->update_query('feedpublisher_queue', array('state' => 'rejected', 'published_at' => TIME_NOW,
            'last_error' => $db->escape_string('Rejected by strict source reconciliation: entry is no longer declared by the source feed.')),
            'feed_id=' . (int) $feed['id'] . " AND state='queued' AND id IN (" . implode(',', $missing) . ')');
    }
    return count($missing);
}

function feedpublisher_retention_preview($feed)
{
    global $db;
    $queue = count(feedpublisher_retention_candidate_ids($feed, 100));
    $dedupe = 0;
    $days = isset($feed['dedupe_retention_days']) ? (int) $feed['dedupe_retention_days'] : 0;
    if ($days > 0) {
        $cutoff = TIME_NOW - max(1, min(3650, $days)) * 86400;
        $dedupe = min(100 - $queue, (int) $db->fetch_field($db->simple_select('feedpublisher_items', 'COUNT(id) AS total',
            'feed_id=' . (int) $feed['id'] . " AND imported_at>0 AND imported_at<{$cutoff}"), 'total'));
    }
    return array('queue' => $queue, 'dedupe' => max(0, $dedupe));
}

function feedpublisher_initial_stage_plan($feed, $items)
{
    $policy = isset($feed['initial_policy']) ? $feed['initial_policy'] : 'latest';
    if (!in_array($policy, array('all', 'latest', 'recent', 'start_now'), true)) {
        $policy = 'latest';
    }

    if ($policy === 'all') {
        $plan = array_map(function ($item) {
            return array('item' => $item, 'state' => 'queued');
        }, $items);
    } elseif ($policy === 'start_now') {
        $plan = array_map(function ($item) {
            return array('item' => $item, 'state' => 'skipped');
        }, $items);
    } else {
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
    }
    foreach ($plan as &$entry) {
        $entry['date_plan'] = feedpublisher_source_date_plan($feed, $entry['item']);
        if ($entry['state'] === 'queued') {
            $entry['state'] = $entry['date_plan']['state'];
        }
    }
    unset($entry);
    return $plan;
}

function feedpublisher_eligibility_stage_plan($feed, $items, $initializing)
{
    $eligible = array();
    $rejected = array();
    foreach ($items as $item) {
        $result = feedpublisher_entry_eligibility($feed, $item);
        $item['_eligibility'] = $result;
        if ($result['eligible']) {
            $eligible[] = $item;
        } else {
            $item['_disposition'] = 'filtered';
            $rejected[] = array('item' => $item, 'state' => 'rejected', 'eligibility' => $result,
                'date_plan' => feedpublisher_source_date_plan($feed, $item));
        }
    }
    $plan = $initializing ? feedpublisher_initial_stage_plan($feed, $eligible) : array_map(function ($item) use ($feed) {
        $datePlan = feedpublisher_source_date_plan($feed, $item);
        return array('item' => $item, 'state' => $datePlan['state'], 'eligibility' => $item['_eligibility'],
            'date_plan' => $datePlan);
    }, $eligible);
    foreach ($plan as &$entry) {
        $entry['eligibility'] = $entry['item']['_eligibility'];
        if ($entry['state'] === 'skipped') { $entry['item']['_disposition'] = 'skipped'; }
        elseif ($entry['state'] === 'rejected') { $entry['item']['_disposition'] = 'rejected'; }
    }
    unset($entry);
    return array_merge($plan, $rejected);
}
