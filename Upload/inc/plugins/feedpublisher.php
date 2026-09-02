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
        'version' => '0.1.27',
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
            `title_prefix` varchar(40) NOT NULL DEFAULT '',
            `thread_prefix_id` int unsigned NOT NULL DEFAULT 0,
            `thread_date_mode` varchar(16) NOT NULL DEFAULT 'publish',
            `future_date_policy` varchar(16) NOT NULL DEFAULT 'hold',
            `schedule_jitter_minutes` tinyint unsigned NOT NULL DEFAULT 0,
            `identity_strategy` varchar(24) NOT NULL DEFAULT 'guid_link',
            `terminal_retention_days` smallint unsigned NOT NULL DEFAULT 90,
            `terminal_retention_count` int unsigned NOT NULL DEFAULT 1000,
            `dedupe_retention_days` smallint unsigned NOT NULL DEFAULT 0,
            `strict_reconciliation` tinyint(1) NOT NULL DEFAULT 0,
            `last_feed_item_count` int unsigned NOT NULL DEFAULT 0,
            `eligibility_rules` text NULL,
            `minimum_source_age_hours` smallint unsigned NOT NULL DEFAULT 0,
            `maximum_source_age_days` smallint unsigned NOT NULL DEFAULT 0,
            `require_entry_body` tinyint(1) NOT NULL DEFAULT 0,
            `require_entry_media` tinyint(1) NOT NULL DEFAULT 0,
            `media_mode` varchar(16) NOT NULL DEFAULT 'ignore',
            `publication_mode` varchar(16) NOT NULL DEFAULT 'automatic',
            `fulltext_mode` varchar(16) NOT NULL DEFAULT 'disabled',
            `fulltext_fallback` varchar(12) NOT NULL DEFAULT 'feed',
            `fulltext_summary_chars` smallint unsigned NOT NULL DEFAULT 600,
            `fulltext_max_per_run` tinyint unsigned NOT NULL DEFAULT 3,
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
            `post_header` text NULL,
            `post_footer` text NULL,
            `body_length_limit` int unsigned NOT NULL DEFAULT 0,
            `continuation_mode` varchar(16) NOT NULL DEFAULT 'none',
            `continuation_text` varchar(100) NOT NULL DEFAULT 'Continue reading',
            `remove_bylines` tinyint(1) NOT NULL DEFAULT 0,
            `remove_source_links` tinyint(1) NOT NULL DEFAULT 0,
            `strip_selectors` text NULL,
            `strip_regexes` text NULL,
            `last_checked` int unsigned NOT NULL DEFAULT 0,
            `last_success_at` int unsigned NOT NULL DEFAULT 0,
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
            `disposition` varchar(16) NOT NULL DEFAULT 'published',
            `tid` int unsigned NOT NULL DEFAULT 0,
            `pid` int unsigned NOT NULL DEFAULT 0,
            `imported_at` int unsigned NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `feed_item` (`feed_id`, `item_key`)
        ) ENGINE=MyISAM{$collation}");
    }

    feedpublisher_install_logs_table($collation);

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
        'title_prefix' => "varchar(40) NOT NULL DEFAULT '' AFTER `uid`",
        'thread_prefix_id' => "int unsigned NOT NULL DEFAULT 0 AFTER `title_prefix`",
        'thread_date_mode' => "varchar(16) NOT NULL DEFAULT 'publish' AFTER `thread_prefix_id`",
        'future_date_policy' => "varchar(16) NOT NULL DEFAULT 'hold' AFTER `thread_date_mode`",
        'schedule_jitter_minutes' => "tinyint unsigned NOT NULL DEFAULT 0 AFTER `future_date_policy`",
        'identity_strategy' => "varchar(24) NOT NULL DEFAULT 'guid_link' AFTER `schedule_jitter_minutes`",
        'terminal_retention_days' => "smallint unsigned NOT NULL DEFAULT 90 AFTER `identity_strategy`",
        'terminal_retention_count' => "int unsigned NOT NULL DEFAULT 1000 AFTER `terminal_retention_days`",
        'dedupe_retention_days' => "smallint unsigned NOT NULL DEFAULT 0 AFTER `terminal_retention_count`",
        'strict_reconciliation' => "tinyint(1) NOT NULL DEFAULT 0 AFTER `dedupe_retention_days`",
        'last_feed_item_count' => "int unsigned NOT NULL DEFAULT 0 AFTER `strict_reconciliation`",
        'eligibility_rules' => "text NULL AFTER `last_feed_item_count`",
        'minimum_source_age_hours' => "smallint unsigned NOT NULL DEFAULT 0 AFTER `eligibility_rules`",
        'maximum_source_age_days' => "smallint unsigned NOT NULL DEFAULT 0 AFTER `minimum_source_age_hours`",
        'require_entry_body' => "tinyint(1) NOT NULL DEFAULT 0 AFTER `maximum_source_age_days`",
        'require_entry_media' => "tinyint(1) NOT NULL DEFAULT 0 AFTER `require_entry_body`",
        'media_mode' => "varchar(16) NOT NULL DEFAULT 'ignore' AFTER `require_entry_media`",
        'publication_mode' => "varchar(16) NOT NULL DEFAULT 'automatic' AFTER `media_mode`",
        'fulltext_mode' => "varchar(16) NOT NULL DEFAULT 'disabled' AFTER `publication_mode`",
        'fulltext_fallback' => "varchar(12) NOT NULL DEFAULT 'feed' AFTER `fulltext_mode`",
        'fulltext_summary_chars' => "smallint unsigned NOT NULL DEFAULT 600 AFTER `fulltext_fallback`",
        'fulltext_max_per_run' => "tinyint unsigned NOT NULL DEFAULT 3 AFTER `fulltext_summary_chars`",
        'interval_minutes' => "smallint unsigned NOT NULL DEFAULT 60 AFTER `enabled`",
        'publish_interval_minutes' => "smallint unsigned NOT NULL DEFAULT 60 AFTER `interval_minutes`",
        'max_posts_per_run' => "smallint unsigned NOT NULL DEFAULT 1 AFTER `publish_interval_minutes`",
        'queue_order' => "varchar(10) NOT NULL DEFAULT 'oldest' AFTER `max_posts_per_run`",
        'publishing_paused' => "tinyint(1) NOT NULL DEFAULT 0 AFTER `queue_order`",
        'initial_policy' => "varchar(16) NOT NULL DEFAULT 'latest' AFTER publishing_paused",
        'initial_limit' => "smallint unsigned NOT NULL DEFAULT 1 AFTER initial_policy",
        'initialized_at' => "int unsigned NOT NULL DEFAULT 0 AFTER initial_limit",
        'attribution_mode' => "varchar(16) NOT NULL DEFAULT 'link' AFTER initialized_at",
        'post_header' => "text NULL AFTER attribution_mode",
        'post_footer' => "text NULL AFTER post_header",
        'body_length_limit' => "int unsigned NOT NULL DEFAULT 0 AFTER post_footer",
        'continuation_mode' => "varchar(16) NOT NULL DEFAULT 'none' AFTER body_length_limit",
        'continuation_text' => "varchar(100) NOT NULL DEFAULT 'Continue reading' AFTER continuation_mode",
        'remove_bylines' => "tinyint(1) NOT NULL DEFAULT 0 AFTER attribution_mode",
        'remove_source_links' => "tinyint(1) NOT NULL DEFAULT 0 AFTER remove_bylines",
        'strip_regexes' => "text NULL AFTER strip_selectors",
        'fetch_failures' => "tinyint unsigned NOT NULL DEFAULT 0 AFTER last_checked",
        'last_success_at' => "int unsigned NOT NULL DEFAULT 0 AFTER last_checked",
        'next_fetch_at' => "int unsigned NOT NULL DEFAULT 0 AFTER fetch_failures",
        'last_published' => "int unsigned NOT NULL DEFAULT 0 AFTER `last_checked`",
    );
    foreach ($columns as $name => $definition) {
        if (!$db->field_exists($name, 'feedpublisher_feeds')) {
            $db->add_column('feedpublisher_feeds', $name, $definition);
        }
    }
    if ($db->table_exists('feedpublisher_items') && !$db->field_exists('disposition', 'feedpublisher_items')) {
        $db->add_column('feedpublisher_items', 'disposition', "varchar(16) NOT NULL DEFAULT 'published' AFTER `source_url`");
    }
    if ($db->table_exists('feedpublisher_queue') && !$db->field_exists('author', 'feedpublisher_queue')) {
        $db->add_column('feedpublisher_queue', 'author', "varchar(255) NOT NULL DEFAULT '' AFTER source_url");
    }
    if ($db->table_exists('feedpublisher_queue') && !$db->field_exists('media_json', 'feedpublisher_queue')) {
        $db->add_column('feedpublisher_queue', 'media_json', "text NULL AFTER author");
    }

    if (!$db->table_exists('feedpublisher_queue')) {
        $collation = $db->build_create_table_collation();
        $db->write_query("CREATE TABLE `" . TABLE_PREFIX . "feedpublisher_queue` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `feed_id` int unsigned NOT NULL,
            `item_key` char(64) NOT NULL,
            `title` varchar(255) NOT NULL DEFAULT '',
            `source_url` varchar(2048) NOT NULL DEFAULT '',
            `author` varchar(255) NOT NULL DEFAULT '',
            `media_json` text NULL,
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
    feedpublisher_install_logs_table($db->build_create_table_collation());
}

function feedpublisher_install_logs_table($collation)
{
    global $db;
    if (!$db->table_exists('feedpublisher_logs')) {
        $db->write_query("CREATE TABLE `" . TABLE_PREFIX . "feedpublisher_logs` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `feed_id` int unsigned NOT NULL DEFAULT 0,
            `created_at` int unsigned NOT NULL,
            `stage` varchar(24) NOT NULL DEFAULT 'general',
            `severity` varchar(12) NOT NULL DEFAULT 'info',
            `message` varchar(1000) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            KEY `feed_time` (`feed_id`, `created_at`),
            KEY `stage_severity` (`stage`, `severity`)
        ) ENGINE=MyISAM{$collation}");
    }
}

function feedpublisher_uninstall()
{
    global $db;

    $db->drop_table('feedpublisher_queue');
    $db->drop_table('feedpublisher_logs');
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
