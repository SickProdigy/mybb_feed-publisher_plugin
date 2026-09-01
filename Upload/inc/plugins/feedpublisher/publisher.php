<?php
/**
 * Permission-aware MyBB thread publication.
 * Copyright (c) 2026 SickProdigy
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!defined('IN_MYBB')) {
    die('Direct access is not allowed.');
}

function feedpublisher_publish_queued_item($feed, $item)
{
    global $db, $mybb, $session;

    $forum = $db->fetch_array($db->simple_select('forums', '*', 'fid=' . (int) $feed['fid'], array('limit' => 1)));
    if (!$forum || $forum['type'] !== 'f' || empty($forum['active']) || !empty($forum['linkto'])) {
        throw new RuntimeException('The configured destination is not an active posting forum.');
    }
    if (isset($forum['open']) && !$forum['open']) {
        throw new RuntimeException('The configured destination forum is closed.');
    }

    $user = get_user((int) $feed['uid']);
    if (!$user || empty($user['uid'])) {
        throw new RuntimeException('The configured posting user no longer exists.');
    }
    $banned = $db->fetch_field($db->simple_select(
        'banned',
        'uid',
        'uid=' . (int) $user['uid'] . ' AND (lifted=0 OR lifted>' . TIME_NOW . ')',
        array('limit' => 1)
    ), 'uid');
    if ($banned) {
        throw new RuntimeException('The configured posting user is banned.');
    }

    $permissions = forum_permissions((int) $forum['fid'], (int) $user['uid']);
    if (empty($permissions['canview']) || empty($permissions['canpostthreads'])) {
        throw new RuntimeException('The configured posting user cannot create threads in the destination forum.');
    }

    $subject = trim((string) $item['title']);
    $message = trim((string) $item['content']);
    if ($subject === '' || $message === '') {
        throw new RuntimeException('The queued entry must have a non-empty title and body.');
    }
    $subject = my_substr($subject, 0, 85);
    $message = feedpublisher_add_source_attribution($message, $item, isset($feed['attribution_mode']) ? $feed['attribution_mode'] : 'link');

    require_once MYBB_ROOT . 'inc/datahandlers/post.php';
    $originalUser = $mybb->user;
    $originalUsergroup = $mybb->usergroup;
    try {
        $mybb->user = $user;
        $mybb->usergroup = usergroup_permissions((int) $user['usergroup']);

        $handler = new PostDataHandler('insert');
        $handler->action = 'thread';
        $handler->set_data(array(
            'fid' => (int) $forum['fid'],
            'subject' => $subject,
            'prefix' => 0,
            'icon' => 0,
            'uid' => (int) $user['uid'],
            'username' => $user['username'],
            'message' => $message,
            'ipaddress' => isset($session->packedip) ? $session->packedip : my_inet_pton(get_ip()),
            'posthash' => '',
            'savedraft' => 0,
            'options' => array(
                'signature' => 0,
                'subscriptionmethod' => 0,
                'disablesmilies' => 0,
            ),
        ));
        if (!$handler->validate_thread()) {
            throw new RuntimeException('MyBB rejected the thread: ' . implode(' ', $handler->get_friendly_errors()));
        }
        $result = $handler->insert_thread();
    } finally {
        $mybb->user = $originalUser;
        $mybb->usergroup = $originalUsergroup;
    }

    if (empty($result['tid']) || empty($result['pid'])) {
        throw new RuntimeException('MyBB did not return thread and post IDs after publication.');
    }
    return $result;
}

function feedpublisher_add_source_attribution($message, $item, $mode)
{
    if ($mode === 'none' || empty($item['source_url'])) {
        return $message;
    }

    $url = trim((string) $item['source_url']);
    $parts = parse_url($url);
    if (!$parts || empty($parts['host']) || empty($parts['scheme'])
        || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)
        || isset($parts['user']) || isset($parts['pass'])) {
        return $message;
    }
    $url = str_replace(array('[', ']'), array('%5B', '%5D'), $url);
    $label = $mode === 'title_link' && !empty($item['title'])
        ? trim(strip_tags((string) $item['title']))
        : 'View original source';
    $label = str_replace(array('[', ']'), '', $label);

    return rtrim($message) . "\n\n[hr]\nSource: [url=" . $url . ']' . $label . '[/url]';
}
