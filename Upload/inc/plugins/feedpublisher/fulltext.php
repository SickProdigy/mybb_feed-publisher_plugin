<?php
/**
 * Secure deterministic linked-article extraction.
 * Copyright (c) 2026 SickProdigy
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!defined('IN_MYBB')) {
    die('Direct access is not allowed.');
}

function feedpublisher_fulltext_fetch($url, &$metadata = null)
{
    $html = feedpublisher_fetch_resource($url, 2097152, 'text/html, application/xhtml+xml', $metadata);
    if (!in_array($metadata['content_type'], array('text/html', 'application/xhtml+xml'), true)) {
        throw new FeedPublisherException('content-type', 'The linked article response was not HTML.');
    }
    return feedpublisher_fulltext_normalize_encoding($html, isset($metadata['http_charset']) ? $metadata['http_charset'] : '');
}

function feedpublisher_fulltext_normalize_encoding($html, $httpCharset = '')
{
    $charset = trim((string) $httpCharset);
    if ($charset === '' && preg_match('/<meta\s+[^>]*charset\s*=\s*["\']?([^\s"\'>;]+)/i', $html, $match)) $charset = $match[1];
    if ($charset === '' && preg_match('/<meta\s+[^>]*content\s*=\s*["\'][^"\']*charset=([^\s;"\']+)/i', $html, $match)) $charset = $match[1];
    if ($charset === '') {
        $charset = !function_exists('mb_check_encoding') || mb_check_encoding($html, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
    }
    $charset = feedpublisher_canonical_encoding($charset);
    if ($charset !== 'UTF-8') {
        if (function_exists('mb_convert_encoding')) $html = @mb_convert_encoding($html, 'UTF-8', $charset);
        elseif (function_exists('iconv')) $html = @iconv($charset, 'UTF-8//IGNORE', $html);
        else throw new FeedPublisherException('fulltext', 'The linked article charset requires mbstring or iconv conversion.');
    }
    if (!is_string($html) || (function_exists('mb_check_encoding') && !mb_check_encoding($html, 'UTF-8'))) {
        throw new FeedPublisherException('fulltext', 'The linked article could not be normalized to UTF-8.');
    }
    return preg_replace('/^\xEF\xBB\xBF/', '', $html);
}

function feedpublisher_fulltext_extract($html, $url, &$metadata = null)
{
    if (strlen($html) > 2097152) throw new FeedPublisherException('fulltext', 'The linked article exceeded the 2 MiB extraction limit.');
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument('1.0', 'UTF-8');
    $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) throw new FeedPublisherException('fulltext', 'The linked article HTML could not be parsed.');
    $xpath = new DOMXPath($document);
    $all = $xpath->query('//*');
    if (!$all || $all->length > 20000) throw new FeedPublisherException('fulltext', 'The linked article exceeded the 20,000-node extraction limit.');
    feedpublisher_cleanup_remove_nodes($xpath, '//script|//style|//iframe|//object|//embed|//form|//nav|//aside|//noscript|//svg|//canvas|//template|//header|//footer');

    $candidates = $xpath->query('//article|//main|//*[@itemprop="articleBody"]|//*[@role="main"]|//div|//section');
    $best = null; $bestScore = 0;
    foreach ($candidates as $candidate) {
        $textLength = my_strlen(trim(preg_replace('/\s+/u', ' ', $candidate->textContent)));
        if ($textLength < 200) continue;
        $paragraphs = $xpath->query('.//p', $candidate)->length;
        $headings = $xpath->query('.//h1|.//h2|.//h3', $candidate)->length;
        $linkText = 0;
        foreach ($xpath->query('.//a', $candidate) as $link) $linkText += my_strlen(trim($link->textContent));
        $hint = strtolower($candidate->getAttribute('class') . ' ' . $candidate->getAttribute('id'));
        $explicit = in_array(strtolower($candidate->nodeName), array('article','main'), true)
            || $candidate->getAttribute('itemprop') === 'articleBody' || strtolower($candidate->getAttribute('role')) === 'main';
        if (!$explicit && $paragraphs < 2 && !preg_match('/article|content|entry|post|story/', $hint)) continue;
        $score = $textLength + $paragraphs * 120 + $headings * 40 - $linkText * 2 + ($explicit ? 1000 : 0);
        if (preg_match('/article|content|entry|post|story/', $hint)) $score += 300;
        if (preg_match('/comment|footer|header|sidebar|related|share|social|promo|advert|menu/', $hint)) $score -= 1000;
        if ($score > $bestScore) { $best = $candidate; $bestScore = $score; }
    }
    if (!$best) throw new FeedPublisherException('fulltext', 'No sufficiently substantial article container was found.');

    $base = $url;
    $baseNodes = $document->getElementsByTagName('base');
    if ($baseNodes->length) {
        $declared = feedpublisher_resolve_relative_content_url($baseNodes->item(0)->getAttribute('href'), $url);
        if ($declared !== '') $base = $declared;
    }
    foreach ($xpath->query('.//*[@href]|.//*[@src]', $best) as $node) {
        foreach (array('href','src') as $attribute) {
            if (!$node->hasAttribute($attribute)) continue;
            $resolved = feedpublisher_resolve_relative_content_url($node->getAttribute($attribute), $base);
            if ($resolved === '') $node->removeAttribute($attribute); else $node->setAttribute($attribute, $resolved);
        }
    }
    $output = '';
    foreach ($best->childNodes as $child) $output .= $document->saveHTML($child);
    $plainLength = my_strlen(trim(preg_replace('/\s+/u', ' ', $best->textContent)));
    if (trim($output) === '' || $plainLength < 200) throw new FeedPublisherException('fulltext', 'Article extraction returned insufficient content.');
    $metadata = array('selector' => strtolower($best->nodeName), 'text_characters' => $plainLength, 'html_bytes' => strlen($output));
    return $output;
}

function feedpublisher_fulltext_item_known($feed, $item, $identity)
{
    global $db;
    if (empty($feed['id']) || empty($identity['key'])) return false;
    $condition = 'feed_id=' . (int) $feed['id'] . " AND item_key='" . $db->escape_string($identity['key']) . "'";
    return (bool) $db->fetch_field($db->simple_select('feedpublisher_queue', 'id', $condition, array('limit' => 1)), 'id')
        || (bool) $db->fetch_field($db->simple_select('feedpublisher_items', 'id', $condition, array('limit' => 1)), 'id');
}

function feedpublisher_fulltext_failure($feed, &$entry, $message)
{
    $fallback = isset($feed['fulltext_fallback']) ? $feed['fulltext_fallback'] : 'feed';
    $entry['item']['_fulltext'] = array('source' => 'feed', 'status' => 'fallback', 'message' => feedpublisher_safe_log_text($message));
    if ($fallback === 'feed' && trim(strip_tags((string) $entry['item']['content'])) === '') {
        throw new FeedPublisherException('fulltext', $message . ' The original feed content was also empty, so no fallback post was queued.');
    } elseif ($fallback === 'skip') {
        $entry['state'] = 'skipped';
        $entry['item']['_disposition'] = 'skipped';
    } elseif ($fallback === 'retry') {
        throw new FeedPublisherException('fulltext', $message);
    }
}

function feedpublisher_fulltext_prepare_plan($feed, $plan)
{
    $mode = isset($feed['fulltext_mode']) ? $feed['fulltext_mode'] : 'disabled';
    if ($mode === 'disabled') {
        foreach ($plan as &$entry) $entry['item']['_fulltext'] = array('source' => 'feed', 'status' => 'disabled', 'message' => 'Full-text retrieval is disabled.');
        unset($entry);
        return $plan;
    }
    $threshold = max(100, min(5000, (int) (isset($feed['fulltext_summary_chars']) ? $feed['fulltext_summary_chars'] : 600)));
    $limit = max(1, min(10, (int) (isset($feed['fulltext_max_per_run']) ? $feed['fulltext_max_per_run'] : 3)));
    $attempted = 0;
    foreach ($plan as &$entry) {
        $item =& $entry['item'];
        $identity = feedpublisher_derive_item_identity($feed, $item);
        $item['_identity_override'] = $identity;
        $plainLength = my_strlen(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['content']))));
        $needs = $entry['state'] === 'queued' && ($mode === 'always' || $plainLength < $threshold);
        if (!$needs) {
            $item['_fulltext'] = array('source' => 'feed', 'status' => 'not-needed', 'message' => 'Feed content retained (' . $plainLength . ' text characters).');
            unset($item);
            continue;
        }
        if (feedpublisher_fulltext_item_known($feed, $item, $identity)) {
            $item['_fulltext'] = array('source' => 'feed', 'status' => 'known', 'message' => 'Entry already exists; linked article was not fetched.');
            unset($item);
            continue;
        }
        if (++$attempted > $limit) {
            $entry['state'] = 'deferred_fulltext';
            $item['_fulltext'] = array('source' => 'feed', 'status' => 'deferred',
                'message' => 'Deferred until a later run because the per-run linked-article fetch limit was reached.');
            unset($item);
            continue;
        }
        try {
            if (!feedpublisher_safe_content_url($item['url'])) throw new FeedPublisherException('fulltext', 'The entry has no safe linked article URL.');
            $fetch = array();
            $html = feedpublisher_fulltext_fetch($item['url'], $fetch);
            $extract = array();
            $content = feedpublisher_fulltext_extract($html, $item['url'], $extract);
            $item['content'] = $content;
            $item['_fulltext'] = array('source' => 'fulltext', 'status' => 'extracted', 'message' => 'Extracted linked article.',
                'fetch' => $fetch, 'extract' => $extract, 'feed_characters' => $plainLength);
        } catch (Throwable $exception) {
            feedpublisher_fulltext_failure($feed, $entry, $exception->getMessage());
        }
        unset($item);
    }
    unset($entry);
    return $plan;
}
