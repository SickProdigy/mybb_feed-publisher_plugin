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

    $action = $mybb->get_input('action');
    if ($action === 'save') {
        feedpublisher_admin_save();
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
            . '<br>Failed: ' . $counts['failed'] . '<br>Uncertain: ' . $counts['uncertain'];
        $initialStatus = empty($feed['initialized_at'])
            ? 'Initial scan pending (' . htmlspecialchars_uni($feed['initial_policy']) . ')'
            : 'Initial scan: ' . my_date('relative', (int) $feed['initialized_at']) . ' (' . htmlspecialchars_uni($feed['initial_policy']) . ')';
        $controls = '<a href="index.php?module=config/feedpublisher&amp;action=edit&amp;id=' . $id . '">Edit</a>'
            . ' &middot; <a href="index.php?module=config/feedpublisher&amp;action=preview&amp;id=' . $id . '">Preview</a>'
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

    $values = array_merge(array(
        'id' => $id,
        'name' => '',
        'url' => '',
        'fid' => 0,
        'uid' => 0,
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

    $form = new Form('index.php?module=config/feedpublisher&amp;action=save', 'post');
    echo $form->generate_hidden_field('id', (int) $values['id']);
    $container = new FormContainer($action === 'edit' ? 'Edit feed' : 'Add feed');
    $container->output_row('Name <em>*</em>', 'A descriptive name shown in the Admin CP and task logs.', $form->generate_text_box('name', $values['name']));
    $container->output_row('Feed URL <em>*</em>', 'The public HTTP or HTTPS RSS/Atom URL.', $form->generate_text_box('url', $values['url']));
    $container->output_row('Destination forum <em>*</em>', 'New entries will be published to this forum.', $form->generate_select_box('fid', $forums, (int) $values['fid']));
    $container->output_row('Posting user <em>*</em>', 'The MyBB account used as the post author.', $form->generate_select_box('uid', $users, (int) $values['uid']));
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
    $container->output_row('Publishing paused', 'Discovery continues while queued publication is paused.', $form->generate_check_box('publishing_paused', 1, 'Pause publishing for this feed', array('checked' => !empty($values['publishing_paused']))));
    $container->output_row('Common cleanup', 'Remove recognized author/byline blocks and trailing source/read-more backlink blocks before conversion.', $form->generate_check_box('remove_bylines', 1, 'Remove common author and byline blocks', array('checked' => !empty($values['remove_bylines']))) . '<br>' . $form->generate_check_box('remove_source_links', 1, 'Remove common source and read-more backlink blocks', array('checked' => !empty($values['remove_source_links']))));
    $container->output_row('Cleanup selectors', 'Optional simple CSS selectors, one per line: tag, .class, #id, tag.class, [attribute], or tag[attribute].', $form->generate_text_area('strip_selectors', $values['strip_selectors']));
    $container->output_row('Cleanup regular expressions', 'Optional PHP-compatible regular expressions, one per line. Matching text is removed; rules are validated before saving.', $form->generate_text_area('strip_regexes', $values['strip_regexes']));
    $container->output_row('Enabled', 'Only enabled feeds are inspected by the scheduled task.', $form->generate_check_box('enabled', 1, 'Enable this feed', array('checked' => !empty($values['enabled']))));
    $container->end();
    $form->output_submit_wrapper(array(
        $form->generate_submit_button($action === 'edit' ? 'Save feed' : 'Add feed', array('name' => 'save_feed')),
        $form->generate_submit_button('Preview / dry run', array('name' => 'preview_initial'))
    ));
    $form->end();
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
        'strip_selectors' => trim($mybb->get_input('strip_selectors')),
        'strip_regexes' => trim($mybb->get_input('strip_regexes')),
        'remove_bylines' => $mybb->get_input('remove_bylines', MyBB::INPUT_INT) ? 1 : 0,
        'remove_source_links' => $mybb->get_input('remove_source_links', MyBB::INPUT_INT) ? 1 : 0,
        'enabled' => $mybb->get_input('enabled', MyBB::INPUT_INT) ? 1 : 0,
    );
    $errors = array();

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
        $items = feedpublisher_parse(feedpublisher_fetch($values['url']));
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

    $table = new Table;
    $table->construct_header('Entry');
    $table->construct_header('Published');
    $table->construct_header('Initial action');
    $table->construct_header('Import state');
    $table->construct_header('Cleanup');
    $table->construct_header('Cleaned result');
    foreach (array_slice($plan, 0, 100) as $entry) {
        $item = $entry['item'];
        $table->construct_cell('<strong>' . htmlspecialchars_uni($item['title']) . '</strong><br><small>' . htmlspecialchars_uni($item['url']) . '</small>');
        $table->construct_cell($item['published'] ? my_date('relative', (int) $item['published']) : 'Unknown');
        $table->construct_cell($entry['state'] === 'queued' ? 'Queue for paced publishing' : 'Mark as seen; do not publish');
        $itemKey = feedpublisher_item_key($item['key']);
        $condition = 'feed_id=' . (int) $values['id'] . " AND item_key='" . $db->escape_string($itemKey) . "'";
        $imported = $values['id'] ? $db->fetch_array($db->simple_select('feedpublisher_items', 'tid,pid,imported_at', $condition, array('limit' => 1))) : null;
        $queued = $values['id'] ? $db->fetch_array($db->simple_select('feedpublisher_queue', 'state,tid,pid', $condition, array('limit' => 1))) : null;
        if ($imported && !empty($imported['tid'])) {
            $importState = 'Imported (thread ' . (int) $imported['tid'] . ', post ' . (int) $imported['pid'] . ')';
        } elseif ($imported) {
            $importState = 'Reserved / uncertain';
        } elseif ($queued) {
            $importState = 'Queue: ' . htmlspecialchars_uni($queued['state']);
        } else {
            $importState = 'New';
        }
        $table->construct_cell($importState);
        try {
            $prepared = feedpublisher_prepare_item($values, $item);
            $removed = max(0, $prepared['raw_bytes'] - $prepared['cleaned_bytes']);
            $table->construct_cell('Source HTML: ' . $prepared['raw_bytes'] . ' bytes<br>Cleaned HTML: ' . $prepared['cleaned_bytes'] . ' bytes<br>Removed: ' . $removed . ' bytes');
            $table->construct_cell('<pre style="white-space:pre-wrap;max-height:14em;overflow:auto">' . htmlspecialchars_uni(my_substr($prepared['content'], 0, 2000)) . '</pre>');
        } catch (Throwable $exception) {
            $table->construct_cell('Conversion failed');
            $table->construct_cell('<span style="color:#a00">' . htmlspecialchars_uni($exception->getMessage()) . '</span>');
        }
        $table->construct_row();
    }
    if (!$plan) {
        $table->construct_cell('The feed contains no eligible entries.', array('colspan' => 6));
        $table->construct_row();
    }
    $table->output('Preview: ' . htmlspecialchars_uni($values['initial_policy']));
    echo '<p><strong>Dry run only:</strong> this preview did not create threads, posts, queue rows, imported-item records, or configuration changes.</p>';
    if (count($plan) > 100) {
        echo '<p>Showing the first 100 of ' . count($plan) . ' entries.</p>';
    }
    $listUrl = 'index.php?module=config/feedpublisher';
    if (!empty($values['preview_initial'])) {
        echo '<p><a class="button" href="' . $listUrl . '" onclick="history.back(); return false;">Return to form</a> ';
    } else {
        echo '<p><a class="button" href="' . $listUrl . '&amp;action=edit&amp;id=' . (int) $values['id'] . '">Edit feed</a> ';
    }
    echo '<a class="button" href="' . $listUrl . '">Back to feed list</a></p>';
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
