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
    $publisherFile = MYBB_ROOT . 'inc/plugins/feedpublisher/publisher.php';
    if (is_file($publisherFile)) {
        require_once $publisherFile;
    }

    $totals = array('feeds' => 0, 'staged' => 0, 'existing' => 0, 'full' => 0, 'published' => 0, 'failed' => 0);
    $errors = array();
    $query = $db->simple_select('feedpublisher_feeds', '*', 'enabled=1', array('order_by' => 'id'));
    while ($feed = $db->fetch_array($query)) {
        ++$totals['feeds'];
        feedpublisher_queue_prune((int) $feed['id']);
        $discoveryInterval = max(5, (int) $feed['interval_minutes']) * 60;
        $discoveryDue = (int) $feed['last_checked'] <= TIME_NOW - $discoveryInterval;

        if ($discoveryDue) {
            try {
                $items = feedpublisher_parse(feedpublisher_fetch($feed['url']));
                foreach ($items as $item) {
                    $result = feedpublisher_queue_stage($feed, $item);
                    if (isset($totals[$result])) {
                        ++$totals[$result];
                    }
                }
                $db->update_query('feedpublisher_feeds', array(
                    'last_checked' => TIME_NOW,
                    'last_error' => '',
                ), 'id=' . (int) $feed['id']);
            } catch (Throwable $exception) {
                $message = substr($exception->getMessage(), 0, 1000);
                $db->update_query('feedpublisher_feeds', array(
                    'last_checked' => TIME_NOW,
                    'last_error' => $db->escape_string($message),
                ), 'id=' . (int) $feed['id']);
                $errors[] = $feed['name'] . ' discovery: ' . $message;
            }
        }

        if (function_exists('feedpublisher_publish_queued_item')) {
            $dispatch = feedpublisher_queue_dispatch($feed, 'feedpublisher_publish_queued_item');
            $totals['published'] += $dispatch['published'];
            $totals['failed'] += $dispatch['failed'];
        }
    }

    $message = 'Checked ' . $totals['feeds'] . ' enabled feeds; staged ' . $totals['staged']
        . ', already known ' . $totals['existing'] . ', queue-full skips ' . $totals['full']
        . ', published ' . $totals['published'] . ', publication failures ' . $totals['failed'] . '.';
    if (!function_exists('feedpublisher_publish_queued_item')) {
        $message .= ' Publishing remains disabled until the issue #3 publisher is installed.';
    }
    if ($errors) {
        $message .= ' Errors: ' . implode('; ', $errors);
    }
    add_task_log($task, $message);
}
