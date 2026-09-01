<?php
/**
 * Admin CP controller for MyBB Feed Publisher.
 * Copyright (c) 2026 SickProdigy
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!defined('IN_MYBB') || !defined('IN_ADMINCP')) {
    die('Direct access is not allowed.');
}

function feedpublisher_admin_controller()
{
    global $mybb, $page;

    if (!check_admin_permissions(array('module' => 'config', 'action' => 'feedpublisher'), false)) {
        $page->output_error('You do not have permission to manage Feed Publisher feeds.');
    }

    feedpublisher_upgrade_schema();
    require_once MYBB_ADMIN_DIR . 'inc/class_form.php';
    require_once MYBB_ADMIN_DIR . 'inc/class_table.php';
    require_once MYBB_ROOT . 'inc/plugins/feedpublisher/core.php';
    require_once MYBB_ROOT . 'inc/plugins/feedpublisher/queue.php';
    require_once MYBB_ROOT . 'inc/plugins/feedpublisher/publisher.php';
    require_once MYBB_ROOT . 'inc/plugins/feedpublisher/operations.php';

    $action = $mybb->get_input('action');
    if ($action === 'save') {
        feedpublisher_admin_save();
    } elseif ($action === 'operation') {
        feedpublisher_admin_operation_commit();
    } elseif ($action === 'operations') {
        feedpublisher_admin_operations_page();
    } elseif ($action === 'preview') {
        feedpublisher_admin_preview_saved();
    } elseif ($action === 'delete') {
        feedpublisher_admin_delete();
    } elseif ($action === 'add' || $action === 'edit') {
        feedpublisher_admin_form($action);
    } else {
        feedpublisher_admin_list();
    }
}

function feedpublisher_admin_tabs($active)
{
    global $page;

    $page->output_nav_tabs(array(
        'feeds' => array(
            'title' => 'Feeds',
            'link' => 'index.php?module=config/feedpublisher',
            'description' => 'Manage RSS and Atom feeds imported by Feed Publisher.',
        ),
        'add' => array(
            'title' => 'Add feed',
            'link' => 'index.php?module=config/feedpublisher&amp;action=add',
            'description' => 'Configure a new RSS or Atom feed.',
        ),
    ), $active);
}

function feedpublisher_admin_list()
{
    global $db, $page;

    $page->add_breadcrumb_item('Feed Publisher');
    $page->output_header('Feed Publisher');
    feedpublisher_admin_tabs('feeds');

    $table = new Table;
    foreach (array('Name', 'Destination forum', 'Posting user', 'Interval', 'Status', 'Queue', 'Last result', 'Controls') as $heading) {
        $table->construct_header($heading);
    }

    $query = $db->write_query(
        'SELECT f.*, fo.name AS forum_name, u.username '
        . 'FROM ' . TABLE_PREFIX . 'feedpublisher_feeds f '
        . 'LEFT JOIN ' . TABLE_PREFIX . 'forums fo ON (fo.fid=f.fid) '
        . 'LEFT JOIN ' . TABLE_PREFIX . 'users u ON (u.uid=f.uid) '
        . 'ORDER BY f.name ASC'
    );

    while ($feed = $db->fetch_array($query)) {
        $lastResult = 'Never checked';
        if ((int) $feed['last_checked'] > 0) {
            $lastResult = my_date('relative', (int) $feed['last_checked']);
            if ($feed['last_error'] !== '') {
                $lastResult .= '<br><span style="color:#a00">' . htmlspecialchars_uni($feed['last_error']) . '</span>';
            }
        }

        $id = (int) $feed['id'];
        $counts = feedpublisher_queue_counts($id);
        $queueStatus = 'Queued: ' . $counts['queued'] . '<br>Processing: ' . $counts['processing']
            . '<br>Published: ' . $counts['published'] . '<br>Skipped: ' . $counts['skipped']
            . '<br>Failed: ' . $counts['failed'] . '<br>Uncertain: ' . $counts['uncertain']
            . '<br>Rejected: ' . $counts['rejected'];
        $initialStatus = empty($feed['initialized_at'])
            ? 'Initial scan pending (' . htmlspecialchars_uni($feed['initial_policy']) . ')'
            : 'Initial scan: ' . my_date('relative', (int) $feed['initialized_at']) . ' (' . htmlspecialchars_uni($feed['initial_policy']) . ')';
        $controls = '<a href="index.php?module=config/feedpublisher&amp;action=edit&amp;id=' . $id . '">Edit</a>'
            . ' &middot; <a href="index.php?module=config/feedpublisher&amp;action=preview&amp;id=' . $id . '">Preview</a>'
            . ' &middot; <a href="index.php?module=config/feedpublisher&amp;action=operations&amp;id=' . $id . '">Operations</a>'
            . ' &middot; <a href="index.php?module=config/feedpublisher&amp;action=delete&amp;id=' . $id . '">Delete</a>';
        $table->construct_cell('<strong>' . htmlspecialchars_uni($feed['name']) . '</strong><br><small>' . htmlspecialchars_uni($feed['url']) . '</small>');
        $table->construct_cell(htmlspecialchars_uni($feed['forum_name'] ?: 'Missing forum'));
        $table->construct_cell(htmlspecialchars_uni($feed['username'] ?: 'Missing user'));
        $table->construct_cell((int) $feed['interval_minutes'] . ' minutes');
        $table->construct_cell($feed['enabled'] ? 'Enabled' : 'Disabled');
        $table->construct_cell($queueStatus . '<br>' . $initialStatus . (!empty($feed['publishing_paused']) ? '<br><strong>Publishing paused</strong>' : ''));
        $table->construct_cell($lastResult);
        $table->construct_cell($controls);
        $table->construct_row();
    }

    if ($table->num_rows() === 0) {
        $table->construct_cell('No feeds have been configured.', array('colspan' => 8));
        $table->construct_row();
    }

    $table->output('Configured feeds');
    $page->output_footer();
}

function feedpublisher_admin_form($action, $values = array(), $errors = array())
{
    global $db, $mybb, $page;

    $id = $mybb->get_input('id', MyBB::INPUT_INT);
    if (!$values && $action === 'edit') {
        $values = $db->fetch_array($db->simple_select('feedpublisher_feeds', '*', 'id=' . $id, array('limit' => 1)));
        if (!$values) {
            flash_message('The selected feed does not exist.', 'error');
            admin_redirect('index.php?module=config/feedpublisher');
        }
    }
    if ($mybb->get_input('feed_url') !== '') {
        $values['url'] = trim($mybb->get_input('feed_url'));
    }

    $values = array_merge(array(
        'id' => $id,
        'name' => '',
        'url' => $action === 'add' ? trim($mybb->get_input('feed_url')) : '',
        'fid' => 0,
        'uid' => 0,
        'title_prefix' => '',
        'thread_prefix_id' => 0,
        'thread_date_mode' => 'publish',
        'future_date_policy' => 'hold',
        'schedule_jitter_minutes' => 0,
        'identity_strategy' => 'guid_link',
        'enabled' => 0,
        'interval_minutes' => 60,
        'publish_interval_minutes' => 60,
        'max_posts_per_run' => 1,
        'queue_order' => 'oldest',
        'publishing_paused' => 0,
        'initial_policy' => 'latest',
        'initial_limit' => 1,
        'initialized_at' => 0,
        'attribution_mode' => 'link',
        'remove_bylines' => 0,
        'remove_source_links' => 0,
        'strip_selectors' => '',
        'strip_regexes' => '',
    ), $values);

    $page->add_breadcrumb_item('Feed Publisher', 'index.php?module=config/feedpublisher');
    $page->add_breadcrumb_item($action === 'edit' ? 'Edit feed' : 'Add feed');
    $page->output_header(($action === 'edit' ? 'Edit' : 'Add') . ' Feed Publisher feed');
    feedpublisher_admin_tabs($action === 'edit' ? 'feeds' : 'add');
    if ($errors) {
        $page->output_inline_error($errors);
    }

    $forums = array(0 => 'Select a forum');
    $query = $db->simple_select('forums', 'fid,name', "type='f'", array('order_by' => 'disporder,name'));
    while ($forum = $db->fetch_array($query)) {
        $forums[(int) $forum['fid']] = $forum['name'];
    }

    $users = array(0 => 'Select a user');
    $query = $db->simple_select('users', 'uid,username', '', array('order_by' => 'username'));
    while ($user = $db->fetch_array($query)) {
        $users[(int) $user['uid']] = $user['username'];
    }

    $prefixOptions = array(0 => 'No MyBB prefix');
    $postingUser = !empty($values['uid']) ? get_user((int) $values['uid']) : array();
    foreach (feedpublisher_available_thread_prefixes((int) $values['fid'], $postingUser) as $prefixId => $prefix) {
        $prefixOptions[$prefixId] = feedpublisher_thread_prefix_label($prefix);
    }
    $selectedPrefixId = (int) $values['thread_prefix_id'];
    if ($selectedPrefixId && !isset($prefixOptions[$selectedPrefixId])) {
        $prefixOptions[$selectedPrefixId] = 'Unavailable prefix #' . $selectedPrefixId . ' - select another option';
    }

    $form = new Form('index.php?module=config/feedpublisher&amp;action=save', 'post');
    echo $form->generate_hidden_field('id', (int) $values['id']);
    $container = new FormContainer($action === 'edit' ? 'Edit feed' : 'Add feed');
    $container->output_row('Name <em>*</em>', 'A descriptive name shown in the Admin CP and task logs.', $form->generate_text_box('name', $values['name']));
    $container->output_row('Feed or website URL <em>*</em>', 'Enter an exact public RSS/Atom URL, or enter a normal website URL and use Find feeds.', $form->generate_text_box('url', $values['url']));
    $refresh = "this.form.elements['refresh_prefixes'].click();";
    $container->output_row('Destination forum <em>*</em>', 'New entries will be published to this forum. Changing it refreshes the available MyBB prefixes.', $form->generate_select_box('fid', $forums, (int) $values['fid'], array('onchange' => $refresh)));
    $container->output_row('Posting user <em>*</em>', 'The MyBB account used as the post author. Changing it refreshes the prefixes this user may apply.', $form->generate_select_box('uid', $users, (int) $values['uid'], array('onchange' => $refresh)));
    $container->output_row('Title prefix text', 'Optional text added to the beginning of every generated title, such as [RSS] or Freebie:.', $form->generate_text_box('title_prefix', $values['title_prefix'], array('maxlength' => 40)));
    $container->output_row('MyBB thread prefix', 'Optional built-in styled prefix available to the selected posting user in the destination forum.', $form->generate_select_box('thread_prefix_id', $prefixOptions, $selectedPrefixId)
        . ' <input type="submit" class="button" name="refresh_prefixes" value="Refresh prefix choices" style="display:none">');
    $container->output_row('Thread date', 'Choose whether MyBB shows when Feed Publisher created the thread or the valid publication date supplied by the feed.', $form->generate_select_box('thread_date_mode', array('publish' => 'Time posted to MyBB', 'source' => 'Original feed publication time'), $values['thread_date_mode']));
    $container->output_row('Future-dated entries', 'Choose what happens when a feed says an entry is scheduled for a future time.', $form->generate_select_box('future_date_policy', array('hold' => 'Hold until that time', 'clamp' => 'Publish normally using the current time', 'skip' => 'Mark as seen; do not publish', 'reject' => 'Reject permanently'), $values['future_date_policy']));
    $container->output_row('Scheduling spread', 'Optionally delay newly queued entries by a repeatable 0 to this many minutes (maximum 60). Normal publication pacing still applies.', $form->generate_numeric_field('schedule_jitter_minutes', (int) $values['schedule_jitter_minutes'], array('min' => 0, 'max' => 60)));
    $container->output_row('Duplicate identity', 'Controls how Feed Publisher recognizes an entry it has already seen. GUID/link is safest and remains the default. Title or content fallbacks help broken feeds but can match unrelated entries.', $form->generate_select_box('identity_strategy', array(
        'guid_link' => 'GUID or link (recommended)',
        'title' => 'Normalized title',
        'content' => 'Normalized content fingerprint',
        'title_content' => 'Normalized title + content fingerprint',
    ), $values['identity_strategy']));
    if (!empty($values['id'])) {
        $container->output_row('Duplicate identity change', 'Changing the strategy cannot safely translate old hashes. Resetting removes this feed\'s queue and duplicate history, but does not delete existing MyBB threads; previously published entries may become eligible again.', $form->generate_check_box('reset_identity_history', 1, 'Confirm identity-strategy change and reset queue/import history.'));
    }
    $container->output_row('Source attribution <em>*</em>', 'Append a source link to every imported thread. Keeping attribution enabled is recommended.', $form->generate_select_box('attribution_mode', array('link' => 'Source link', 'title_link' => 'Linked source title', 'none' => 'None'), $values['attribution_mode']));
    $container->output_row('Initial import policy <em>*</em>', 'Controls the first successful scan only. All available queues the full feed; most recent queues one; recent count queues a bounded number; start now records current entries as seen without publishing them.', $form->generate_select_box('initial_policy', array('all' => 'All available entries', 'latest' => 'Most recent only', 'recent' => 'Recent count', 'start_now' => 'Start now (skip current backlog)'), $values['initial_policy']));
    $container->output_row('Initial recent count', 'Used only by the Recent count policy (1 to 100).', $form->generate_numeric_field('initial_limit', (int) $values['initial_limit'], array('min' => 1, 'max' => 100)));
    if (!empty($values['initialized_at'])) {
        $container->output_row('Initial scan completed', my_date('relative', (int) $values['initialized_at']) . '. Changing the policy requires confirmation and resets queued, skipped, and failed entries for the next discovery.', $form->generate_check_box('reset_initial_policy', 1, 'Confirm reset if the policy or recent count is changed.'));
    }
    $container->output_row('Import interval <em>*</em>', 'Minutes between checks (minimum 5, maximum 10080).', $form->generate_numeric_field('interval_minutes', (int) $values['interval_minutes'], array('min' => 5, 'max' => 10080)));
    $container->output_row('Publication interval <em>*</em>', 'Minimum minutes between publishing batches for this feed.', $form->generate_numeric_field('publish_interval_minutes', (int) $values['publish_interval_minutes'], array('min' => 5, 'max' => 10080)));
    $container->output_row('Maximum posts per run <em>*</em>', 'Maximum queued entries released for this feed in one task run (1 to 25). Use 1 for gradual posting.', $form->generate_numeric_field('max_posts_per_run', (int) $values['max_posts_per_run'], array('min' => 1, 'max' => 25)));
    $container->output_row('Queue order <em>*</em>', 'Choose which queued entry is published first.', $form->generate_select_box('queue_order', array('oldest' => 'Oldest first', 'newest' => 'Newest first'), $values['queue_order']));
    $container->output_row('Common cleanup', 'Remove recognized author/byline blocks and trailing source/read-more backlink blocks before conversion.', $form->generate_check_box('remove_bylines', 1, 'Remove common author and byline blocks', array('checked' => !empty($values['remove_bylines']))) . '<br>' . $form->generate_check_box('remove_source_links', 1, 'Remove common source and read-more backlink blocks', array('checked' => !empty($values['remove_source_links']))));
    $container->output_row('Cleanup selectors', 'Optional simple CSS selectors, one per line: tag, .class, #id, tag.class, [attribute], or tag[attribute].', $form->generate_text_area('strip_selectors', $values['strip_selectors']));
    $container->output_row('Cleanup regular expressions', 'Optional PHP-compatible regular expressions, one per line. Matching text is removed; rules are validated before saving.', $form->generate_text_area('strip_regexes', $values['strip_regexes']));
    $container->output_row('Pause publishing', 'Keep checking this feed and adding new entries to its queue, but do not create MyBB threads until publishing is resumed.', $form->generate_check_box('publishing_paused', 1, 'Keep collecting entries, but do not publish them yet', array('checked' => !empty($values['publishing_paused']))));
    $container->output_row('Feed enabled', 'Turn this off to stop this feed completely. Feed Publisher will not check it for new entries or publish its queued entries.', $form->generate_check_box('enabled', 1, 'Enable checking and publishing for this feed', array('checked' => !empty($values['enabled']))));
    $container->end();
    $form->output_submit_wrapper(array(
        $form->generate_submit_button($action === 'edit' ? 'Save feed' : 'Add feed', array('name' => 'save_feed')),
        $form->generate_submit_button('Preview / dry run', array('name' => 'preview_initial')),
        $form->generate_submit_button('Test connection', array('name' => 'test_connection')),
        $form->generate_submit_button('Find feeds', array('name' => 'find_feeds'))
    ));
    $form->end();
    $page->output_footer();
}

function feedpublisher_admin_connection_results($values, $candidates, $pageMetadata = array())
{
    global $page;

    $page->add_breadcrumb_item('Feed Publisher', 'index.php?module=config/feedpublisher');
    $page->add_breadcrumb_item('Source test');
    $page->output_header('Feed Publisher source test');
    feedpublisher_admin_tabs(!empty($values['id']) ? 'feeds' : 'add');
    if ($pageMetadata) {
        echo '<p><strong>Website fetch:</strong> HTTP ' . (int) $pageMetadata['http_status']
            . ' &middot; ' . htmlspecialchars_uni($pageMetadata['content_type'])
            . ' &middot; Redirects: ' . (int) $pageMetadata['redirects'] . '</p>';
    }
    if (!$candidates) {
        echo '<div class="error"><p>No declared RSS or Atom links were found. Feed Publisher checks only HTML alternate-link declarations and does not crawl the website.</p></div>';
    } else {
        $table = new Table;
        foreach (array('Feed', 'Connection', 'Detected content', 'Entries', 'Action') as $heading) {
            $table->construct_header($heading);
        }
        foreach (array_slice($candidates, 0, 20) as $candidate) {
            $result = feedpublisher_test_feed_connection($candidate['url']);
            $fetch = $result['fetch'];
            $parse = $result['parse'];
            $title = !empty($parse['title']) ? $parse['title'] : (!empty($candidate['declared_title']) ? $candidate['declared_title'] : 'Untitled feed');
            $table->construct_cell('<strong>' . htmlspecialchars_uni($title) . '</strong><br><small>' . htmlspecialchars_uni($candidate['url']) . '</small>');
            $connection = ($result['ok'] ? '<span style="color:#287b31">Success</span>' : '<span style="color:#a00">Failed at ' . htmlspecialchars_uni($result['stage']) . '</span>')
                . '<br><small>HTTP ' . (isset($fetch['http_status']) ? (int) $fetch['http_status'] : 'not received')
                . ' &middot; ' . htmlspecialchars_uni(isset($fetch['content_type']) && $fetch['content_type'] !== '' ? $fetch['content_type'] : 'no content type')
                . ' &middot; Redirects: ' . (isset($fetch['redirects']) ? (int) $fetch['redirects'] : 0) . '</small>';
            if (!$result['ok']) {
                $connection .= '<br><small>' . htmlspecialchars_uni($result['error']) . '</small>';
            }
            $table->construct_cell($connection);
            $table->construct_cell($result['ok'] ? htmlspecialchars_uni($parse['format']) . '<br><small>' . htmlspecialchars_uni($parse['encoding']) . '</small>' : 'Not parsed');
            $newest = $result['newest'] ? my_date('normal', $result['newest']) : 'No valid source date';
            $table->construct_cell($result['ok'] ? (int) $result['items'] . '<br><small>Newest: ' . $newest . '</small>' : '&mdash;');
            $useUrl = 'index.php?module=config/feedpublisher&amp;action=' . (!empty($values['id']) ? 'edit&amp;id=' . (int) $values['id'] : 'add')
                . '&amp;feed_url=' . rawurlencode($candidate['url']);
            $table->construct_cell($result['ok'] ? '<a class="button" href="' . $useUrl . '">Use this feed</a>' : 'Unavailable');
            $table->construct_row();
        }
        $table->output($pageMetadata ? 'Declared feeds' : 'Connection result');
    }
    echo '<p><a class="button" href="#" onclick="history.back(); return false;">&larr; Return to feed form</a> '
        . '<a class="button" href="index.php?module=config/feedpublisher&amp;action=add">+ Add feed</a></p>';
    $page->output_footer();
}

function feedpublisher_admin_save()
{
    global $db, $mybb;

    verify_post_check($mybb->get_input('my_post_key'));
    $id = $mybb->get_input('id', MyBB::INPUT_INT);
    $currentFeed = array();
    if ($id) {
        $currentFeed = $db->fetch_array($db->simple_select('feedpublisher_feeds', '*', 'id=' . $id, array('limit' => 1)));
    }
    $values = array(
        'id' => $id,
        'name' => trim($mybb->get_input('name')),
        'url' => trim($mybb->get_input('url')),
        'fid' => $mybb->get_input('fid', MyBB::INPUT_INT),
        'uid' => $mybb->get_input('uid', MyBB::INPUT_INT),
        'title_prefix' => $mybb->get_input('title_prefix'),
        'thread_prefix_id' => $mybb->get_input('thread_prefix_id', MyBB::INPUT_INT),
        'thread_date_mode' => $mybb->get_input('thread_date_mode'),
        'future_date_policy' => $mybb->get_input('future_date_policy'),
        'schedule_jitter_minutes' => $mybb->get_input('schedule_jitter_minutes', MyBB::INPUT_INT),
        'identity_strategy' => $mybb->get_input('identity_strategy'),
        'reset_identity_history' => $mybb->get_input('reset_identity_history', MyBB::INPUT_INT) ? 1 : 0,
        'interval_minutes' => $mybb->get_input('interval_minutes', MyBB::INPUT_INT),
        'publish_interval_minutes' => $mybb->get_input('publish_interval_minutes', MyBB::INPUT_INT),
        'max_posts_per_run' => $mybb->get_input('max_posts_per_run', MyBB::INPUT_INT),
        'queue_order' => $mybb->get_input('queue_order'),
        'publishing_paused' => $mybb->get_input('publishing_paused', MyBB::INPUT_INT) ? 1 : 0,
        'initial_policy' => $mybb->get_input('initial_policy'),
        'initial_limit' => $mybb->get_input('initial_limit', MyBB::INPUT_INT),
        'attribution_mode' => $mybb->get_input('attribution_mode'),
        'reset_initial_policy' => $mybb->get_input('reset_initial_policy', MyBB::INPUT_INT) ? 1 : 0,
        'preview_initial' => isset($mybb->input['preview_initial']) ? 1 : 0,
        'refresh_prefixes' => isset($mybb->input['refresh_prefixes']) ? 1 : 0,
        'test_connection' => isset($mybb->input['test_connection']) ? 1 : 0,
        'find_feeds' => isset($mybb->input['find_feeds']) ? 1 : 0,
        'strip_selectors' => trim($mybb->get_input('strip_selectors')),
        'strip_regexes' => trim($mybb->get_input('strip_regexes')),
        'remove_bylines' => $mybb->get_input('remove_bylines', MyBB::INPUT_INT) ? 1 : 0,
        'remove_source_links' => $mybb->get_input('remove_source_links', MyBB::INPUT_INT) ? 1 : 0,
        'enabled' => $mybb->get_input('enabled', MyBB::INPUT_INT) ? 1 : 0,
    );
    $errors = array();

    if ($values['refresh_prefixes']) {
        feedpublisher_admin_form($id ? 'edit' : 'add', $values);
        return;
    }

    if (($values['test_connection'] || $values['find_feeds']) && !feedpublisher_admin_url_is_valid($values['url'])) {
        feedpublisher_admin_form($id ? 'edit' : 'add', $values, array('Enter a valid public HTTP or HTTPS URL before testing.'));
        return;
    }
    if ($values['test_connection']) {
        feedpublisher_admin_connection_results($values, array(array('url' => $values['url'], 'declared_title' => $values['name'])));
        return;
    }
    if ($values['find_feeds']) {
        try {
            $discovery = feedpublisher_discover_declared_feeds($values['url']);
            feedpublisher_admin_connection_results($values, $discovery['candidates'], $discovery['page']);
        } catch (Throwable $exception) {
            feedpublisher_admin_form($id ? 'edit' : 'add', $values, array('Feed discovery failed: ' . htmlspecialchars_uni(feedpublisher_safe_log_text($exception->getMessage()))));
        }
        return;
    }

    if ($values['name'] === '' || my_strlen($values['name']) > 150) {
        $errors[] = 'Enter a feed name no longer than 150 characters.';
    }
    if (!feedpublisher_admin_url_is_valid($values['url'])) {
        $errors[] = 'Enter a valid public HTTP or HTTPS feed URL.';
    }
    if (!$db->fetch_field($db->simple_select('forums', 'fid', "fid={$values['fid']} AND type='f'", array('limit' => 1)), 'fid')) {
        $errors[] = 'Select a valid destination forum.';
    }
    if (!$db->fetch_field($db->simple_select('users', 'uid', 'uid=' . $values['uid'], array('limit' => 1)), 'uid')) {
        $errors[] = 'Select a valid posting user.';
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $values['title_prefix']) || my_strlen(feedpublisher_normalize_title_prefix($values['title_prefix'])) > 40) {
        $errors[] = 'Title prefix text must be one line and no longer than 40 characters.';
    }
    $values['title_prefix'] = feedpublisher_normalize_title_prefix($values['title_prefix']);
    $postingUser = get_user($values['uid']);
    if ($values['thread_prefix_id'] && !feedpublisher_thread_prefix_is_available($values['thread_prefix_id'], $values['fid'], $postingUser)) {
        $errors[] = 'Select a MyBB thread prefix available to the posting user in the destination forum.';
    }
    if (!in_array($values['thread_date_mode'], array('publish', 'source'), true)) {
        $errors[] = 'Select a valid thread date option.';
    }
    if (!in_array($values['future_date_policy'], array('hold', 'clamp', 'skip', 'reject'), true)) {
        $errors[] = 'Select a valid future-date policy.';
    }
    if ($values['schedule_jitter_minutes'] < 0 || $values['schedule_jitter_minutes'] > 60) {
        $errors[] = 'Scheduling spread must be between 0 and 60 minutes.';
    }
    if (!in_array($values['identity_strategy'], array('guid_link', 'title', 'content', 'title_content'), true)) {
        $errors[] = 'Select a valid duplicate identity strategy.';
    }
    if ($values['interval_minutes'] < 5 || $values['interval_minutes'] > 10080) {
        $errors[] = 'The import interval must be between 5 and 10080 minutes.';
    }
    if ($values['publish_interval_minutes'] < 5 || $values['publish_interval_minutes'] > 10080) {
        $errors[] = 'The publication interval must be between 5 and 10080 minutes.';
    }
    if ($values['max_posts_per_run'] < 1 || $values['max_posts_per_run'] > 25) {
        $errors[] = 'Maximum posts per run must be between 1 and 25.';
    }
    if (!in_array($values['initial_policy'], array('all', 'latest', 'recent', 'start_now'), true)) {
        $errors[] = 'Select a valid initial import policy.';
    }
    if ($values['initial_limit'] < 1 || $values['initial_limit'] > 100) {
        $errors[] = 'The initial recent count must be between 1 and 100.';
    }
    if (!in_array($values['queue_order'], array('oldest', 'newest'), true)) {
        $errors[] = 'Select a valid queue order.';
    }
    if (!in_array($values['attribution_mode'], array('link', 'title_link', 'none'), true)) {
        $errors[] = 'Select a valid source attribution mode.';
    }
    foreach (feedpublisher_cleanup_validate_rules($values['strip_selectors'], $values['strip_regexes']) as $cleanupError) {
        $errors[] = $cleanupError;
    }
    if ($values['preview_initial'] && !$errors) {
        feedpublisher_admin_initial_preview($values);
        return;
    }
    $policyChanged = $currentFeed && !empty($currentFeed['initialized_at'])
        && ($values['initial_policy'] !== $currentFeed['initial_policy']
            || ($values['initial_policy'] === 'recent' && $values['initial_limit'] !== (int) $currentFeed['initial_limit']));
    $identityChanged = $currentFeed && $values['identity_strategy'] !== $currentFeed['identity_strategy'];
    if ($identityChanged && !$values['reset_identity_history']) {
        $errors[] = 'Confirm the queue/import-history reset before changing the duplicate identity strategy.';
    }
    if ($identityChanged && $values['reset_identity_history']) {
        $processing = (int) $db->fetch_field($db->simple_select('feedpublisher_queue', 'COUNT(id) AS total', "feed_id=" . $id . " AND state='processing'"), 'total');
        if ($processing > 0) {
            $errors[] = 'Wait for processing queue claims to finish before changing the duplicate identity strategy.';
        }
    }
    if ($policyChanged && $values['reset_initial_policy']) {
        $processing = (int) $db->fetch_field($db->simple_select('feedpublisher_queue', 'COUNT(id) AS total', "feed_id=" . $id . " AND state='processing'"), 'total');
        if ($processing > 0) {
            $errors[] = 'Wait for processing queue claims to finish before resetting the initial policy.';
        }
    }
    if ($policyChanged && !$values['reset_initial_policy']) {
        $errors[] = 'Confirm the initial-policy reset before changing a policy that has already been applied.';
    }
    if ($currentFeed) {
        $values['initialized_at'] = (int) $currentFeed['initialized_at'];
    }
    if ($id && !$currentFeed) {
        $errors[] = 'The selected feed does not exist.';
    }

    if ($errors) {
        feedpublisher_admin_form($id ? 'edit' : 'add', $values, $errors);
        return;
    }

    $record = array(
        'name' => $db->escape_string($values['name']),
        'url' => $db->escape_string($values['url']),
        'fid' => $values['fid'],
        'uid' => $values['uid'],
        'title_prefix' => $db->escape_string($values['title_prefix']),
        'thread_prefix_id' => $values['thread_prefix_id'],
        'thread_date_mode' => $db->escape_string($values['thread_date_mode']),
        'future_date_policy' => $db->escape_string($values['future_date_policy']),
        'schedule_jitter_minutes' => $values['schedule_jitter_minutes'],
        'identity_strategy' => $db->escape_string($values['identity_strategy']),
        'enabled' => $values['enabled'],
        'interval_minutes' => $values['interval_minutes'],
        'publish_interval_minutes' => $values['publish_interval_minutes'],
        'max_posts_per_run' => $values['max_posts_per_run'],
        'queue_order' => $db->escape_string($values['queue_order']),
        'publishing_paused' => $values['publishing_paused'],
        'initial_policy' => $db->escape_string($values['initial_policy']),
        'initial_limit' => $values['initial_limit'],
        'attribution_mode' => $db->escape_string($values['attribution_mode']),
        'initialized_at' => ($policyChanged && $values['reset_initial_policy']) ? 0 : (int) ($currentFeed['initialized_at'] ?? 0),
        'last_checked' => ($policyChanged && $values['reset_initial_policy']) ? 0 : (int) ($currentFeed['last_checked'] ?? 0),
        'strip_selectors' => $db->escape_string($values['strip_selectors']),
        'strip_regexes' => $db->escape_string($values['strip_regexes']),
        'remove_bylines' => $values['remove_bylines'],
        'remove_source_links' => $values['remove_source_links'],
    );
    if ($id) {
        $db->update_query('feedpublisher_feeds', $record, 'id=' . $id);
        if ($policyChanged && $values['reset_initial_policy']) {
            $db->delete_query('feedpublisher_queue', "feed_id=" . $id . " AND state IN ('queued','skipped','failed')");
        }
        if ($identityChanged && $values['reset_identity_history']) {
            $db->delete_query('feedpublisher_queue', 'feed_id=' . $id);
            $db->delete_query('feedpublisher_items', 'feed_id=' . $id);
        }
        flash_message('The feed was updated.', 'success');
    } else {
        $db->insert_query('feedpublisher_feeds', $record);
        flash_message('The feed was added.', 'success');
    }
    admin_redirect('index.php?module=config/feedpublisher');
}

function feedpublisher_admin_delete()
{
    global $db, $mybb, $page;

    $id = $mybb->get_input('id', MyBB::INPUT_INT);
    $feed = $db->fetch_array($db->simple_select('feedpublisher_feeds', 'id,name', 'id=' . $id, array('limit' => 1)));
    if (!$feed) {
        flash_message('The selected feed does not exist.', 'error');
        admin_redirect('index.php?module=config/feedpublisher');
    }

    if ($mybb->request_method === 'post') {
        verify_post_check($mybb->get_input('my_post_key'));
        if (!$mybb->get_input('confirm', MyBB::INPUT_INT)) {
            flash_message('Confirm deletion before continuing.', 'error');
            admin_redirect('index.php?module=config/feedpublisher&action=delete&id=' . $id);
        }
        $db->delete_query('feedpublisher_queue', 'feed_id=' . $id);
        $db->delete_query('feedpublisher_items', 'feed_id=' . $id);
        $db->delete_query('feedpublisher_feeds', 'id=' . $id);
        flash_message('The feed and its import history were deleted.', 'success');
        admin_redirect('index.php?module=config/feedpublisher');
    }

    $page->add_breadcrumb_item('Feed Publisher', 'index.php?module=config/feedpublisher');
    $page->output_header('Delete Feed Publisher feed');
    $form = new Form('index.php?module=config/feedpublisher&amp;action=delete&amp;id=' . $id, 'post');
    $container = new FormContainer('Delete feed');
    $container->output_row('Confirm deletion', 'Delete “' . htmlspecialchars_uni($feed['name']) . '” and all import history? This cannot be undone.', $form->generate_check_box('confirm', 1, 'I understand that this permanently deletes the feed history.'));
    $container->end();
    $form->output_submit_wrapper(array($form->generate_submit_button('Delete feed')));
    $form->end();
    $page->output_footer();
}

function feedpublisher_admin_url_is_valid($url)
{
    return strlen($url) <= 2048 && feedpublisher_validate_url($url);
}

function feedpublisher_admin_initial_preview($values)
{
    global $db, $page;

    try {
        $fetchMetadata = array();
        $parseMetadata = array();
        $xml = feedpublisher_fetch($values['url'], 2097152, $fetchMetadata);
        $items = feedpublisher_parse($xml, $fetchMetadata, $parseMetadata);
        $plan = feedpublisher_initial_stage_plan($values, $items);
    } catch (Throwable $exception) {
        feedpublisher_admin_form($values['id'] ? 'edit' : 'add', $values, array(
            'Preview failed: ' . htmlspecialchars_uni($exception->getMessage()),
        ));
        return;
    }

    $page->add_breadcrumb_item('Feed Publisher', 'index.php?module=config/feedpublisher');
    $page->add_breadcrumb_item('Initial import preview');
    $page->output_header('Feed Publisher initial import preview');
    feedpublisher_admin_tabs('feeds');

    echo '<style>'
        . '.fp-preview{margin:0 0 10px;border:1px solid #bbb;background:#fff}'
        . '.fp-preview summary{cursor:pointer;padding:12px;font-weight:bold}'
        . '.fp-preview-publish{border-left:5px solid #3b8f45;background:#f4fbf5}'
        . '.fp-preview-skip{border-left:5px solid #b94a48;background:#fff6f6}'
        . '.fp-preview[open]{border-width:2px;box-shadow:0 2px 8px rgba(0,0,0,.18)}'
        . '.fp-preview-publish[open]{border-color:#3b8f45}'
        . '.fp-preview-skip[open]{border-color:#b94a48}'
        . '.fp-preview-actions{margin:16px 0;padding:12px;border:1px solid #ccc;background:#f5f5f5}'
        . '.fp-preview-actions .button{display:inline-block;margin:0 8px 0 0;padding:7px 12px;text-decoration:none}'
        . '</style>';
    echo '<div style="margin:12px 0"><strong>Initial policy:</strong> ' . htmlspecialchars_uni($values['initial_policy'])
        . ' &middot; <strong>Maximum posts per run:</strong> ' . (int) $values['max_posts_per_run']
        . ' &middot; <strong>Previewed entries:</strong> ' . min(100, count($plan))
        . ' &middot; <strong>Feed format:</strong> ' . htmlspecialchars_uni($parseMetadata['format'])
        . ' &middot; <strong>Source encoding:</strong> ' . htmlspecialchars_uni($parseMetadata['encoding'])
        . (!empty($parseMetadata['content_type_fallback']) ? ' &middot; <strong>Content type:</strong> accepted after XML validation' : '')
        . '</div>';
    foreach (array_slice($plan, 0, 100) as $index => $entry) {
        $item = $entry['item'];
        $datePlan = isset($entry['date_plan']) ? $entry['date_plan'] : feedpublisher_source_date_plan($values, $item);
        $willPublish = $entry['state'] === 'queued';
        if ($entry['state'] === 'rejected') {
            $action = 'Reject permanently; do not publish';
        } elseif ($willPublish && $datePlan['available_at'] > TIME_NOW) {
            $action = 'Hold until scheduled time, then queue for paced publishing';
        } elseif ($willPublish) {
            $action = 'Queue for paced publishing';
        } else {
            $action = 'Mark as seen; do not publish';
        }
        $statusIcon = $willPublish ? '&#x1F7E2;' : '&#x1F534;';
        $panelClass = $willPublish ? 'fp-preview-publish' : 'fp-preview-skip';
        $identity = feedpublisher_derive_item_identity($values, $item);
        $itemKey = $identity['key'];
        $condition = 'feed_id=' . (int) $values['id'] . " AND item_key='" . $db->escape_string($itemKey) . "'";
        $imported = $values['id'] ? $db->fetch_array($db->simple_select('feedpublisher_items', 'tid,pid,imported_at', $condition, array('limit' => 1))) : null;
        $queued = $values['id'] ? $db->fetch_array($db->simple_select('feedpublisher_queue', 'state,tid,pid', $condition, array('limit' => 1))) : null;
        if ($imported && !empty($imported['tid'])) {
            $importState = 'Imported (thread ' . (int) $imported['tid'] . ', post ' . (int) $imported['pid'] . ')';
        } elseif ($queued) {
            $importState = 'Queue: ' . htmlspecialchars_uni($queued['state']);
        } elseif ($imported) {
            $importState = 'Reserved / uncertain';
        } else {
            $importState = 'New';
        }
        echo '<details class="fp-preview ' . $panelClass . '"' . ($index === 0 ? ' open' : '') . '>'
            . '<summary><span aria-hidden="true">' . $statusIcon . '</span> ' . htmlspecialchars_uni($action)
            . ' &mdash; ' . htmlspecialchars_uni($item['title']) . '</summary>'
            . '<div style="padding:0 12px 14px">'
            . '<table style="width:100%;border-collapse:collapse;margin-bottom:14px">'
            . '<tr><th style="width:170px;text-align:left;padding:6px;border-bottom:1px solid #ddd">Initial action</th><td style="padding:6px;border-bottom:1px solid #ddd">' . htmlspecialchars_uni($action) . '</td></tr>'
            . '<tr><th style="text-align:left;padding:6px;border-bottom:1px solid #ddd">Entry</th><td style="padding:6px;border-bottom:1px solid #ddd"><strong>' . htmlspecialchars_uni($item['title']) . '</strong><br><small>' . htmlspecialchars_uni($item['url']) . '</small></td></tr>'
            . '<tr><th style="text-align:left;padding:6px;border-bottom:1px solid #ddd">Duplicate identity</th><td style="padding:6px;border-bottom:1px solid #ddd">' . htmlspecialchars_uni($identity['basis']) . '<br><small>Key: ' . htmlspecialchars_uni($itemKey ?: 'unavailable') . ' &middot; Match: ' . htmlspecialchars_uni($importState === 'New' ? 'none found' : $importState) . '</small></td></tr>'
            . '<tr><th style="text-align:left;padding:6px;border-bottom:1px solid #ddd">Source time</th><td style="padding:6px;border-bottom:1px solid #ddd">'
            . ($datePlan['source_time'] ? my_date('normal', $datePlan['source_time']) : 'Missing or outside the supported 1980 to one-year-future range') . '</td></tr>'
            . '<tr><th style="text-align:left;padding:6px;border-bottom:1px solid #ddd">Effective queue time</th><td style="padding:6px;border-bottom:1px solid #ddd">'
            . ($entry['state'] === 'queued' ? my_date('normal', $datePlan['available_at']) : 'Not queued') . '</td></tr>'
            . '<tr><th style="text-align:left;padding:6px;border-bottom:1px solid #ddd">Intended thread time</th><td style="padding:6px;border-bottom:1px solid #ddd">'
            . ($entry['state'] === 'queued' ? my_date('normal', $datePlan['thread_time']) : 'No thread will be created') . '</td></tr>';
        try {
            $prepared = feedpublisher_prepare_item($values, $item);
            $removed = max(0, $prepared['raw_bytes'] - $prepared['cleaned_bytes']);
            $percent = $prepared['raw_bytes'] > 0 ? round(($removed / $prepared['raw_bytes']) * 100, 1) : 0;
            $previewItem = array('source_url' => $item['url'], 'title' => $item['title']);
            $exampleTitle = feedpublisher_build_subject($item['title'], isset($values['title_prefix']) ? $values['title_prefix'] : '');
            $previewUser = !empty($values['uid']) ? get_user((int) $values['uid']) : array();
            $availablePreviewPrefixes = feedpublisher_available_thread_prefixes((int) $values['fid'], $previewUser);
            $selectedPrefix = !empty($values['thread_prefix_id']) && isset($availablePreviewPrefixes[(int) $values['thread_prefix_id']])
                ? $availablePreviewPrefixes[(int) $values['thread_prefix_id']]
                : false;
            $nativePrefixLabel = $selectedPrefix ? feedpublisher_thread_prefix_label($selectedPrefix) : 'No MyBB prefix';
            $displayedExampleTitle = $selectedPrefix ? $nativePrefixLabel . ' ' . $exampleTitle : $exampleTitle;
            $exampleBody = feedpublisher_add_source_attribution($prepared['content'], $previewItem, $values['attribution_mode']);
            echo '<tr><th style="text-align:left;padding:6px;border-bottom:1px solid #ddd">HTML cleanup</th><td style="padding:6px;border-bottom:1px solid #ddd">Source: '
                . $prepared['raw_bytes'] . ' bytes &middot; Cleaned: ' . $prepared['cleaned_bytes']
                . ' bytes &middot; Removed: ' . $removed . ' bytes (' . $percent . '%)</td></tr>';
            echo '<tr><th style="text-align:left;padding:6px;border-bottom:1px solid #ddd">MyBB thread prefix</th><td style="padding:6px;border-bottom:1px solid #ddd">'
                . htmlspecialchars_uni($nativePrefixLabel) . '</td></tr>';
            if ($importState !== 'New') {
                echo '<tr><th style="text-align:left;padding:6px;border-bottom:1px solid #ddd">Existing state</th><td style="padding:6px;border-bottom:1px solid #ddd">' . $importState . '</td></tr>';
            }
            echo '</table><h3 style="margin-bottom:5px">Example title</h3>'
                . '<div style="padding:10px;border:1px solid #ccc;background:#f7f7f7">' . htmlspecialchars_uni($displayedExampleTitle) . '</div>'
                . '<h3 style="margin-bottom:5px">Example body</h3>'
                . '<pre style="white-space:pre-wrap;max-height:28em;overflow:auto;padding:10px;border:1px solid #ccc;background:#f7f7f7">'
                . htmlspecialchars_uni($exampleBody) . '</pre>';
        } catch (Throwable $exception) {
            echo '<tr><th style="text-align:left;padding:6px">Conversion</th><td style="padding:6px;color:#a00">Failed: '
                . htmlspecialchars_uni($exception->getMessage()) . '</td></tr></table>';
        }
        echo '</div></details>';
    }
    if (!$plan) {
        echo '<p>The feed contains no eligible entries.</p>';
    }
    echo '<p><strong>Dry run only:</strong> this preview did not create threads, posts, queue rows, imported-item records, or configuration changes.</p>';
    if (count($plan) > 100) {
        echo '<p>Showing the first 100 of ' . count($plan) . ' entries.</p>';
    }
    $listUrl = 'index.php?module=config/feedpublisher';
    $addUrl = $listUrl . '&amp;action=add';
    echo '<div class="fp-preview-actions">';
    if (!empty($values['preview_initial'])) {
        echo '<a class="button" href="' . $listUrl . '" onclick="history.back(); return false;">&larr; Return to feed form</a>';
    } else {
        echo '<a class="button" href="' . $listUrl . '&amp;action=edit&amp;id=' . (int) $values['id'] . '">Edit this feed</a>';
    }
    echo '<a class="button" href="' . $addUrl . '">+ Add feed</a>'
        . '<a class="button" href="' . $listUrl . '">View all feeds</a></div>';
    $page->output_footer();
}

function feedpublisher_admin_preview_saved()
{
    global $db, $mybb;

    $id = $mybb->get_input('id', MyBB::INPUT_INT);
    $feed = $db->fetch_array($db->simple_select('feedpublisher_feeds', '*', 'id=' . $id, array('limit' => 1)));
    if (!$feed) {
        flash_message('The selected feed does not exist.', 'error');
        admin_redirect('index.php?module=config/feedpublisher');
    }
    feedpublisher_admin_initial_preview($feed);
}

function feedpublisher_admin_operation_form($feedId, $operation, $label, $confirmation = '')
{
    global $mybb;

    echo '<form action="index.php?module=config/feedpublisher&amp;action=operation" method="post" style="margin:0">'
        . '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">'
        . '<input type="hidden" name="id" value="' . (int) $feedId . '">'
        . '<input type="hidden" name="operation" value="' . htmlspecialchars_uni($operation) . '">';
    if ($confirmation !== '') {
        echo '<label><input type="checkbox" name="confirm" value="1"> ' . htmlspecialchars_uni($confirmation) . '</label><br>';
    }
    echo '<input type="submit" class="button" value="' . htmlspecialchars_uni($label) . '"></form>';
}

function feedpublisher_admin_operations_page()
{
    global $db, $mybb, $page;

    $id = $mybb->get_input('id', MyBB::INPUT_INT);
    $feed = $db->fetch_array($db->simple_select('feedpublisher_feeds', '*', 'id=' . $id, array('limit' => 1)));
    if (!$feed) {
        flash_message('The selected feed does not exist.', 'error');
        admin_redirect('index.php?module=config/feedpublisher');
    }

    $counts = feedpublisher_queue_counts($id);
    $page->add_breadcrumb_item('Feed Publisher', 'index.php?module=config/feedpublisher');
    $page->add_breadcrumb_item('Operations');
    $page->output_header('Feed Publisher operations');
    feedpublisher_admin_tabs('feeds');
    echo '<p><strong>' . htmlspecialchars_uni($feed['name']) . '</strong><br><small>'
        . htmlspecialchars_uni($feed['url']) . '</small></p>';

    $table = new Table;
    $table->construct_header('Operation');
    $table->construct_header('Current state');
    $table->construct_header('Action');
    $table->construct_cell('<strong>Discover now</strong><br><small>Fetch this feed immediately and stage eligible entries.</small>');
    $table->construct_cell('Last checked: ' . ((int) $feed['last_checked'] ? my_date('relative', (int) $feed['last_checked']) : 'Never')
        . '<br>Fetch failures: ' . (int) $feed['fetch_failures']);
    ob_start();
    feedpublisher_admin_operation_form($id, 'discover', 'Discover now');
    $table->construct_cell(ob_get_clean());
    $table->construct_row();

    $table->construct_cell('<strong>Publish next batch</strong><br><small>Bypass the interval, but retain the feed pause and maximum-post limit.</small>');
    $table->construct_cell('Queued: ' . $counts['queued'] . '<br>Publishing: ' . (!empty($feed['publishing_paused']) ? 'Paused' : 'Active'));
    ob_start();
    feedpublisher_admin_operation_form($id, 'publish', 'Publish next batch');
    $table->construct_cell(ob_get_clean());
    $table->construct_row();

    $table->construct_cell('<strong>Retry failed</strong><br><small>Return at most 100 failed items to the queue.</small>');
    $table->construct_cell('Failed: ' . $counts['failed']);
    ob_start();
    feedpublisher_admin_operation_form($id, 'retry_failed', 'Retry failed items');
    $table->construct_cell(ob_get_clean());
    $table->construct_row();

    $table->construct_cell('<strong>Pause or resume</strong>');
    $table->construct_cell(!empty($feed['publishing_paused']) ? 'Paused' : 'Active');
    ob_start();
    feedpublisher_admin_operation_form($id, 'toggle_pause', !empty($feed['publishing_paused']) ? 'Resume publishing' : 'Pause publishing', 'Confirm the publishing-state change.');
    $table->construct_cell(ob_get_clean());
    $table->construct_row();

    $table->construct_cell('<strong>Reset fetch backoff</strong>');
    $table->construct_cell((int) $feed['next_fetch_at'] > TIME_NOW ? 'Next retry: ' . my_date('relative', (int) $feed['next_fetch_at']) : 'No active backoff');
    ob_start();
    feedpublisher_admin_operation_form($id, 'reset_backoff', 'Reset backoff', 'Confirm resetting the saved failure count and retry time.');
    $table->construct_cell(ob_get_clean());
    $table->construct_row();

    $table->construct_cell('<strong>Clear eligible queue</strong><br><small>Delete queued and failed rows only. Published, skipped, processing, uncertain, and rejected rows remain.</small>');
    $table->construct_cell('Eligible: ' . ($counts['queued'] + $counts['failed']));
    ob_start();
    feedpublisher_admin_operation_form($id, 'clear_queue', 'Clear eligible queue', 'Confirm permanent deletion of queued and failed entries.');
    $table->construct_cell(ob_get_clean());
    $table->construct_row();
    $table->output('Manual controls');

    $queueTable = new Table;
    $queueTable->construct_header('State');
    $queueTable->construct_header('Entry');
    $queueTable->construct_header('Attempts / error');
    $queueTable->construct_header('Resolution');
    $query = $db->simple_select(
        'feedpublisher_queue',
        '*',
        'feed_id=' . $id . " AND state IN ('failed','uncertain')",
        array('order_by' => 'last_attempt', 'order_dir' => 'DESC', 'limit' => 100)
    );
    while ($item = $db->fetch_array($query)) {
        $queueTable->construct_cell(htmlspecialchars_uni($item['state']));
        $queueTable->construct_cell('<strong>' . htmlspecialchars_uni($item['title']) . '</strong><br><small>' . htmlspecialchars_uni($item['source_url']) . '</small>');
        $queueTable->construct_cell((int) $item['attempts'] . '<br><small>' . htmlspecialchars_uni($item['last_error']) . '</small>');
        ob_start();
        if ($item['state'] === 'failed') {
            echo '<form action="index.php?module=config/feedpublisher&amp;action=operation" method="post">'
                . '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">'
                . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="queue_id" value="' . (int) $item['id'] . '">'
                . '<input type="hidden" name="operation" value="retry_item"><input type="submit" class="button" value="Retry this item"></form>';
        } else {
            echo '<form action="index.php?module=config/feedpublisher&amp;action=operation" method="post">'
                . '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">'
                . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="queue_id" value="' . (int) $item['id'] . '">'
                . '<input type="hidden" name="operation" value="resolve_uncertain">'
                . '<select name="resolution"><option value="link">Link existing post</option><option value="retry">No post exists; retry</option><option value="reject">Permanently reject</option></select><br>'
                . 'Thread ID: <input type="number" name="tid" min="0" style="width:80px"> '
                . 'Post ID: <input type="number" name="pid" min="0" style="width:80px"><br>'
                . '<label><input type="checkbox" name="confirm" value="1"> Confirm this resolution; before retrying, verify that no thread or post was created.</label><br>'
                . '<input type="submit" class="button" value="Resolve uncertain item"></form>';
        }
        $queueTable->construct_cell(ob_get_clean());
        $queueTable->construct_row();
    }
    if ($queueTable->num_rows() === 0) {
        $queueTable->construct_cell('No failed or uncertain entries require action.', array('colspan' => 4));
        $queueTable->construct_row();
    }
    $queueTable->output('Items requiring attention');
    echo '<p><a class="button" href="index.php?module=config/feedpublisher">View all feeds</a></p>';
    $page->output_footer();
}

function feedpublisher_admin_operation_commit()
{
    global $db, $mybb;

    if ($mybb->request_method !== 'post') {
        admin_redirect('index.php?module=config/feedpublisher');
    }
    verify_post_check($mybb->get_input('my_post_key'));
    $id = $mybb->get_input('id', MyBB::INPUT_INT);
    $operation = $mybb->get_input('operation');
    $feed = $db->fetch_array($db->simple_select('feedpublisher_feeds', '*', 'id=' . $id, array('limit' => 1)));
    if (!$feed) {
        flash_message('The selected feed does not exist.', 'error');
        admin_redirect('index.php?module=config/feedpublisher');
    }

    $confirmedOperations = array('toggle_pause', 'reset_backoff', 'clear_queue', 'resolve_uncertain');
    if (in_array($operation, $confirmedOperations, true) && !$mybb->get_input('confirm', MyBB::INPUT_INT)) {
        flash_message('Confirm the requested state-changing operation before continuing.', 'error');
        admin_redirect('index.php?module=config/feedpublisher&action=operations&id=' . $id);
    }

    try {
        if ($operation === 'discover') {
            $result = feedpublisher_discover_feed($feed);
            $message = 'Discovery completed: staged ' . $result['staged'] . ', skipped ' . $result['skipped']
                . ', rejected ' . $result['rejected'] . ', already known ' . $result['existing'] . ', queue-full ' . $result['full'] . '.';
        } elseif ($operation === 'publish') {
            if (!empty($feed['publishing_paused'])) {
                throw new RuntimeException('Resume this feed before manually publishing a batch.');
            }
            $result = feedpublisher_queue_dispatch($feed, 'feedpublisher_publish_queued_item', true);
            $message = 'Manual batch completed: published ' . $result['published'] . ', failed ' . $result['failed'] . '.';
        } elseif ($operation === 'retry_failed') {
            $count = feedpublisher_queue_retry_failed($id, 100);
            $message = 'Returned ' . $count . ' failed entries to the queue.';
        } elseif ($operation === 'retry_item') {
            $queueId = $mybb->get_input('queue_id', MyBB::INPUT_INT);
            $db->update_query('feedpublisher_queue', array(
                'state' => 'queued', 'attempts' => 0, 'available_at' => TIME_NOW, 'last_error' => '',
            ), 'id=' . $queueId . ' AND feed_id=' . $id . " AND state='failed'");
            if ($db->affected_rows() !== 1) {
                throw new RuntimeException('The failed queue entry no longer exists.');
            }
            $message = 'The failed entry was returned to the queue.';
        } elseif ($operation === 'toggle_pause') {
            $paused = empty($feed['publishing_paused']) ? 1 : 0;
            $db->update_query('feedpublisher_feeds', array('publishing_paused' => $paused), 'id=' . $id);
            $message = $paused ? 'Publishing was paused.' : 'Publishing was resumed.';
        } elseif ($operation === 'reset_backoff') {
            $db->update_query('feedpublisher_feeds', array(
                'fetch_failures' => 0, 'next_fetch_at' => 0, 'last_error' => '',
            ), 'id=' . $id);
            $message = 'Fetch backoff and the saved fetch error were reset.';
        } elseif ($operation === 'clear_queue') {
            $db->delete_query('feedpublisher_queue', 'feed_id=' . $id . " AND state IN ('queued','failed')");
            $message = 'Deleted ' . $db->affected_rows() . ' queued or failed entries.';
        } elseif ($operation === 'resolve_uncertain') {
            $queueId = $mybb->get_input('queue_id', MyBB::INPUT_INT);
            $resolution = $mybb->get_input('resolution');
            $result = feedpublisher_queue_resolve_uncertain(
                $feed,
                $queueId,
                $resolution,
                $mybb->get_input('tid', MyBB::INPUT_INT),
                $mybb->get_input('pid', MyBB::INPUT_INT)
            );
            $message = 'The uncertain entry was resolved as ' . $result . '.';
        } else {
            throw new RuntimeException('Select a valid Feed Publisher operation.');
        }
        log_admin_action('Feed Publisher', $operation, $id, $mybb->get_input('queue_id', MyBB::INPUT_INT));
        flash_message($message, 'success');
    } catch (Throwable $exception) {
        log_admin_action('Feed Publisher failed operation', $operation, $id, $mybb->get_input('queue_id', MyBB::INPUT_INT));
        flash_message(htmlspecialchars_uni(feedpublisher_safe_log_text($exception->getMessage())), 'error');
    }
    admin_redirect('index.php?module=config/feedpublisher&action=operations&id=' . $id);
}
