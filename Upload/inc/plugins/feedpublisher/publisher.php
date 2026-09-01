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

    $post = feedpublisher_compose_post($feed, $item);
    $subject = $post['title'];
    $message = $post['body'];
    if ($subject === '' || $message === '') {
        throw new RuntimeException('The queued entry must have a non-empty title and body.');
    }
    $threadPrefixId = isset($feed['thread_prefix_id']) ? (int) $feed['thread_prefix_id'] : 0;
    if ($threadPrefixId && !feedpublisher_thread_prefix_is_available($threadPrefixId, $forum['fid'], $user)) {
        throw new RuntimeException('The selected MyBB thread prefix is no longer available to the posting user in the destination forum.');
    }
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
            'prefix' => $threadPrefixId,
            'icon' => 0,
            'uid' => (int) $user['uid'],
            'username' => $user['username'],
            'message' => $message,
            'dateline' => feedpublisher_thread_dateline($feed, $item),
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

function feedpublisher_normalize_title_prefix($prefix)
{
    return trim(preg_replace('/\s+/u', ' ', (string) $prefix));
}

function feedpublisher_build_subject($title, $prefix = '')
{
    $title = trim(preg_replace('/\s+/u', ' ', (string) $title));
    $prefix = feedpublisher_normalize_title_prefix($prefix);
    if ($prefix === '') {
        return my_substr($title, 0, 85);
    }
    $remaining = 85 - my_strlen($prefix) - 1;
    return $remaining > 0 ? $prefix . ' ' . my_substr($title, 0, $remaining) : my_substr($prefix, 0, 85);
}

function feedpublisher_available_thread_prefixes($fid, $user)
{
    $available = array();
    $prefixes = build_prefixes(0);
    if (!$fid || !$user || empty($user['uid']) || !is_array($prefixes)) {
        return $available;
    }
    foreach ($prefixes as $prefix) {
        if ($prefix['forums'] !== '-1' && !in_array((int) $fid, array_map('intval', explode(',', $prefix['forums'])), true)) {
            continue;
        }
        if (!is_member($prefix['groups'], $user)) {
            continue;
        }
        $available[(int) $prefix['pid']] = $prefix;
    }
    return $available;
}

function feedpublisher_thread_prefix_is_available($prefixId, $fid, $user)
{
    if (!(int) $prefixId) {
        return true;
    }
    $available = feedpublisher_available_thread_prefixes((int) $fid, $user);
    return isset($available[(int) $prefixId]);
}

function feedpublisher_thread_prefix_label($prefix)
{
    if (!$prefix) {
        return 'No MyBB prefix';
    }
    $label = isset($prefix['prefix']) ? trim(strip_tags((string) $prefix['prefix'])) : '';
    return $label !== '' ? $label : 'Prefix #' . (int) $prefix['pid'];
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

function feedpublisher_template_errors($header, $footer)
{
    $errors = array();
    $allowed = array('title', 'source_url', 'feed_name', 'author', 'published_date');
    foreach (array('Header' => $header, 'Footer' => $footer) as $label => $template) {
        if (strlen((string) $template) > 10000) $errors[] = $label . ' template must not exceed 10,000 bytes.';
        preg_match_all('/\{([^{}]+)\}/', (string) $template, $matches);
        foreach ($matches[1] as $placeholder) {
            if (!in_array($placeholder, $allowed, true)) $errors[] = $label . ' template contains unsupported placeholder {' . $placeholder . '}.';
        }
        if (strpos((string) $template, '<?') !== false) $errors[] = $label . ' template cannot contain PHP opening tags.';
    }
    return $errors;
}

function feedpublisher_render_template($template, $feed, $item)
{
    $safe = function ($value) { return str_replace(array('[', ']'), array('&#91;', '&#93;'), trim(strip_tags((string) $value))); };
    $published = isset($item['source_published']) ? (int) $item['source_published'] : (int) (isset($item['published']) ? $item['published'] : 0);
    $url = isset($item['source_url']) ? $item['source_url'] : (isset($item['url']) ? $item['url'] : '');
    $url = feedpublisher_safe_content_url($url) ? str_replace(array('[', ']'), array('%5B', '%5D'), $url) : '';
    return strtr((string) $template, array(
        '{title}' => $safe(isset($item['title']) ? $item['title'] : ''),
        '{source_url}' => $url,
        '{feed_name}' => $safe(isset($feed['name']) ? $feed['name'] : ''),
        '{author}' => $safe(isset($item['author']) ? $item['author'] : ''),
        '{published_date}' => $published > 0 ? date('Y-m-d H:i:s', $published) : '',
    ));
}

function feedpublisher_word_safe_excerpt($message, $limit, &$truncated)
{
    $truncated = false;
    $limit = (int) $limit;
    if ($limit < 1 || my_strlen($message) <= $limit) return $message;
    $plain = trim(preg_replace('/\[[^\]\r\n]{1,200}\]/', '', $message));
    if (my_strlen($plain) <= $limit) return $message;
    $excerpt = my_substr($plain, 0, $limit + 1);
    if (preg_match('/^(.{1,' . $limit . '})(?:\s+\S*)$/us', $excerpt, $match)) $excerpt = $match[1];
    else $excerpt = my_substr($excerpt, 0, $limit);
    $truncated = true;
    return rtrim($excerpt) . '...';
}

function feedpublisher_compose_post($feed, $item)
{
    $body = trim((string) $item['content']);
    $truncated = false;
    $body = feedpublisher_word_safe_excerpt($body, isset($feed['body_length_limit']) ? $feed['body_length_limit'] : 0, $truncated);
    $parts = array();
    $header = feedpublisher_render_template(isset($feed['post_header']) ? $feed['post_header'] : '', $feed, $item);
    if (trim($header) !== '') $parts[] = trim($header);
    $parts[] = $body;
    if ($truncated && isset($feed['continuation_mode']) && $feed['continuation_mode'] === 'source_link') {
        $url = isset($item['source_url']) ? $item['source_url'] : (isset($item['url']) ? $item['url'] : '');
        if (feedpublisher_safe_content_url($url)) {
            $label = trim(isset($feed['continuation_text']) ? $feed['continuation_text'] : 'Continue reading');
            $label = $label !== '' ? str_replace(array('[', ']'), '', $label) : 'Continue reading';
            $parts[] = '[url=' . str_replace(array('[', ']'), array('%5B', '%5D'), $url) . ']' . $label . '[/url]';
        }
    }
    $footer = feedpublisher_render_template(isset($feed['post_footer']) ? $feed['post_footer'] : '', $feed, $item);
    if (trim($footer) !== '') $parts[] = trim($footer);
    $body = feedpublisher_add_source_attribution(implode("\n\n", $parts), $item, isset($feed['attribution_mode']) ? $feed['attribution_mode'] : 'link');
    return array('title' => feedpublisher_build_subject($item['title'], isset($feed['title_prefix']) ? $feed['title_prefix'] : ''), 'body' => $body, 'truncated' => $truncated);
}
