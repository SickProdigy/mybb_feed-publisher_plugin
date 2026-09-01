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

    if (empty($mybb->admin['permissions']['config']['feedpublisher'])) {
        $page->output_error('You do not have permission to manage Feed Publisher feeds.');
    }

    feedpublisher_upgrade_schema();
    require_once MYBB_ADMIN_DIR . 'inc/class_form.php';
    require_once MYBB_ADMIN_DIR . 'inc/class_table.php';
    require_once MYBB_ROOT . 'inc/plugins/feedpublisher/core.php';

    $action = $mybb->get_input('action');
    if ($action === 'save') {
        feedpublisher_admin_save();
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
    foreach (array('Name', 'Destination forum', 'Posting user', 'Interval', 'Status', 'Last result', 'Controls') as $heading) {
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
        $controls = '<a href="index.php?module=config/feedpublisher&amp;action=edit&amp;id=' . $id . '">Edit</a>'
            . ' &middot; <a href="index.php?module=config/feedpublisher&amp;action=delete&amp;id=' . $id . '">Delete</a>';
        $table->construct_cell('<strong>' . htmlspecialchars_uni($feed['name']) . '</strong><br><small>' . htmlspecialchars_uni($feed['url']) . '</small>');
        $table->construct_cell(htmlspecialchars_uni($feed['forum_name'] ?: 'Missing forum'));
        $table->construct_cell(htmlspecialchars_uni($feed['username'] ?: 'Missing user'));
        $table->construct_cell((int) $feed['interval_minutes'] . ' minutes');
        $table->construct_cell($feed['enabled'] ? 'Enabled' : 'Disabled');
        $table->construct_cell($lastResult);
        $table->construct_cell($controls);
        $table->construct_row();
    }

    if ($table->num_rows() === 0) {
        $table->construct_cell('No feeds have been configured.', array('colspan' => 7));
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
        'strip_selectors' => '',
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
    $container->output_row('Import interval <em>*</em>', 'Minutes between checks (minimum 5, maximum 10080).', $form->generate_numeric_field('interval_minutes', (int) $values['interval_minutes'], array('min' => 5, 'max' => 10080)));
    $container->output_row('Cleanup selectors', 'Optional CSS selectors, one per line. Issue #5 will make these rules operational.', $form->generate_text_area('strip_selectors', $values['strip_selectors']));
    $container->output_row('Enabled', 'Only enabled feeds are inspected by the scheduled task.', $form->generate_check_box('enabled', 1, 'Enable this feed', array('checked' => !empty($values['enabled']))));
    $container->end();
    $form->output_submit_wrapper(array($form->generate_submit_button($action === 'edit' ? 'Save feed' : 'Add feed')));
    $form->end();
    $page->output_footer();
}

function feedpublisher_admin_save()
{
    global $db, $mybb;

    verify_post_check($mybb->get_input('my_post_key'));
    $id = $mybb->get_input('id', MyBB::INPUT_INT);
    $values = array(
        'id' => $id,
        'name' => trim($mybb->get_input('name')),
        'url' => trim($mybb->get_input('url')),
        'fid' => $mybb->get_input('fid', MyBB::INPUT_INT),
        'uid' => $mybb->get_input('uid', MyBB::INPUT_INT),
        'interval_minutes' => $mybb->get_input('interval_minutes', MyBB::INPUT_INT),
        'strip_selectors' => trim($mybb->get_input('strip_selectors')),
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
    if ($id && !$db->fetch_field($db->simple_select('feedpublisher_feeds', 'id', 'id=' . $id, array('limit' => 1)), 'id')) {
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
        'strip_selectors' => $db->escape_string($values['strip_selectors']),
    );
    if ($id) {
        $db->update_query('feedpublisher_feeds', $record, 'id=' . $id);
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
