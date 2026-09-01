<?php
/**
 * Scheduled task entry point for MyBB Feed Publisher.
 * Copyright (c) 2026 SickProdigy
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!defined('IN_MYBB')) {
    die('Direct access is not allowed.');
}

function task_feedpublisher($task)
{
    global $db, $lang;

    require_once MYBB_ROOT . 'inc/plugins/feedpublisher/core.php';

    $processed = 0;
    $errors = array();
    $query = $db->simple_select('feedpublisher_feeds', '*', 'enabled=1');
    while ($feed = $db->fetch_array($query)) {
        $interval = max(5, (int) $feed['interval_minutes']) * 60;
        if ((int) $feed['last_checked'] > TIME_NOW - $interval) {
            continue;
        }

        try {
            $items = feedpublisher_parse(feedpublisher_fetch($feed['url']));
            foreach ($items as $item) {
                $key = hash('sha256', $item['key']);
                $existing = $db->fetch_field($db->simple_select(
                    'feedpublisher_items',
                    'id',
                    "feed_id=" . (int) $feed['id'] . " AND item_key='" . $db->escape_string($key) . "'",
                    array('limit' => 1)
                ), 'id');
                if ($existing) {
                    continue;
                }

                // Publishing will be enabled after ACP feed configuration and
                // preview controls are implemented. Recording nothing here is
                // intentional: an unpublished item must remain importable.
                feedpublisher_html_to_mycode($item['content']);
                ++$processed;
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
            $errors[] = $feed['name'] . ': ' . $message;
        }
    }

    add_task_log($task, 'Inspected ' . $processed . ' new entries.' . ($errors ? ' Errors: ' . implode('; ', $errors) : ''));
}
