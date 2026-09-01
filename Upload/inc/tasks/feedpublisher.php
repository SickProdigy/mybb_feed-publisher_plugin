<?php
/**
 * Scheduled discovery and paced queue task for MyBB Feed Publisher.
 * Copyright (c) 2026 SickProdigy
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!defined('IN_MYBB')) {
    die('Direct access is not allowed.');
}

function task_feedpublisher($task)
{
    global $db;

    require_once MYBB_ROOT . 'inc/plugins/feedpublisher/core.php';
    require_once MYBB_ROOT . 'inc/plugins/feedpublisher/queue.php';
    require_once MYBB_ROOT . 'inc/plugins/feedpublisher/operations.php';
    $publisherFile = MYBB_ROOT . 'inc/plugins/feedpublisher/publisher.php';
    if (is_file($publisherFile)) {
        require_once $publisherFile;
    }

    $totals = array('feeds' => 0, 'staged' => 0, 'skipped' => 0, 'rejected' => 0, 'existing' => 0, 'full' => 0, 'published' => 0, 'failed' => 0);
    $errors = array();
    $query = $db->simple_select('feedpublisher_feeds', '*', 'enabled=1', array('order_by' => 'id'));
    while ($feed = $db->fetch_array($query)) {
        ++$totals['feeds'];
        feedpublisher_retention_cleanup($feed, 100);
        $discoveryInterval = max(5, (int) $feed['interval_minutes']) * 60;
        $discoveryDue = (int) $feed['fetch_failures'] > 0
            ? (int) $feed['next_fetch_at'] <= TIME_NOW
            : (int) $feed['last_checked'] <= TIME_NOW - $discoveryInterval;

        if ($discoveryDue) {
            try {
                $discovery = feedpublisher_discover_feed($feed);
                foreach ($discovery as $name => $total) {
                    if (isset($totals[$name])) {
                        $totals[$name] += $total;
                    }
                }
            } catch (Throwable $exception) {
                $stage = $exception instanceof FeedPublisherException ? $exception->getStage() : 'discovery';
                $message = feedpublisher_safe_log_text($exception->getMessage());
                $errors[] = feedpublisher_safe_log_text($feed['name']) . ' ' . $stage . ': ' . $message;
            }
        }

        if (function_exists('feedpublisher_publish_queued_item')) {
            $dispatch = feedpublisher_queue_dispatch($feed, 'feedpublisher_publish_queued_item');
            $totals['published'] += $dispatch['published'];
            $totals['failed'] += $dispatch['failed'];
        }
    }

    $message = 'Checked ' . $totals['feeds'] . ' enabled feeds; staged ' . $totals['staged']
        . ', initially skipped ' . $totals['skipped'] . ', rejected ' . $totals['rejected'] . ', already known ' . $totals['existing'] . ', queue-full skips ' . $totals['full']
        . ', published ' . $totals['published'] . ', publication failures ' . $totals['failed'] . '.';
    if ($errors) {
        $message .= ' Errors: ' . implode('; ', $errors);
    }
    feedpublisher_log_event(0, 'task', $errors ? 'warning' : 'info', $message);
    feedpublisher_log_prune(100);
    add_task_log($task, $message);
}
