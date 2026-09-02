<?php
/**
 * Bounded OPML and configuration portability tools.
 * Copyright (c) 2026 SickProdigy
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!defined('IN_MYBB') || !defined('IN_ADMINCP')) {
    die('Direct access is not allowed.');
}

function feedpublisher_portability_config_fields()
{
    return array('name','url','title_prefix','thread_date_mode','future_date_policy','schedule_jitter_minutes',
        'identity_strategy','terminal_retention_days','terminal_retention_count','dedupe_retention_days',
        'strict_reconciliation','eligibility_rules','minimum_source_age_hours','maximum_source_age_days',
        'require_entry_body','require_entry_media','media_mode','publication_mode','enabled','interval_minutes',
        'fulltext_mode','fulltext_fallback','fulltext_summary_chars','fulltext_max_per_run',
        'publish_interval_minutes','max_posts_per_run','queue_order','publishing_paused','initial_policy',
        'initial_limit','attribution_mode','post_header','post_footer','body_length_limit','continuation_mode',
        'continuation_text','remove_bylines','remove_source_links','strip_selectors','strip_regexes');
}

function feedpublisher_portability_url_valid($url)
{
    $parts = parse_url(trim((string) $url));
    if (!$parts || empty($parts['host']) || empty($parts['scheme'])) return false;
    if (!in_array(strtolower($parts['scheme']), array('http','https'), true) || isset($parts['user']) || isset($parts['pass']) || strlen($url) > 2048) return false;
    $host = trim($parts['host'], '[]');
    return !filter_var($host, FILTER_VALIDATE_IP)
        || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function feedpublisher_portability_settings_supported($entry)
{
    $errors = array();
    feedpublisher_eligibility_rules(isset($entry['eligibility_rules']) ? $entry['eligibility_rules'] : '', $errors);
    $errors = array_merge($errors, feedpublisher_template_errors(isset($entry['post_header']) ? $entry['post_header'] : '', isset($entry['post_footer']) ? $entry['post_footer'] : ''));
    $errors = array_merge($errors, feedpublisher_cleanup_validate_rules(isset($entry['strip_selectors']) ? $entry['strip_selectors'] : '', isset($entry['strip_regexes']) ? $entry['strip_regexes'] : ''));
    $enums = array('thread_date_mode' => array('publish','source'), 'future_date_policy' => array('hold','clamp','skip','reject'),
        'identity_strategy' => array('guid_link','title','content','title_content'), 'media_mode' => array('ignore','links','hotlink'),
        'publication_mode' => array('automatic','approval'), 'queue_order' => array('oldest','newest'),
        'fulltext_mode' => array('disabled','summary','always'), 'fulltext_fallback' => array('feed','skip','retry'),
        'initial_policy' => array('all','latest','recent','start_now'), 'attribution_mode' => array('link','title_link','none'),
        'continuation_mode' => array('none','source_link'));
    foreach ($enums as $field => $allowed) if (isset($entry[$field]) && !in_array($entry[$field], $allowed, true)) $errors[] = $field;
    return !$errors;
}

function feedpublisher_portability_export_opml()
{
    global $db;
    $lines = array('<?xml version="1.0" encoding="UTF-8"?>', '<opml version="2.0"><head><title>Feed Publisher feeds</title></head><body>');
    $query = $db->simple_select('feedpublisher_feeds', 'name,url,enabled', '', array('order_by' => 'name'));
    while ($feed = $db->fetch_array($query)) {
        $name = htmlspecialchars($feed['name'], ENT_QUOTES | ENT_XML1, 'UTF-8');
        $url = htmlspecialchars($feed['url'], ENT_QUOTES | ENT_XML1, 'UTF-8');
        $lines[] = '  <outline type="rss" text="' . $name . '" title="' . $name . '" xmlUrl="' . $url
            . '" feedPublisherEnabled="' . (int) $feed['enabled'] . '" />';
    }
    $lines[] = '</body></opml>';
    log_admin_action('Feed Publisher export', 'opml');
    feedpublisher_portability_download('feed-publisher-feeds.opml', 'text/x-opml; charset=UTF-8', implode("\n", $lines));
}

function feedpublisher_portability_export_config()
{
    global $db;
    $feeds = array();
    $fields = feedpublisher_portability_config_fields();
    $query = $db->write_query('SELECT f.*,fo.name AS destination_forum,u.username AS posting_username FROM '
        . TABLE_PREFIX . 'feedpublisher_feeds f LEFT JOIN ' . TABLE_PREFIX . 'forums fo ON (fo.fid=f.fid) '
        . 'LEFT JOIN ' . TABLE_PREFIX . 'users u ON (u.uid=f.uid) ORDER BY f.name');
    while ($feed = $db->fetch_array($query)) {
        $row = array('destination_forum' => (string) $feed['destination_forum'], 'posting_username' => (string) $feed['posting_username'],
            'source_thread_prefix_id' => (int) $feed['thread_prefix_id']);
        foreach ($fields as $field) $row[$field] = isset($feed[$field]) ? $feed[$field] : '';
        $feeds[] = $row;
    }
    $payload = json_encode(array('format' => 'mybb-feed-publisher-config', 'version' => 1, 'exported_at' => gmdate('c'), 'feeds' => $feeds),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    log_admin_action('Feed Publisher export', 'configuration');
    feedpublisher_portability_download('feed-publisher-config.json', 'application/json; charset=UTF-8', $payload . "\n");
}

function feedpublisher_portability_download($name, $type, $content)
{
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($content));
    header('X-Content-Type-Options: nosniff');
    echo $content;
    exit;
}

function feedpublisher_portability_page()
{
    global $mybb, $page;
    $page->add_breadcrumb_item('Feed Publisher', 'index.php?module=config/feedpublisher');
    $page->add_breadcrumb_item('Import / export');
    $page->output_header('Feed Publisher import / export');
    feedpublisher_admin_tabs('tools');
    echo '<p><a class="button" href="index.php?module=config/feedpublisher&amp;action=export_opml">Download OPML feed list</a> '
        . '<a class="button" href="index.php?module=config/feedpublisher&amp;action=export_config">Download full configuration</a></p>'
        . '<p>OPML contains feed names, URLs, and an enabled-state hint. The JSON configuration export also contains MyBB-specific feed settings, but never queue contents, imported-item history, errors, timestamps, credentials, cookies, or tokens.</p>'
        . '<form method="post" enctype="multipart/form-data" action="index.php?module=config/feedpublisher&amp;action=import_preview">'
        . '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">'
        . '<fieldset><legend>Preview an import</legend><p>Upload OPML or a Feed Publisher JSON export (maximum 256 KiB and 500 feeds). No feed URLs are fetched during preview or import.</p>'
        . '<p><input type="file" name="import_file" accept=".opml,.xml,.json,application/json,text/xml"></p>'
        . '<p>Or paste its contents:</p><textarea name="import_text" style="width:100%;height:180px"></textarea>'
        . '<p><button class="button" type="submit">Preview import</button></p></fieldset></form>';
    $page->output_footer();
}

function feedpublisher_portability_input()
{
    global $mybb;
    $content = trim($mybb->get_input('import_text'));
    if ($content === '' && !empty($_FILES['import_file']) && (int) $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
        if ((int) $_FILES['import_file']['size'] > 262144) throw new RuntimeException('The import file exceeds 256 KiB.');
        $content = (string) file_get_contents($_FILES['import_file']['tmp_name']);
    }
    if ($content === '') throw new RuntimeException('Choose a file or paste import data.');
    if (strlen($content) > 262144) throw new RuntimeException('The import data exceeds 256 KiB.');
    return $content;
}

function feedpublisher_portability_parse($content)
{
    $trimmed = ltrim($content);
    if (isset($trimmed[0]) && $trimmed[0] === '<') return array('type' => 'opml', 'entries' => feedpublisher_portability_parse_opml($content));
    $data = json_decode($content, true);
    if (!is_array($data) || ($data['format'] ?? '') !== 'mybb-feed-publisher-config' || (int) ($data['version'] ?? 0) !== 1 || !is_array($data['feeds'] ?? null)) {
        throw new RuntimeException('The JSON is not a supported Feed Publisher configuration export.');
    }
    if (count($data['feeds']) > 500) throw new RuntimeException('The configuration contains more than 500 feeds.');
    return array('type' => 'config', 'entries' => $data['feeds']);
}

function feedpublisher_portability_parse_opml($content)
{
    if (stripos($content, '<!DOCTYPE') !== false || stripos($content, '<!ENTITY') !== false) throw new RuntimeException('DOCTYPE and entity declarations are not allowed.');
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $loaded = $document->loadXML($content, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded || strtolower($document->documentElement->localName) !== 'opml') throw new RuntimeException('The uploaded XML is not valid OPML.');
    $xpath = new DOMXPath($document);
    $nodes = $xpath->query('//*[local-name()="outline"][@xmlUrl]');
    if ($nodes->length > 500) throw new RuntimeException('The OPML contains more than 500 feeds.');
    $entries = array();
    foreach ($nodes as $node) {
        $entries[] = array('name' => trim($node->getAttribute('title') ?: $node->getAttribute('text')),
            'url' => trim($node->getAttribute('xmlUrl')), 'enabled' => (int) $node->getAttribute('feedPublisherEnabled'));
    }
    return $entries;
}

function feedpublisher_portability_classify($parsed)
{
    global $db;
    $seen = array();
    $rows = array();
    foreach ($parsed['entries'] as $entry) {
        $url = trim(isset($entry['url']) ? $entry['url'] : '');
        $name = trim(isset($entry['name']) ? $entry['name'] : '');
        $status = 'new';
        if (!feedpublisher_portability_url_valid($url) || $name === '') $status = 'invalid';
        elseif ($parsed['type'] === 'config' && !feedpublisher_portability_settings_supported($entry)) $status = 'unsupported settings';
        elseif (isset($seen[$url])) $status = 'duplicate in file';
        else {
            $seen[$url] = true;
            $exists = $db->fetch_field($db->simple_select('feedpublisher_feeds', 'id', "url='" . $db->escape_string($url) . "'", array('limit' => 1)), 'id');
            if ($exists) $status = 'already exists';
        }
        $rows[] = array('entry' => $entry, 'name' => my_substr($name, 0, 150), 'url' => $url, 'status' => $status);
    }
    return $rows;
}

function feedpublisher_portability_mapping_options()
{
    global $db;
    $forums = array(0 => 'Select destination forum');
    $query = $db->simple_select('forums', 'fid,name', "type='f' AND active=1", array('order_by' => 'disporder,name'));
    while ($row = $db->fetch_array($query)) $forums[(int) $row['fid']] = $row['name'];
    $users = array(0 => 'Select posting user');
    $query = $db->simple_select('users', 'uid,username', '', array('order_by' => 'username'));
    while ($row = $db->fetch_array($query)) $users[(int) $row['uid']] = $row['username'];
    return array($forums, $users);
}

function feedpublisher_portability_name_index($options)
{
    $index = array();
    foreach ($options as $id => $name) {
        if (!(int) $id) continue;
        $key = strtolower(trim((string) $name));
        if ($key === '') continue;
        $index[$key] = isset($index[$key]) ? 0 : (int) $id;
    }
    return $index;
}

function feedpublisher_portability_resolve_mapping($entry, $forums, $users, $fallbackFid = 0, $fallbackUid = 0, $useSaved = true)
{
    $fid = 0; $uid = 0; $forumName = trim((string) ($entry['destination_forum'] ?? ''));
    $username = trim((string) ($entry['posting_username'] ?? ''));
    if ($useSaved) {
        $forumIndex = feedpublisher_portability_name_index($forums);
        $userIndex = feedpublisher_portability_name_index($users);
        $fid = isset($forumIndex[strtolower($forumName)]) ? $forumIndex[strtolower($forumName)] : 0;
        $uid = isset($userIndex[strtolower($username)]) ? $userIndex[strtolower($username)] : 0;
    }
    return array(
        'fid' => $fid ?: (int) $fallbackFid,
        'uid' => $uid ?: (int) $fallbackUid,
        'forum_saved' => $fid > 0,
        'user_saved' => $uid > 0,
        'complete' => ($fid ?: (int) $fallbackFid) > 0 && ($uid ?: (int) $fallbackUid) > 0,
    );
}

function feedpublisher_portability_preview()
{
    global $mybb, $page;
    verify_post_check($mybb->get_input('my_post_key'));
    try {
        $content = feedpublisher_portability_input();
        $parsed = feedpublisher_portability_parse($content);
        $rows = feedpublisher_portability_classify($parsed);
    } catch (Throwable $exception) {
        flash_message(htmlspecialchars_uni($exception->getMessage()), 'error');
        admin_redirect('index.php?module=config/feedpublisher&action=tools');
    }
    list($forums, $users) = feedpublisher_portability_mapping_options();
    $form = new Form('index.php?module=config/feedpublisher&amp;action=import_apply', 'post');
    $page->add_breadcrumb_item('Feed Publisher', 'index.php?module=config/feedpublisher');
    $page->add_breadcrumb_item('Import preview');
    $page->output_header('Feed Publisher import preview');
    feedpublisher_admin_tabs('tools');
    echo '<p><strong>Format:</strong> ' . ($parsed['type'] === 'opml' ? 'OPML feed list' : 'Feed Publisher configuration')
        . '. This preview made no configuration changes and performed no network requests.</p>';
    $table = new Table;
    foreach (array('Feed', 'URL', 'Saved mapping', 'Restore mapping', 'Result') as $heading) $table->construct_header($heading);
    $new = 0;
    foreach ($rows as $row) {
        if ($row['status'] === 'new') ++$new;
        $entry = $row['entry'];
        $mapping = $parsed['type'] === 'config'
            ? 'Forum: ' . htmlspecialchars_uni(isset($entry['destination_forum']) ? $entry['destination_forum'] : 'unknown')
                . '<br>User: ' . htmlspecialchars_uni(isset($entry['posting_username']) ? $entry['posting_username'] : 'unknown')
                . '<br>Native prefix: ' . (!empty($entry['source_thread_prefix_id']) ? '#' . (int) $entry['source_thread_prefix_id'] . ' (reset on import)' : 'none')
            : 'Not included in OPML';
        $resolved = feedpublisher_portability_resolve_mapping($entry, $forums, $users);
        $restore = $parsed['type'] === 'config'
            ? 'Forum: ' . ($resolved['forum_saved'] ? htmlspecialchars_uni($forums[$resolved['fid']]) . ' (matched)' : 'needs fallback')
                . '<br>User: ' . ($resolved['user_saved'] ? htmlspecialchars_uni($users[$resolved['uid']]) . ' (matched)' : 'needs fallback')
            : 'Uses fallback targets';
        $table->construct_cell(htmlspecialchars_uni($row['name'] ?: '(missing name)'));
        $table->construct_cell(htmlspecialchars_uni($row['url'] ?: '(missing URL)'));
        $table->construct_cell($mapping);
        $table->construct_cell($restore);
        $table->construct_cell(htmlspecialchars_uni($row['status']));
        $table->construct_row();
    }
    if (!$rows) { $table->construct_cell('No feed entries were found.', array('colspan' => 5)); $table->construct_row(); }
    $table->output('Import entries');
    echo $form->generate_hidden_field('import_payload', base64_encode($content));
    echo '<fieldset><legend>Restore target mapping</legend><p>Full backups match each saved forum name and username against this installation. IDs are never trusted. Fallbacks are used for OPML and for any name that is missing, renamed, or ambiguous.</p>'
        . '<p>' . $form->generate_check_box('restore_saved_mapping', 1, 'Restore each feed\'s saved forum and posting user when both names match.', array('checked' => true)) . '</p>'
        . '<p>Fallback forum: ' . $form->generate_select_box('fid', $forums, 0) . '</p>'
        . '<p>Fallback posting user: ' . $form->generate_select_box('uid', $users, 0) . '</p>'
        . '<p>' . $form->generate_check_box('preserve_enabled', 1, 'Preserve exported enabled states (otherwise every imported feed starts disabled).') . '</p></fieldset>';
    echo '<p><strong>' . $new . '</strong> new feed(s) can be imported. Existing, repeated, and invalid entries will be skipped.</p>';
    $form->output_submit_wrapper(array($form->generate_submit_button('Import new feeds')));
    $page->output_footer();
}

function feedpublisher_portability_defaults($entry, $fid, $uid, $preserveEnabled)
{
    $defaults = array(
        'name' => my_substr(trim((string) ($entry['name'] ?? '')), 0, 150), 'url' => trim((string) ($entry['url'] ?? '')),
        'title_prefix' => '', 'thread_date_mode' => 'publish', 'future_date_policy' => 'hold', 'schedule_jitter_minutes' => 0,
        'identity_strategy' => 'guid_link', 'terminal_retention_days' => 90, 'terminal_retention_count' => 1000,
        'dedupe_retention_days' => 0, 'strict_reconciliation' => 0, 'eligibility_rules' => '',
        'minimum_source_age_hours' => 0, 'maximum_source_age_days' => 0, 'require_entry_body' => 0,
        'require_entry_media' => 0, 'media_mode' => 'ignore', 'publication_mode' => 'automatic', 'enabled' => 0,
        'fulltext_mode' => 'disabled', 'fulltext_fallback' => 'feed', 'fulltext_summary_chars' => 600, 'fulltext_max_per_run' => 3,
        'interval_minutes' => 60, 'publish_interval_minutes' => 60, 'max_posts_per_run' => 1, 'queue_order' => 'oldest',
        'publishing_paused' => 0, 'initial_policy' => 'latest', 'initial_limit' => 1, 'attribution_mode' => 'link',
        'post_header' => '', 'post_footer' => '', 'body_length_limit' => 0, 'continuation_mode' => 'none',
        'continuation_text' => 'Continue reading', 'remove_bylines' => 0, 'remove_source_links' => 0,
        'strip_selectors' => '', 'strip_regexes' => '');
    foreach (feedpublisher_portability_config_fields() as $field) {
        if (array_key_exists($field, $entry)) $defaults[$field] = $entry[$field];
    }
    $defaults['name'] = my_substr(trim((string) $defaults['name']), 0, 150);
    $defaults['url'] = trim((string) $defaults['url']);
    $defaults['enabled'] = $preserveEnabled && !empty($defaults['enabled']) ? 1 : 0;
    foreach (array('strict_reconciliation','require_entry_body','require_entry_media','publishing_paused','remove_bylines','remove_source_links') as $field) $defaults[$field] = !empty($defaults[$field]) ? 1 : 0;
    $enums = array('thread_date_mode' => array('publish','source'), 'future_date_policy' => array('hold','clamp','skip','reject'),
        'identity_strategy' => array('guid_link','title','content','title_content'), 'media_mode' => array('ignore','links','hotlink'),
        'publication_mode' => array('automatic','approval'), 'queue_order' => array('oldest','newest'),
        'fulltext_mode' => array('disabled','summary','always'), 'fulltext_fallback' => array('feed','skip','retry'),
        'initial_policy' => array('all','latest','recent','start_now'), 'attribution_mode' => array('link','title_link','none'),
        'continuation_mode' => array('none','source_link'));
    foreach ($enums as $field => $allowed) if (!in_array($defaults[$field], $allowed, true)) $defaults[$field] = $allowed[0];
    $bounds = array('schedule_jitter_minutes' => array(0,60), 'terminal_retention_days' => array(1,3650),
        'terminal_retention_count' => array(100,100000), 'dedupe_retention_days' => array(0,3650),
        'minimum_source_age_hours' => array(0,8760), 'maximum_source_age_days' => array(0,3650),
        'interval_minutes' => array(5,10080), 'publish_interval_minutes' => array(5,10080),
        'max_posts_per_run' => array(1,25), 'initial_limit' => array(1,100), 'body_length_limit' => array(0,100000));
    $bounds['fulltext_summary_chars'] = array(100,5000);
    $bounds['fulltext_max_per_run'] = array(1,10);
    foreach ($bounds as $field => $range) $defaults[$field] = max($range[0], min($range[1], (int) $defaults[$field]));
    $defaults['title_prefix'] = my_substr(trim((string) $defaults['title_prefix']), 0, 40);
    $defaults['continuation_text'] = my_substr(trim((string) $defaults['continuation_text']), 0, 100);
    foreach (array('eligibility_rules','post_header','post_footer','strip_selectors','strip_regexes') as $field) {
        $defaults[$field] = substr((string) $defaults[$field], 0, 10000);
    }
    $defaults['fid'] = (int) $fid; $defaults['uid'] = (int) $uid; $defaults['thread_prefix_id'] = 0;
    $defaults['initialized_at'] = 0;
    return $defaults;
}

function feedpublisher_portability_apply()
{
    global $db, $mybb;
    verify_post_check($mybb->get_input('my_post_key'));
    $payload = base64_decode($mybb->get_input('import_payload'), true);
    if ($payload === false || strlen($payload) > 262144) {
        flash_message('The import preview payload is invalid or too large.', 'error');
        admin_redirect('index.php?module=config/feedpublisher&action=tools');
    }
    $fid = $mybb->get_input('fid', MyBB::INPUT_INT);
    $uid = $mybb->get_input('uid', MyBB::INPUT_INT);
    try {
        $parsed = feedpublisher_portability_parse($payload);
        $rows = feedpublisher_portability_classify($parsed);
    } catch (Throwable $exception) {
        flash_message(htmlspecialchars_uni($exception->getMessage()), 'error');
        admin_redirect('index.php?module=config/feedpublisher&action=tools');
    }
    list($forums, $users) = feedpublisher_portability_mapping_options();
    $useSaved = $parsed['type'] === 'config' && $mybb->get_input('restore_saved_mapping', MyBB::INPUT_INT);
    $inserted = 0; $skipped = 0; $unmapped = 0;
    foreach ($rows as $row) {
        if ($row['status'] !== 'new') { ++$skipped; continue; }
        $mapping = feedpublisher_portability_resolve_mapping($row['entry'], $forums, $users, $fid, $uid, $useSaved);
        if (!$mapping['complete']) { ++$unmapped; continue; }
        $forum = $db->fetch_array($db->simple_select('forums', 'fid,type,active,linkto', 'fid=' . $mapping['fid'], array('limit' => 1)));
        $user = $db->fetch_array($db->simple_select('users', 'uid', 'uid=' . $mapping['uid'], array('limit' => 1)));
        $permissions = $user ? forum_permissions($mapping['fid'], $mapping['uid']) : array();
        if (!$forum || $forum['type'] !== 'f' || empty($forum['active']) || !empty($forum['linkto']) || !$user
            || empty($permissions['canview']) || empty($permissions['canpostthreads'])) { ++$unmapped; continue; }
        $record = feedpublisher_portability_defaults($row['entry'], $mapping['fid'], $mapping['uid'], $mybb->get_input('preserve_enabled', MyBB::INPUT_INT));
        foreach ($record as $field => $value) if (!is_int($value)) $record[$field] = $db->escape_string($value);
        $db->insert_query('feedpublisher_feeds', $record);
        ++$inserted;
    }
    log_admin_action('Feed Publisher import', $parsed['type'], $inserted, $skipped, $unmapped);
    flash_message('Imported ' . $inserted . ' new feed(s); skipped ' . $skipped . ' existing, repeated, or invalid entries and ' . $unmapped . ' without a valid forum/user mapping. No feed URLs were fetched.', 'success');
    admin_redirect('index.php?module=config/feedpublisher&action=tools');
}
