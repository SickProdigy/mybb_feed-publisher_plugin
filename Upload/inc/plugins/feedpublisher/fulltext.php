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

function feedpublisher_fulltext_url_rewrite_errors($regex, $replacement)
{
    $regex = trim((string) $regex);
    $replacement = trim((string) $replacement);
    $errors = array();
    if (($regex === '') !== ($replacement === '')) {
        $errors[] = 'Full-article URL rewriting requires both a match regex and replacement.';
        return $errors;
    }
    if ($regex === '') return $errors;
    if (strlen($regex) > 255 || preg_match('/[\r\n\x00]/', $regex)) {
        $errors[] = 'Full-article URL match regex must be one line and no longer than 255 bytes.';
    } else {
        set_error_handler(function () { return true; });
        $valid = preg_match($regex, '') !== false;
        restore_error_handler();
        if (!$valid) $errors[] = 'Full-article URL match regex must be a valid PHP-compatible regular expression.';
    }
    if (strlen($replacement) > 2048 || preg_match('/[\r\n\x00]/', $replacement)) {
        $errors[] = 'Full-article URL replacement must be one line and no longer than 2048 bytes.';
    }
    return $errors;
}

function feedpublisher_fulltext_rewrite_url($feed, $url, &$metadata = null)
{
    $original = trim((string) $url);
    $regex = isset($feed['fulltext_url_regex']) ? trim((string) $feed['fulltext_url_regex']) : '';
    $replacement = isset($feed['fulltext_url_replacement']) ? trim((string) $feed['fulltext_url_replacement']) : '';
    $metadata = array('original_url' => $original, 'fetch_url' => $original, 'rewritten' => false);
    $errors = feedpublisher_fulltext_url_rewrite_errors($regex, $replacement);
    if ($errors) throw new FeedPublisherException('fulltext', $errors[0]);
    if ($regex === '') return $original;
    $count = 0;
    $rewritten = preg_replace($regex, $replacement, $original, 1, $count);
    if (!is_string($rewritten)) throw new FeedPublisherException('fulltext', 'Full-article URL rewriting failed.');
    if ($count === 0) return $original;
    if (!feedpublisher_safe_content_url($rewritten)) {
        throw new FeedPublisherException('fulltext', 'The rewritten full-article URL is not a safe public HTTP or HTTPS URL.');
    }
    $metadata['fetch_url'] = $rewritten;
    $metadata['rewritten'] = $rewritten !== $original;
    return $rewritten;
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
        $focusedBody = preg_match('/(?:article|entry|post|story)[-_\s]?(?:body|content)|(?:body|content)[-_\s]?(?:article|entry|post|story)|rich[-_\s]?content/', $hint);
        if (!$explicit && $paragraphs < 2 && !preg_match('/article|content|entry|post|story/', $hint)) continue;
        $score = $textLength + $paragraphs * 120 + $headings * 40 - $linkText * 2 + ($explicit ? 1000 : 0);
        if (preg_match('/article|content|entry|post|story/', $hint)) $score += 300;
        if ($focusedBody) $score += 3000;
        if (preg_match('/comment|footer|header|sidebar|related|share|social|promo|advert|menu/', $hint)) $score -= 1000;
        if ($score > $bestScore) { $best = $candidate; $bestScore = $score; }
    }
    if (!$best) throw new FeedPublisherException('fulltext', 'No sufficiently substantial article container was found.');
    feedpublisher_fulltext_remove_article_chrome($xpath, $best);

    $base = $url;
    $baseNodes = $document->getElementsByTagName('base');
    if ($baseNodes->length) {
        $declared = feedpublisher_resolve_relative_content_url($baseNodes->item(0)->getAttribute('href'), $url);
        if ($declared !== '') $base = $declared;
    }
    feedpublisher_fulltext_normalize_lazy_images($xpath, $best, $base);
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

function feedpublisher_fulltext_normalize_lazy_images(DOMXPath $xpath, DOMElement $article, $base)
{
    $images = $xpath->query('.//img', $article);
    if (!$images) return;
    foreach ($images as $image) {
        if (!$image instanceof DOMElement) continue;
        $replacement = '';
        foreach (array('data-src', 'data-lazy-src', 'data-original', 'data-original-src') as $attribute) {
            if (!$image->hasAttribute($attribute)) continue;
            $replacement = feedpublisher_resolve_relative_content_url($image->getAttribute($attribute), $base);
            if ($replacement !== '') break;
        }
        if ($replacement === '') {
            foreach (array('data-srcset', 'data-lazy-srcset') as $attribute) {
                if (!$image->hasAttribute($attribute)) continue;
                $replacement = feedpublisher_fulltext_srcset_url($image->getAttribute($attribute), $base);
                if ($replacement !== '') break;
            }
        }
        if ($replacement !== '') {
            $image->setAttribute('src', $replacement);
        } elseif (feedpublisher_fulltext_placeholder_image($image->getAttribute('src'))) {
            $image->removeAttribute('src');
        }
        foreach (array('data-src', 'data-lazy-src', 'data-original', 'data-original-src', 'data-srcset', 'data-lazy-srcset') as $attribute) {
            $image->removeAttribute($attribute);
        }
    }
}

function feedpublisher_fulltext_srcset_url($srcset, $base)
{
    $best = ''; $bestScore = -1; $position = 0;
    foreach (preg_split('/\s*,\s*/', trim((string) $srcset)) as $candidate) {
        if (!preg_match('/^(\S+)(?:\s+([0-9]+(?:\.[0-9]+)?)(w|x))?$/i', trim($candidate), $match)) continue;
        $resolved = feedpublisher_resolve_relative_content_url($match[1], $base);
        if ($resolved === '') continue;
        $score = isset($match[2]) ? (float) $match[2] : (float) ++$position;
        if (isset($match[3]) && strtolower($match[3]) === 'x') $score *= 10000;
        if ($score >= $bestScore) { $best = $resolved; $bestScore = $score; }
    }
    return $best;
}

function feedpublisher_fulltext_placeholder_image($url)
{
    $path = strtolower((string) parse_url(trim((string) $url), PHP_URL_PATH));
    $name = basename($path);
    return $name !== '' && (bool) preg_match('/(?:^|[-_.])(placeholder|spacer|transparent|blank|pixel)(?:[-_.]|$)/', $name);
}

function feedpublisher_fulltext_remove_article_chrome(DOMXPath $xpath, DOMElement $article)
{
    $chromePattern = '/(?:^|[-_\s])(?:ad|ads|advert|advertisement|affiliate|breadcrumb|comment|comments|newsletter|promo|promoted|recommended|related|share|sharing|social|sponsor|sponsored|subscribe|trending)(?:$|[-_\s])/i';
    $nodes = $xpath->query('.//*[self::aside or self::div or self::section or self::ul or self::ol or self::footer or self::header or self::nav or self::form]', $article);
    if ($nodes) {
        for ($index = $nodes->length - 1; $index >= 0; --$index) {
            $node = $nodes->item($index);
            if (!$node instanceof DOMElement) {
                continue;
            }
            $hint = trim($node->getAttribute('class') . ' ' . $node->getAttribute('id') . ' ' . $node->getAttribute('role') . ' ' . $node->getAttribute('aria-label'));
            if ($hint !== '' && preg_match($chromePattern, $hint) && $node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    feedpublisher_fulltext_remove_article_tail($xpath, $article);

    $labels = array(
        'advertisement' => true,
        'advertisements' => true,
        'add a comment' => true,
        'close' => true,
        'copied!' => true,
        'copy link' => true,
        'view and download image' => true,
        'download the image' => true,
        'download this image' => true,
        'join the conversation' => true,
        'print' => true,
        'share' => true,
        'share this article' => true,
        'show comments' => true,
        'sign up' => true,
        'subscribe' => true,
    );
    $nodes = $xpath->query('.//*[self::a or self::button or self::span or self::strong]', $article);
    if (!$nodes) {
        return;
    }
    for ($index = $nodes->length - 1; $index >= 0; --$index) {
        $node = $nodes->item($index);
        if (!$node instanceof DOMElement || $xpath->query('.//img', $node)->length > 0) {
            continue;
        }
        $label = strtolower(trim(preg_replace('/\s+/u', ' ', $node->textContent)));
        if (isset($labels[$label]) && $node->parentNode) {
            $node->parentNode->removeChild($node);
        }
    }
}

function feedpublisher_fulltext_remove_article_tail(DOMXPath $xpath, DOMElement $article)
{
    $tailLabels = array('filed under' => true, 'keep reading' => true, 'latest news' => true, 'related articles' => true, 'related posts' => true, 'trending stories' => true);
    $nodes = $xpath->query('.//*[self::h2 or self::h3 or self::h4 or self::p or self::div]', $article);
    if (!$nodes) {
        return;
    }
    foreach ($nodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $label = strtolower(trim(preg_replace('/\s+/u', ' ', $node->textContent)));
        if (!isset($tailLabels[$label]) || !$node->parentNode) {
            continue;
        }
        $preceding = '';
        for ($sibling = $node->previousSibling; $sibling; $sibling = $sibling->previousSibling) {
            $preceding .= ' ' . $sibling->textContent;
        }
        if (my_strlen(trim(preg_replace('/\s+/u', ' ', $preceding))) < 200) {
            continue;
        }
        for ($sibling = $node->nextSibling; $sibling;) {
            $next = $sibling->nextSibling;
            $node->parentNode->removeChild($sibling);
            $sibling = $next;
        }
        $node->parentNode->removeChild($node);
        break;
    }
}

function feedpublisher_fulltext_item_known($feed, $item, $identity)
{
    global $db;
    if (empty($feed['id']) || empty($identity['key'])) return false;
    $condition = 'feed_id=' . (int) $feed['id'] . " AND item_key='" . $db->escape_string($identity['key']) . "'";
    return (bool) $db->fetch_field($db->simple_select('feedpublisher_queue', 'id', $condition, array('limit' => 1)), 'id')
        || (bool) $db->fetch_field($db->simple_select('feedpublisher_items', 'id', $condition, array('limit' => 1)), 'id');
}

function feedpublisher_fulltext_retry_state($feedId, $itemKey)
{
    global $db;
    if ((int) $feedId <= 0 || $itemKey === '') return false;
    return $db->fetch_array($db->simple_select(
        'feedpublisher_fulltext_retries',
        '*',
        'feed_id=' . (int) $feedId . " AND item_key='" . $db->escape_string($itemKey) . "'",
        array('limit' => 1)
    ));
}

function feedpublisher_fulltext_retry_clear($feedId, $itemKey)
{
    global $db;
    if ((int) $feedId > 0 && $itemKey !== '') {
        $db->delete_query('feedpublisher_fulltext_retries',
            'feed_id=' . (int) $feedId . " AND item_key='" . $db->escape_string($itemKey) . "'");
    }
}

function feedpublisher_fulltext_retry_record($feedId, $itemKey, $message)
{
    global $db;
    $existing = feedpublisher_fulltext_retry_state($feedId, $itemKey);
    $attempts = $existing ? (int) $existing['attempts'] + 1 : 1;
    $delay = min(21600, 300 * (2 ** min(6, $attempts - 1)));
    $record = array(
        'attempts' => $attempts,
        'next_attempt_at' => TIME_NOW + $delay,
        'last_attempt_at' => TIME_NOW,
        'last_error' => $db->escape_string(feedpublisher_safe_log_text($message)),
    );
    if ($existing) {
        $db->update_query('feedpublisher_fulltext_retries', $record, 'id=' . (int) $existing['id']);
    } else {
        $record['feed_id'] = (int) $feedId;
        $record['item_key'] = $db->escape_string($itemKey);
        $db->insert_query('feedpublisher_fulltext_retries', $record);
    }
    return $record;
}

function feedpublisher_fulltext_next_retry_at($feedId)
{
    global $db;
    if ((int) $feedId <= 0) return 0;
    return (int) $db->fetch_field($db->simple_select(
        'feedpublisher_fulltext_retries',
        'MIN(next_attempt_at) AS next_retry',
        'feed_id=' . (int) $feedId . ' AND next_attempt_at>0'
    ), 'next_retry');
}

function feedpublisher_fulltext_retry_prune($feedId, $seenItemKeys)
{
    global $db;
    if ((int) $feedId <= 0) return 0;
    $seen = array_fill_keys($seenItemKeys, true);
    $removed = 0;
    $query = $db->simple_select('feedpublisher_fulltext_retries', 'item_key', 'feed_id=' . (int) $feedId);
    while ($row = $db->fetch_array($query)) {
        if (!isset($seen[$row['item_key']])) {
            $db->delete_query('feedpublisher_fulltext_retries',
                'feed_id=' . (int) $feedId . " AND item_key='" . $db->escape_string($row['item_key']) . "'");
            ++$removed;
        }
    }
    return $removed;
}

function feedpublisher_fulltext_failure($feed, &$entry, $message, $identity = array())
{
    $fallback = isset($feed['fulltext_fallback']) ? $feed['fulltext_fallback'] : 'feed';
    $feedId = isset($feed['id']) ? (int) $feed['id'] : 0;
    $itemKey = isset($identity['key']) ? $identity['key'] : '';
    $urlMetadata = isset($entry['item']['_fulltext']['url']) ? $entry['item']['_fulltext']['url'] : null;
    $entry['item']['_fulltext'] = array('source' => 'feed', 'status' => 'fallback', 'message' => feedpublisher_safe_log_text($message));
    if ($urlMetadata !== null) $entry['item']['_fulltext']['url'] = $urlMetadata;
    if ($fallback === 'feed' && trim(strip_tags((string) $entry['item']['content'])) === '') {
        $entry['state'] = 'rejected';
        $entry['item']['_disposition'] = 'rejected';
        $entry['item']['_fulltext']['status'] = 'rejected';
        $entry['item']['_fulltext']['message'] = feedpublisher_safe_log_text($message . ' The original feed content was also empty, so the entry was rejected.');
        feedpublisher_fulltext_retry_clear($feedId, $itemKey);
    } elseif ($fallback === 'skip') {
        $entry['state'] = 'skipped';
        $entry['item']['_disposition'] = 'skipped';
        feedpublisher_fulltext_retry_clear($feedId, $itemKey);
    } elseif ($fallback === 'retry' || $fallback === 'retry_skip') {
        $retry = $feedId > 0
            ? feedpublisher_fulltext_retry_record($feedId, $itemKey, $message)
            : array('attempts' => 1, 'next_attempt_at' => TIME_NOW + 300);
        if ($fallback === 'retry_skip' && (int) $retry['attempts'] >= 4) {
            $entry['state'] = 'skipped';
            $entry['item']['_disposition'] = 'skipped';
            $entry['item']['_fulltext']['status'] = 'retry-exhausted';
            $entry['item']['_fulltext']['message'] = feedpublisher_safe_log_text($message . ' The initial attempt and three retries failed; entry marked seen without publishing.');
            feedpublisher_fulltext_retry_clear($feedId, $itemKey);
        } else {
            $entry['state'] = 'deferred_fulltext';
            $entry['item']['_fulltext']['status'] = 'retry-scheduled';
            $entry['item']['_fulltext']['retry_attempts'] = (int) $retry['attempts'];
            $entry['item']['_fulltext']['next_attempt_at'] = (int) $retry['next_attempt_at'];
            $description = $fallback === 'retry_skip'
                ? 'Scheduled retry ' . (int) $retry['attempts'] . ' of 3 after the initial attempt.'
                : 'Scheduled another full-article retry.';
            $entry['item']['_fulltext']['message'] = feedpublisher_safe_log_text($message . ' ' . $description);
        }
    } else {
        feedpublisher_fulltext_retry_clear($feedId, $itemKey);
    }
}

function feedpublisher_fulltext_prepare_plan($feed, $plan)
{
    $mode = isset($feed['fulltext_mode']) ? $feed['fulltext_mode'] : 'disabled';
    if ($mode === 'disabled') {
        if (!empty($feed['id'])) feedpublisher_fulltext_retry_prune((int) $feed['id'], array());
        foreach ($plan as &$entry) $entry['item']['_fulltext'] = array('source' => 'feed', 'status' => 'disabled', 'message' => 'Full-text retrieval is disabled.');
        unset($entry);
        return $plan;
    }
    $threshold = max(100, min(5000, (int) (isset($feed['fulltext_summary_chars']) ? $feed['fulltext_summary_chars'] : 600)));
    $limit = max(1, min(10, (int) (isset($feed['fulltext_max_per_run']) ? $feed['fulltext_max_per_run'] : 3)));
    $attempted = 0;
    $seenItemKeys = array();
    foreach ($plan as &$entry) {
        $item =& $entry['item'];
        $identity = feedpublisher_derive_item_identity($feed, $item);
        if ($identity['key'] !== '') $seenItemKeys[] = $identity['key'];
        $item['_identity_override'] = $identity;
        $plainLength = my_strlen(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['content']))));
        $partialFeedContent = feedpublisher_feed_content_looks_partial((string) $item['content'], $threshold);
        $needs = $entry['state'] === 'queued' && ($mode === 'always' || $partialFeedContent);
        if (!$needs) {
            feedpublisher_fulltext_retry_clear(isset($feed['id']) ? (int) $feed['id'] : 0, $identity['key']);
            $item['_fulltext'] = array('source' => 'feed', 'status' => 'not-needed', 'message' => 'Feed content retained (' . $plainLength . ' text characters).');
            unset($item);
            continue;
        }
        if (feedpublisher_fulltext_item_known($feed, $item, $identity)) {
            feedpublisher_fulltext_retry_clear(isset($feed['id']) ? (int) $feed['id'] : 0, $identity['key']);
            $item['_fulltext'] = array('source' => 'feed', 'status' => 'known', 'message' => 'Entry already exists; linked article was not fetched.');
            unset($item);
            continue;
        }
        $retry = feedpublisher_fulltext_retry_state(isset($feed['id']) ? (int) $feed['id'] : 0, $identity['key']);
        if ($retry && (int) $retry['next_attempt_at'] > TIME_NOW) {
            $entry['state'] = 'deferred_fulltext';
            $item['_fulltext'] = array(
                'source' => 'feed', 'status' => 'retry-wait', 'retry_attempts' => (int) $retry['attempts'],
                'next_attempt_at' => (int) $retry['next_attempt_at'],
                'message' => 'Waiting for the saved per-entry full-article retry time.',
            );
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
            $rewrite = array();
            $fetchUrl = feedpublisher_fulltext_rewrite_url($feed, $item['url'], $rewrite);
            $item['_fulltext'] = array('url' => $rewrite);
            $fetch = array();
            $html = feedpublisher_fulltext_fetch($fetchUrl, $fetch);
            $extract = array();
            $content = feedpublisher_fulltext_extract($html, $fetchUrl, $extract);
            $item['content'] = $content;
            feedpublisher_fulltext_retry_clear(isset($feed['id']) ? (int) $feed['id'] : 0, $identity['key']);
            if (!empty($rewrite['rewritten'])) $item['source_url'] = $fetchUrl;
            $item['_fulltext'] = array('source' => 'fulltext', 'status' => 'extracted', 'message' => 'Extracted linked article.',
                'url' => $rewrite, 'fetch' => $fetch, 'extract' => $extract, 'feed_characters' => $plainLength);
        } catch (Throwable $exception) {
            feedpublisher_fulltext_failure($feed, $entry, $exception->getMessage(), $identity);
        }
        unset($item);
    }
    unset($entry);
    if (!empty($feed['id'])) feedpublisher_fulltext_retry_prune((int) $feed['id'], $seenItemKeys);
    return $plan;
}
