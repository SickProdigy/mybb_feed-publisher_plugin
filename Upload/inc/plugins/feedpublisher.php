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
        'website' => 'https://sickgaming.net',
        'author' => 'SickProdigy',
        'authorsite' => 'https://sickgaming.net',
        'version' => '0.1.11',
        'compatibility' => '18*',
        'codename' => 'feedpublisher',
    );
}

function feedpublisher_is_installed()
{
    global $db;

    return $db->table_exists('feedpublisher_feeds')
        && $db->table_exists('feedpublisher_items')
        && $db->table_exists('feedpublisher_queue');
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
            `publish_interval_minutes` smallint unsigned NOT NULL DEFAULT 60,
            `max_posts_per_run` smallint unsigned NOT NULL DEFAULT 1,
            `queue_order` varchar(10) NOT NULL DEFAULT 'oldest',
            `publishing_paused` tinyint(1) NOT NULL DEFAULT 0,
            `initial_policy` varchar(16) NOT NULL DEFAULT 'latest',
            `initial_limit` smallint unsigned NOT NULL DEFAULT 1,
            `initialized_at` int unsigned NOT NULL DEFAULT 0,
            `attribution_mode` varchar(16) NOT NULL DEFAULT 'link',
            `remove_bylines` tinyint(1) NOT NULL DEFAULT 0,
            `remove_source_links` tinyint(1) NOT NULL DEFAULT 0,
            `strip_selectors` text NULL,
            `strip_regexes` text NULL,
            `last_checked` int unsigned NOT NULL DEFAULT 0,
            `fetch_failures` tinyint unsigned NOT NULL DEFAULT 0,
            `next_fetch_at` int unsigned NOT NULL DEFAULT 0,
            `last_published` int unsigned NOT NULL DEFAULT 0,
            `last_error` text NULL,
            PRIMARY KEY (`id`),
            KEY `enabled` (`enabled`)
        ) ENGINE=MyISAM{$collation}");
    }

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

    feedpublisher_upgrade_schema();
    feedpublisher_install_task();
}

function feedpublisher_upgrade_schema()
{
    global $db;

    if (!$db->table_exists('feedpublisher_feeds')) {
        return;
    }

    $columns = array(
        'interval_minutes' => "smallint unsigned NOT NULL DEFAULT 60 AFTER `enabled`",
        'publish_interval_minutes' => "smallint unsigned NOT NULL DEFAULT 60 AFTER `interval_minutes`",
        'max_posts_per_run' => "smallint unsigned NOT NULL DEFAULT 1 AFTER `publish_interval_minutes`",
        'queue_order' => "varchar(10) NOT NULL DEFAULT 'oldest' AFTER `max_posts_per_run`",
        'publishing_paused' => "tinyint(1) NOT NULL DEFAULT 0 AFTER `queue_order`",
        'initial_policy' => "varchar(16) NOT NULL DEFAULT 'latest' AFTER publishing_paused",
        'initial_limit' => "smallint unsigned NOT NULL DEFAULT 1 AFTER initial_policy",
        'initialized_at' => "int unsigned NOT NULL DEFAULT 0 AFTER initial_limit",
        'attribution_mode' => "varchar(16) NOT NULL DEFAULT 'link' AFTER initialized_at",
        'remove_bylines' => "tinyint(1) NOT NULL DEFAULT 0 AFTER attribution_mode",
        'remove_source_links' => "tinyint(1) NOT NULL DEFAULT 0 AFTER remove_bylines",
        'strip_regexes' => "text NULL AFTER strip_selectors",
        'fetch_failures' => "tinyint unsigned NOT NULL DEFAULT 0 AFTER last_checked",
        'next_fetch_at' => "int unsigned NOT NULL DEFAULT 0 AFTER fetch_failures",
        'last_published' => "int unsigned NOT NULL DEFAULT 0 AFTER `last_checked`",
    );
    foreach ($columns as $name => $definition) {
        if (!$db->field_exists($name, 'feedpublisher_feeds')) {
            $db->add_column('feedpublisher_feeds', $name, $definition);
        }
    }

    if (!$db->table_exists('feedpublisher_queue')) {
        $collation = $db->build_create_table_collation();
        $db->write_query("CREATE TABLE `" . TABLE_PREFIX . "feedpublisher_queue` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `feed_id` int unsigned NOT NULL,
            `item_key` char(64) NOT NULL,
            `title` varchar(255) NOT NULL DEFAULT '',
            `source_url` varchar(2048) NOT NULL DEFAULT '',
            `raw_content` mediumtext NOT NULL,
            `content` mediumtext NOT NULL,
            `source_published` int unsigned NOT NULL DEFAULT 0,
            `discovered_at` int unsigned NOT NULL,
            `available_at` int unsigned NOT NULL DEFAULT 0,
            `state` varchar(16) NOT NULL DEFAULT 'queued',
            `attempts` smallint unsigned NOT NULL DEFAULT 0,
            `last_attempt` int unsigned NOT NULL DEFAULT 0,
            `last_error` text NULL,
            `claim_token` char(64) NOT NULL DEFAULT '',
            `claimed_at` int unsigned NOT NULL DEFAULT 0,
            `tid` int unsigned NOT NULL DEFAULT 0,
            `pid` int unsigned NOT NULL DEFAULT 0,
            `published_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `feed_item` (`feed_id`, `item_key`),
            KEY `feed_state` (`feed_id`, `state`),
            KEY `state_available` (`state`, `available_at`)
        ) ENGINE=MyISAM{$collation}");
    }
}

function feedpublisher_uninstall()
{
    global $db;

    $db->drop_table('feedpublisher_queue');
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
        'description' => 'Discovers feed entries and publishes queued items at controlled intervals.',
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
