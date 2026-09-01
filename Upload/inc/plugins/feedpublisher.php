<?php
/**
 * MyBB Feed Publisher
 * Copyright (c) 2026 SickProdigy
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!defined('IN_MYBB')) {
    die('Direct access is not allowed.');
}

$plugins->add_hook('admin_config_action_handler', 'feedpublisher_admin_action_handler');
$plugins->add_hook('admin_config_menu', 'feedpublisher_admin_menu');
$plugins->add_hook('admin_config_permissions', 'feedpublisher_admin_permissions');
$plugins->add_hook('admin_load', 'feedpublisher_admin_load');

function feedpublisher_info()
{
    return array(
        'name' => 'Feed Publisher',
        'description' => 'Imports RSS and Atom entries as safe MyBB posts.',
        'website' => '',
        'author' => 'SickProdigy',
        'authorsite' => '',
        'version' => '0.1.1',
        'compatibility' => '18*',
        'codename' => 'feedpublisher',
    );
}

function feedpublisher_is_installed()
{
    global $db;

    return $db->table_exists('feedpublisher_feeds')
        && $db->table_exists('feedpublisher_items');
}

function feedpublisher_install()
{
    global $db;

    $collation = $db->build_create_table_collation();

    if (!$db->table_exists('feedpublisher_feeds')) {
        $db->write_query("CREATE TABLE `" . TABLE_PREFIX . "feedpublisher_feeds` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(150) NOT NULL DEFAULT '',
            `url` varchar(2048) NOT NULL,
            `fid` int unsigned NOT NULL,
            `uid` int unsigned NOT NULL,
            `enabled` tinyint(1) NOT NULL DEFAULT 0,
            `interval_minutes` smallint unsigned NOT NULL DEFAULT 60,
            `strip_selectors` text NULL,
            `last_checked` int unsigned NOT NULL DEFAULT 0,
            `last_error` text NULL,
            PRIMARY KEY (`id`),
            KEY `enabled` (`enabled`)
        ) ENGINE=MyISAM{$collation}");
    }

    feedpublisher_upgrade_schema();

    if (!$db->table_exists('feedpublisher_items')) {
        $db->write_query("CREATE TABLE `" . TABLE_PREFIX . "feedpublisher_items` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `feed_id` int unsigned NOT NULL,
            `item_key` char(64) NOT NULL,
            `source_url` varchar(2048) NOT NULL DEFAULT '',
            `tid` int unsigned NOT NULL DEFAULT 0,
            `pid` int unsigned NOT NULL DEFAULT 0,
            `imported_at` int unsigned NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `feed_item` (`feed_id`, `item_key`)
        ) ENGINE=MyISAM{$collation}");
    }

    feedpublisher_install_task();
}

function feedpublisher_upgrade_schema()
{
    global $db;

    if (!$db->table_exists('feedpublisher_feeds')) {
        return;
    }

    if (!$db->field_exists('interval_minutes', 'feedpublisher_feeds')) {
        $db->add_column('feedpublisher_feeds', 'interval_minutes', "smallint unsigned NOT NULL DEFAULT 60 AFTER enabled");
    }
}

function feedpublisher_uninstall()
{
    global $db;

    $db->drop_table('feedpublisher_items');
    $db->drop_table('feedpublisher_feeds');
    $db->delete_query('tasks', "file='feedpublisher'");
}

function feedpublisher_activate()
{
    feedpublisher_upgrade_schema();
    feedpublisher_install_task();
}

function feedpublisher_deactivate()
{
}

function feedpublisher_install_task()
{
    global $db;

    if ($db->fetch_field($db->simple_select('tasks', 'tid', "file='feedpublisher'", array('limit' => 1)), 'tid')) {
        return;
    }

    $db->insert_query('tasks', array(
        'title' => 'Feed Publisher imports',
        'description' => 'Fetches enabled RSS and Atom feeds and publishes new entries.',
        'file' => 'feedpublisher',
        'minute' => '0,5,10,15,20,25,30,35,40,45,50,55',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'weekday' => '*',
        'enabled' => 0,
        'logging' => 1,
    ));
}

function feedpublisher_admin_action_handler(&$actions)
{
    $actions['feedpublisher'] = array('active' => 'feedpublisher', 'file' => 'feedpublisher');
}

function feedpublisher_admin_menu(&$sub_menu)
{
    $sub_menu[] = array(
        'id' => 'feedpublisher',
        'title' => 'Feed Publisher',
        'link' => 'index.php?module=config/feedpublisher',
    );
}

function feedpublisher_admin_permissions(&$admin_permissions)
{
    $admin_permissions['feedpublisher'] = 'Can manage Feed Publisher feeds?';
}

function feedpublisher_admin_load()
{
    global $page;

    if ($page->active_action !== 'feedpublisher') {
        return;
    }

    require_once MYBB_ROOT . 'inc/plugins/feedpublisher/admin.php';
    feedpublisher_admin_controller();
    exit;
}
