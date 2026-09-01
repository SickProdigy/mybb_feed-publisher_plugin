<?php
/**
 * Feed fetching, parsing, and conversion helpers.
 * Copyright (c) 2026 SickProdigy
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!defined('IN_MYBB')) {
    die('Direct access is not allowed.');
}

class FeedPublisherException extends RuntimeException
{
    private $stage;

    public function __construct($stage, $message)
    {
        $this->stage = $stage;
        parent::__construct($message);
    }

    public function getStage()
    {
        return $this->stage;
    }
}

function feedpublisher_safe_log_text($text)
{
    $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $text);
    $text = preg_replace('/\s+/u', ' ', trim($text));
    return substr($text, 0, 1000);
}

function feedpublisher_safe_diagnostic_text($text, $redactUrls = false)
{
    $text = feedpublisher_safe_log_text($text);
    $text = preg_replace('/\b(authorization|cookie|token|password|secret|api[_ -]?key)\s*[:=]\s*[^\s;,]+/i', '$1=[redacted]', $text);
    if ($redactUrls) {
        $text = preg_replace('~https?://[^\s<>]+~i', '[redacted URL]', $text);
    } else {
        $text = preg_replace('~(https?://)[^/@\s]+@~i', '$1[redacted]@', $text);
    }
    return substr($text, 0, 1000);
}

function feedpublisher_safe_report_url($url)
{
    $parts = parse_url((string) $url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) { return '[invalid URL]'; }
    $safe = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
    if (isset($parts['port'])) { $safe .= ':' . (int) $parts['port']; }
    return $safe . (isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/');
}

function feedpublisher_log_event($feedId, $stage, $severity, $message)
{
    global $db;
    if (!$db->table_exists('feedpublisher_logs')) { return; }
    $stage = in_array($stage, array('task', 'fetch', 'content-type', 'parse', 'discovery', 'publication', 'cleanup', 'general'), true) ? $stage : 'general';
    $severity = in_array($severity, array('info', 'warning', 'error'), true) ? $severity : 'info';
    $db->insert_query('feedpublisher_logs', array('feed_id' => max(0, (int) $feedId), 'created_at' => TIME_NOW,
        'stage' => $db->escape_string($stage), 'severity' => $db->escape_string($severity),
        'message' => $db->escape_string(feedpublisher_safe_diagnostic_text($message))));
}

function feedpublisher_log_prune($limit = 100)
{
    global $db;
    if (!$db->table_exists('feedpublisher_logs')) { return 0; }
    $limit = max(1, min(100, (int) $limit));
    $cutoff = TIME_NOW - 30 * 86400;
    $ids = array();
    $query = $db->simple_select('feedpublisher_logs', 'id', 'created_at<' . $cutoff, array('order_by' => 'created_at', 'order_dir' => 'ASC', 'limit' => $limit));
    while ($row = $db->fetch_array($query)) { $ids[(int) $row['id']] = (int) $row['id']; }
    if (count($ids) < $limit) {
        $total = (int) $db->fetch_field($db->simple_select('feedpublisher_logs', 'COUNT(id) AS total'), 'total');
        $overflow = max(0, $total - 1000);
        if ($overflow) {
            $query = $db->simple_select('feedpublisher_logs', 'id', '', array('order_by' => 'created_at,id', 'order_dir' => 'ASC', 'limit' => min($overflow, $limit - count($ids))));
            while ($row = $db->fetch_array($query)) { $ids[(int) $row['id']] = (int) $row['id']; }
        }
    }
    if ($ids) { $db->delete_query('feedpublisher_logs', 'id IN (' . implode(',', $ids) . ')'); }
    return count($ids);
}

function feedpublisher_resolve_url($url)
{
    $parts = parse_url($url);
    if (!$parts || empty($parts['host']) || empty($parts['scheme'])
        || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)
        || isset($parts['user']) || isset($parts['pass'])) {
        throw new FeedPublisherException('validation', 'The feed URL must be a public HTTP or HTTPS URL without credentials.');
    }

    $host = trim($parts['host'], '[]');
    $parts['host'] = $host;
    $addresses = array();
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $addresses[] = $host;
    } else {
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: array() as $record) {
            if (!empty($record['ip'])) {
                $addresses[] = $record['ip'];
            } elseif (!empty($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }
    }
    $addresses = array_values(array_unique($addresses));
    if (!$addresses) {
        throw new FeedPublisherException('dns', 'The feed hostname did not resolve.');
    }
    foreach ($addresses as $address) {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new FeedPublisherException('dns', 'The feed hostname resolves to a disallowed address.');
        }
    }
    return array('parts' => $parts, 'addresses' => $addresses);
}

function feedpublisher_validate_url($url)
{
    try {
        feedpublisher_resolve_url($url);
        return true;
    } catch (FeedPublisherException $exception) {
        return false;
    }
}

function feedpublisher_fetch($url, $maxBytes = 2097152, &$metadata = null)
{
    $body = feedpublisher_fetch_resource($url, $maxBytes, 'application/rss+xml, application/atom+xml, application/xml, text/xml', $metadata);
    $mediaType = $metadata['content_type'];
    $acceptedTypes = array('application/rss+xml', 'application/atom+xml', 'application/rdf+xml', 'application/xml', 'text/xml');
    $looksLikeXml = preg_match('/^(?:\xEF\xBB\xBF)?\s*(?:<\?xml\b|<(?:rss|feed|rdf:RDF)\b)/i', $body) === 1;
    if (!in_array($mediaType, $acceptedTypes, true) && !$looksLikeXml) {
        throw new FeedPublisherException('content-type', 'The feed response did not use an accepted XML content type.');
    }
    $metadata['content_type_fallback'] = !in_array($mediaType, $acceptedTypes, true);
    return $body;
}

function feedpublisher_fetch_resource($url, $maxBytes, $accept, &$metadata = null)
{
    if (!function_exists('curl_init')) {
        throw new FeedPublisherException('fetch', 'The PHP cURL extension is required.');
    }
    $resolved = feedpublisher_resolve_url($url);
    $parts = $resolved['parts'];
    $scheme = strtolower($parts['scheme']);
    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
    $address = $resolved['addresses'][0];
    $pinned = strpos($address, ':') !== false ? '[' . $address . ']' : $address;

    $body = '';
    $tooLarge = false;
    $curl = curl_init($url);
    curl_setopt_array($curl, array(
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_RESOLVE => array($parts['host'] . ':' . $port . ':' . $pinned),
        CURLOPT_USERAGENT => 'MyBB Feed Publisher/0.1',
        CURLOPT_HTTPHEADER => array('Accept: ' . $accept),
        CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body, &$tooLarge, $maxBytes) {
            if (strlen($body) + strlen($chunk) > $maxBytes) {
                $tooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ));

    $ok = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $contentType = strtolower(trim((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE)));
    $error = curl_error($curl);
    curl_close($curl);

    $mediaType = trim(strtok($contentType, ';'));
    $charset = '';
    if (preg_match('/(?:^|;)\s*charset\s*=\s*["\']?([^;"\']+)/i', $contentType, $match)) {
        $charset = trim($match[1]);
    }
    $metadata = array('url' => $url, 'http_status' => $status, 'content_type' => $mediaType,
        'http_charset' => $charset, 'redirects' => ($status >= 300 && $status < 400) ? 1 : 0);

    if ($tooLarge) {
        throw new FeedPublisherException('fetch', 'The remote response exceeded the configured size limit.');
    }
    if ($status >= 300 && $status < 400) {
        throw new FeedPublisherException('fetch', 'Feed redirects are not allowed.');
    }
    if ($ok === false || $status < 200 || $status >= 300) {
        throw new FeedPublisherException('fetch', 'The feed request failed' . ($error ? ': ' . $error : ' with HTTP ' . $status) . '.');
    }
    return $body;
}

function feedpublisher_discover_declared_feeds($url)
{
    $metadata = array();
    $html = feedpublisher_fetch_resource($url, 1048576, 'text/html, application/xhtml+xml', $metadata);
    if (!in_array($metadata['content_type'], array('text/html', 'application/xhtml+xml'), true)) {
        throw new FeedPublisherException('content-type', 'The website response was not HTML.');
    }
    return array('page' => $metadata, 'candidates' => feedpublisher_extract_declared_feeds($html, $url));
}

function feedpublisher_extract_declared_feeds($html, $url)
{
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $loaded = $document->loadHTML($html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        throw new FeedPublisherException('parse', 'The website HTML could not be inspected.');
    }
    $base = $url;
    $baseNodes = $document->getElementsByTagName('base');
    if ($baseNodes->length) {
        $declaredBase = feedpublisher_resolve_relative_content_url($baseNodes->item(0)->getAttribute('href'), $url);
        if ($declaredBase !== '') {
            $base = $declaredBase;
        }
    }
    $candidates = array();
    foreach ($document->getElementsByTagName('link') as $link) {
        $relations = preg_split('/\s+/', strtolower(trim($link->getAttribute('rel'))));
        $type = strtolower(trim(strtok($link->getAttribute('type'), ';')));
        if (!in_array('alternate', $relations, true)
            || !in_array($type, array('application/rss+xml', 'application/atom+xml', 'application/rdf+xml'), true)) {
            continue;
        }
        $candidate = feedpublisher_resolve_relative_content_url($link->getAttribute('href'), $base);
        if ($candidate !== '' && !isset($candidates[$candidate])) {
            $candidates[$candidate] = array('url' => $candidate, 'declared_title' => trim($link->getAttribute('title')));
        }
        if (count($candidates) >= 20) {
            break;
        }
    }
    return array_values($candidates);
}

function feedpublisher_test_feed_connection($url)
{
    $fetch = array();
    try {
        $xml = feedpublisher_fetch($url, 2097152, $fetch);
        $parse = array();
        $items = feedpublisher_parse($xml, $fetch, $parse);
        $newest = 0;
        foreach ($items as $item) {
            $newest = max($newest, (int) $item['published']);
        }
        return array('ok' => true, 'stage' => 'complete', 'fetch' => $fetch, 'parse' => $parse,
            'items' => count($items), 'newest' => $newest, 'error' => '');
    } catch (Throwable $exception) {
        return array('ok' => false, 'stage' => $exception instanceof FeedPublisherException ? $exception->getStage() : 'unknown',
            'fetch' => $fetch, 'parse' => array(), 'items' => 0, 'newest' => 0,
            'error' => feedpublisher_safe_log_text($exception->getMessage()));
    }
}

function feedpublisher_parse($xml, $fetchMetadata = array(), &$parseMetadata = null)
{
    if (strlen($xml) > 2097152) {
        throw new FeedPublisherException('parse', 'The XML document exceeded the 2 MiB size limit.');
    }
    if (stripos($xml, '<!DOCTYPE') !== false) {
        throw new FeedPublisherException('parse', 'XML document type declarations are not allowed.');
    }
    $encoding = '';
    $xml = feedpublisher_normalize_xml_encoding($xml, isset($fetchMetadata['http_charset']) ? $fetchMetadata['http_charset'] : '', $encoding);
    if (stripos($xml, '<!DOCTYPE') !== false) {
        throw new FeedPublisherException('parse', 'XML document type declarations are not allowed.');
    }

    $previous = libxml_use_internal_errors(true);
    $document = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($document === false) {
        throw new FeedPublisherException('parse', 'The response is not valid XML after encoding normalization.');
    }

    $rootNode = dom_import_simplexml($document);
    $nodeCount = 0;
    if (!$rootNode || !feedpublisher_xml_within_limits($rootNode, 0, $nodeCount)) {
        throw new FeedPublisherException('parse', 'The XML structure exceeded the depth or node limit.');
    }
    $format = feedpublisher_detect_feed_format($rootNode);
    if ($format === '') {
        throw new FeedPublisherException('parse', 'The XML document is not a supported RSS, RDF, or Atom feed.');
    }
    $parseMetadata = array(
        'format' => $format,
        'encoding' => $encoding,
        'content_type_fallback' => !empty($fetchMetadata['content_type_fallback']),
    );

    $xpath = new DOMXPath($rootNode->ownerDocument);
    $titleNodes = $xpath->query('/*[local-name()="feed"]/*[local-name()="title"] | /*[local-name()="rss"]/*[local-name()="channel"]/*[local-name()="title"] | /*[local-name()="RDF"]/*[local-name()="channel"]/*[local-name()="title"]');
    $parseMetadata['title'] = $titleNodes->length ? trim($titleNodes->item(0)->textContent) : '';
    $query = $format === 'Atom' ? '//*[local-name()="entry"]' : '//*[local-name()="item"]';
    $items = array();
    foreach ($xpath->query($query) as $entry) {
        $base = feedpublisher_dom_xml_base($entry, isset($fetchMetadata['url']) ? $fetchMetadata['url'] : '');
        if ($format === 'Atom') {
            $link = '';
            $linkNode = null;
            foreach ($xpath->query('./*[local-name()="link"]', $entry) as $candidate) {
                $relation = strtolower(trim($candidate->getAttribute('rel')));
                if ($relation === 'enclosure') {
                    continue;
                }
                if ($link === '' || $relation === '' || $relation === 'alternate') {
                    $link = $candidate->getAttribute('href');
                    $linkNode = $candidate;
                    if ($relation === '' || $relation === 'alternate') {
                        break;
                    }
                }
            }
            $linkBase = $linkNode instanceof DOMElement
                ? feedpublisher_dom_xml_base($linkNode, $base)
                : $base;
            $link = feedpublisher_resolve_relative_content_url($link, $linkBase);
            $key = feedpublisher_dom_first_text($entry, array('id'));
            $title = feedpublisher_dom_first_text($entry, array('title'));
            $content = feedpublisher_dom_first_text($entry, array('content', 'summary'));
            $date = feedpublisher_dom_first_text($entry, array('published', 'updated'));
        } else {
            $link = feedpublisher_resolve_relative_content_url(feedpublisher_dom_first_text($entry, array('link')), $base);
            $key = feedpublisher_dom_first_text($entry, array('guid', 'identifier'));
            if ($key === '' && $entry->hasAttributeNS('http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'about')) {
                $key = $entry->getAttributeNS('http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'about');
            }
            $title = feedpublisher_dom_first_text($entry, array('title'));
            $content = feedpublisher_dom_first_text($entry, array('encoded', 'description', 'content'));
            $date = feedpublisher_dom_first_text($entry, array('pubDate', 'date', 'published', 'updated'));
        }
        if ($key === '') {
            $key = $link;
        }
        $categories = array();
        foreach ($xpath->query('./*[local-name()="category"]', $entry) as $categoryNode) {
            $category = $categoryNode->hasAttribute('term') ? $categoryNode->getAttribute('term') : $categoryNode->textContent;
            $category = trim($category);
            if ($category !== '') { $categories[] = $category; }
        }
        $hasMedia = false;
        foreach ($xpath->query('.//*', $entry) as $contentNode) {
            $localName = strtolower($contentNode->localName);
            if (in_array($localName, array('enclosure', 'image', 'thumbnail'), true)
                || ($contentNode->namespaceURI === 'http://search.yahoo.com/mrss/' && $localName === 'content')) {
                $hasMedia = true;
                break;
            }
        }
        if (!$hasMedia && $format === 'Atom') {
            foreach ($xpath->query('./*[local-name()="link"]', $entry) as $candidate) {
                if (strtolower(trim($candidate->getAttribute('rel'))) === 'enclosure') { $hasMedia = true; break; }
            }
        }
        $items[] = array(
            'key' => trim($key),
            'title' => trim($title),
            'url' => trim($link),
            'content' => $content,
            'published' => feedpublisher_parse_source_date($date),
            'author' => trim(feedpublisher_dom_first_text($entry, array('author', 'creator'))),
            'categories' => array_values(array_unique($categories)),
            'has_media' => $hasMedia,
        );
    }

    $items = array_values(array_filter($items, function ($item) {
        return $item['title'] !== '' && ($item['key'] !== '' || trim($item['content']) !== '');
    }));
    if (count($items) > 1000) {
        throw new FeedPublisherException('parse', 'The feed contains more than 1,000 entries.');
    }
    return $items;
}

function feedpublisher_canonical_encoding($encoding)
{
    $encoding = strtoupper(str_replace(array('_', ' '), '-', trim((string) $encoding)));
    $aliases = array('UTF8' => 'UTF-8', 'UTF-16' => 'UTF-16', 'UTF16' => 'UTF-16', 'UTF16LE' => 'UTF-16LE',
        'UTF16BE' => 'UTF-16BE', 'ISO8859-1' => 'ISO-8859-1', 'LATIN1' => 'ISO-8859-1',
        'WINDOWS1252' => 'WINDOWS-1252', 'CP1252' => 'WINDOWS-1252');
    return isset($aliases[$encoding]) ? $aliases[$encoding] : $encoding;
}

function feedpublisher_normalize_xml_encoding($xml, $httpEncoding = '', &$detectedEncoding = '')
{
    $bomEncoding = '';
    if (substr($xml, 0, 3) === "\xEF\xBB\xBF") {
        $bomEncoding = 'UTF-8';
        $xml = substr($xml, 3);
    } elseif (substr($xml, 0, 2) === "\xFF\xFE") {
        $bomEncoding = 'UTF-16LE';
        $xml = substr($xml, 2);
    } elseif (substr($xml, 0, 2) === "\xFE\xFF") {
        $bomEncoding = 'UTF-16BE';
        $xml = substr($xml, 2);
    }
    if ($bomEncoding === 'UTF-16LE' || $bomEncoding === 'UTF-16BE') {
        $xml = feedpublisher_convert_to_utf8($xml, $bomEncoding);
    }
    $declared = '';
    if (preg_match('/^\s*<\?xml\b[^>]*\bencoding\s*=\s*["\']([^"\']+)["\']/i', $xml, $match)) {
        $declared = feedpublisher_canonical_encoding($match[1]);
    }
    $httpEncoding = feedpublisher_canonical_encoding($httpEncoding);
    $bomEncoding = feedpublisher_canonical_encoding($bomEncoding);
    $supported = array('', 'UTF-8', 'UTF-16', 'UTF-16LE', 'UTF-16BE', 'ISO-8859-1', 'WINDOWS-1252');
    foreach (array($bomEncoding, $declared, $httpEncoding) as $candidate) {
        if (!in_array($candidate, $supported, true)) {
            throw new FeedPublisherException('parse', 'The feed declares an unsupported character encoding.');
        }
    }
    $encodingsAgree = function ($left, $right) {
        if ($left === '' || $right === '' || $left === $right) {
            return true;
        }
        return ($left === 'UTF-16' && in_array($right, array('UTF-16LE', 'UTF-16BE'), true))
            || ($right === 'UTF-16' && in_array($left, array('UTF-16LE', 'UTF-16BE'), true));
    };
    if (!$encodingsAgree($bomEncoding, $declared)
        || !$encodingsAgree($bomEncoding, $httpEncoding)
        || !$encodingsAgree($declared, $httpEncoding)) {
        throw new FeedPublisherException('parse', 'The HTTP, BOM, and XML character encodings conflict.');
    }
    $sourceEncoding = $bomEncoding ?: ($declared ?: ($httpEncoding ?: 'UTF-8'));
    if ($sourceEncoding !== 'UTF-8' && $bomEncoding === '') {
        $xml = feedpublisher_convert_to_utf8($xml, $sourceEncoding);
    }
    if (preg_match('//u', $xml) !== 1) {
        throw new FeedPublisherException('parse', 'The feed contains invalid text for its declared encoding.');
    }
    $xml = preg_replace('/^(\s*<\?xml\b[^>]*\bencoding\s*=\s*)["\'][^"\']+["\']/i', '$1"UTF-8"', $xml, 1);
    $detectedEncoding = $sourceEncoding;
    return $xml;
}

function feedpublisher_convert_to_utf8($value, $sourceEncoding)
{
    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($value, 'UTF-8', $sourceEncoding);
    } elseif (function_exists('iconv')) {
        $converted = @iconv($sourceEncoding, 'UTF-8', $value);
    } else {
        throw new FeedPublisherException('parse', 'Converting this feed encoding requires mbstring or iconv.');
    }
    if ($converted === false) {
        throw new FeedPublisherException('parse', 'The feed character encoding could not be converted to UTF-8.');
    }
    return $converted;
}

function feedpublisher_detect_feed_format(DOMElement $root)
{
    $name = strtolower($root->localName);
    if ($name === 'feed' && $root->namespaceURI === 'http://www.w3.org/2005/Atom') {
        return 'Atom';
    }
    if ($name === 'rdf' && $root->namespaceURI === 'http://www.w3.org/1999/02/22-rdf-syntax-ns#') {
        return 'RSS 1.0 (RDF)';
    }
    if ($name === 'rss') {
        $version = trim($root->getAttribute('version'));
        return $version !== '' ? 'RSS ' . $version : 'RSS';
    }
    return '';
}

function feedpublisher_dom_first_text(DOMElement $parent, $localNames)
{
    foreach ($localNames as $name) {
        foreach ($parent->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && strcasecmp($child->localName, $name) === 0) {
                return trim($child->textContent);
            }
        }
    }
    return '';
}

function feedpublisher_dom_xml_base(DOMNode $node, $fallback)
{
    $bases = array();
    for ($current = $node; $current instanceof DOMElement; $current = $current->parentNode) {
        if ($current->hasAttributeNS('http://www.w3.org/XML/1998/namespace', 'base')) {
            array_unshift($bases, $current->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'base'));
        }
    }
    $base = $fallback;
    foreach ($bases as $candidate) {
        $base = feedpublisher_resolve_relative_content_url($candidate, $base);
    }
    return $base;
}

function feedpublisher_resolve_relative_content_url($url, $base)
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    if ($parts === false) {
        return '';
    }
    if (!empty($parts['scheme'])) {
        return feedpublisher_safe_content_url($url) ? $url : '';
    }
    $baseParts = parse_url($base);
    if (!$baseParts || empty($baseParts['scheme']) || empty($baseParts['host'])) {
        return '';
    }
    if (strpos($url, '//') === 0) {
        return $baseParts['scheme'] . ':' . $url;
    }
    $origin = $baseParts['scheme'] . '://' . $baseParts['host'];
    if (isset($baseParts['port'])) {
        $origin .= ':' . (int) $baseParts['port'];
    }
    if (!empty($parts['host'])) {
        return '';
    }
    $relativePath = isset($parts['path']) ? $parts['path'] : '';
    if ($relativePath === '') {
        $path = isset($baseParts['path']) ? $baseParts['path'] : '/';
    } else {
        $path = isset($relativePath[0]) && $relativePath[0] === '/'
            ? $relativePath
            : rtrim(dirname(isset($baseParts['path']) ? $baseParts['path'] : '/'), '/') . '/' . $relativePath;
    }
    $segments = array();
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') { continue; }
        if ($segment === '..') { array_pop($segments); continue; }
        $segments[] = $segment;
    }
    $resolved = $origin . '/' . implode('/', $segments);
    if (array_key_exists('query', $parts)) {
        $resolved .= '?' . $parts['query'];
    } elseif ($relativePath === '' && isset($baseParts['query'])) {
        $resolved .= '?' . $baseParts['query'];
    }
    if (array_key_exists('fragment', $parts)) {
        $resolved .= '#' . $parts['fragment'];
    }
    return feedpublisher_safe_content_url($resolved) ? $resolved : '';
}

function feedpublisher_parse_source_date($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return max(0, $date->getTimestamp());
    } catch (Throwable $exception) {
        return 0;
    }
}

function feedpublisher_xml_within_limits(DOMNode $node, $depth, &$nodeCount)
{
    if ($depth > 64 || ++$nodeCount > 20000) {
        return false;
    }
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_ELEMENT_NODE
            && !feedpublisher_xml_within_limits($child, $depth + 1, $nodeCount)) {
            return false;
        }
    }
    return true;
}

function feedpublisher_normalize_item_identity($identity)
{
    $identity = preg_replace('/\s+/u', ' ', trim((string) $identity));
    $parts = parse_url($identity);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])
        || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
        return $identity;
    }

    $scheme = strtolower($parts['scheme']);
    $host = strtolower($parts['host']);
    $port = isset($parts['port']) && !(($scheme === 'http' && $parts['port'] === 80)
        || ($scheme === 'https' && $parts['port'] === 443))
        ? ':' . $parts['port']
        : '';
    $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

    return $scheme . '://' . $host . $port . $path . $query;
}

function feedpublisher_item_key($identity)
{
    return hash('sha256', feedpublisher_normalize_item_identity($identity));
}

function feedpublisher_normalize_identity_text($value)
{
    $value = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/\s+/u', ' ', trim($value));
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function feedpublisher_derive_item_identity($feed, $item)
{
    $strategy = isset($feed['identity_strategy']) ? $feed['identity_strategy'] : 'guid_link';
    $title = feedpublisher_normalize_identity_text(isset($item['title']) ? $item['title'] : '');
    $content = feedpublisher_normalize_identity_text(isset($item['content']) ? $item['content'] : '');
    if ($strategy === 'title') {
        $identity = 'fp-title-v1|' . $title;
        $basis = 'normalized title (v1)';
    } elseif ($strategy === 'content') {
        $identity = 'fp-content-v1|' . hash('sha256', $content);
        $basis = 'normalized content fingerprint (v1)';
    } elseif ($strategy === 'title_content') {
        $identity = 'fp-title-content-v1|' . $title . '|' . hash('sha256', $content);
        $basis = 'normalized title and content fingerprint (v1)';
    } else {
        $strategy = 'guid_link';
        $identity = isset($item['key']) ? $item['key'] : '';
        $basis = 'normalized GUID or link';
    }
    if ($identity === '' || ($strategy !== 'guid_link' && $title === '' && $content === '')) {
        return array('strategy' => $strategy, 'identity' => '', 'key' => '', 'basis' => $basis);
    }
    return array('strategy' => $strategy, 'identity' => $identity, 'key' => feedpublisher_item_key($identity), 'basis' => $basis);
}

function feedpublisher_eligibility_rules($rules, &$errors = array())
{
    $parsed = array();
    $lines = feedpublisher_cleanup_rule_lines($rules);
    if (count($lines) > 50) { $errors[] = 'Eligibility filters are limited to 50 rules.'; return array(); }
    $regexCount = 0;
    foreach ($lines as $index => $line) {
        if (strlen($line) > 500 || !preg_match('/^(include|exclude)(-regex)?\s+(title|url|category|body)\s*:\s*(.+)$/i', $line, $match)) {
            $errors[] = 'Eligibility rule ' . ($index + 1) . ' must use: include|exclude [-regex] title|url|category|body: value.';
            continue;
        }
        $regex = $match[2] !== '';
        if ($regex && (++$regexCount > 20 || @preg_match($match[4], '') === false)) {
            $errors[] = 'Eligibility rule ' . ($index + 1) . ' contains an invalid regex or exceeds the 20-regex limit.';
            continue;
        }
        $parsed[] = array('action' => strtolower($match[1]), 'regex' => $regex, 'field' => strtolower($match[3]),
            'value' => $match[4], 'label' => $line);
    }
    return $parsed;
}

function feedpublisher_entry_eligibility($feed, $item, $now = null)
{
    $now = $now === null ? TIME_NOW : (int) $now;
    if (!empty($feed['require_entry_body']) && trim(strip_tags((string) $item['content'])) === '') {
        return array('eligible' => false, 'reason' => 'Required body content is missing.');
    }
    if (!empty($feed['require_entry_media']) && empty($item['has_media'])) {
        return array('eligible' => false, 'reason' => 'Required image or media item is missing.');
    }
    $published = isset($item['published']) ? (int) $item['published'] : 0;
    $minimumHours = isset($feed['minimum_source_age_hours']) ? (int) $feed['minimum_source_age_hours'] : 0;
    $maximumDays = isset($feed['maximum_source_age_days']) ? (int) $feed['maximum_source_age_days'] : 0;
    if (($minimumHours > 0 || $maximumDays > 0) && $published <= 0) {
        return array('eligible' => false, 'reason' => 'A source date is required by the configured age filter.');
    }
    if ($minimumHours > 0 && $published > $now - $minimumHours * 3600) {
        return array('eligible' => false, 'reason' => 'Entry is newer than the minimum source age of ' . $minimumHours . ' hours.');
    }
    if ($maximumDays > 0 && $published < $now - $maximumDays * 86400) {
        return array('eligible' => false, 'reason' => 'Entry is older than the maximum source age of ' . $maximumDays . ' days.');
    }
    $errors = array();
    $rules = feedpublisher_eligibility_rules(isset($feed['eligibility_rules']) ? $feed['eligibility_rules'] : '', $errors);
    if ($errors) { return array('eligible' => false, 'reason' => $errors[0]); }
    $fields = array('title' => (string) $item['title'], 'url' => (string) $item['url'],
        'category' => implode(' ', isset($item['categories']) ? $item['categories'] : array()), 'body' => (string) $item['content']);
    $includeRules = 0;
    $includeMatched = false;
    foreach ($rules as $rule) {
        $haystack = $fields[$rule['field']];
        $matched = $rule['regex'] ? preg_match($rule['value'], $haystack) === 1
            : (function_exists('mb_stripos') ? mb_stripos($haystack, $rule['value'], 0, 'UTF-8') !== false : stripos($haystack, $rule['value']) !== false);
        if ($rule['action'] === 'exclude' && $matched) {
            return array('eligible' => false, 'reason' => 'Matched exclusion rule: ' . $rule['label']);
        }
        if ($rule['action'] === 'include') { ++$includeRules; $includeMatched = $includeMatched || $matched; }
    }
    if ($includeRules > 0 && !$includeMatched) {
        return array('eligible' => false, 'reason' => 'No configured inclusion rule matched.');
    }
    return array('eligible' => true, 'reason' => $includeRules ? 'Matched an inclusion rule and no exclusion rule.' : 'No eligibility rule rejected this entry.');
}

function feedpublisher_cleanup_rule_lines($rules)
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $rules)), 'strlen'));
}

function feedpublisher_cleanup_selector_xpath($selector)
{
    if (!preg_match('/^(?:(?<tag>[a-z][a-z0-9-]*))?(?:(?<class>\.[a-z_][a-z0-9_-]*)|(?<id>#[a-z_][a-z0-9_-]*)|\[(?<attr>[a-z_:][a-z0-9_:-]*)\])?$/i', $selector, $match)
        || (empty($match['tag']) && empty($match['class']) && empty($match['id']) && empty($match['attr']))) {
        return false;
    }

    $xpath = '//' . (!empty($match['tag']) ? strtolower($match['tag']) : '*');
    if (!empty($match['class'])) {
        $class = substr($match['class'], 1);
        $xpath .= "[contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')]";
    } elseif (!empty($match['id'])) {
        $xpath .= "[@id='" . substr($match['id'], 1) . "']";
    } elseif (!empty($match['attr'])) {
        $xpath .= '[@' . $match['attr'] . ']';
    }
    return $xpath;
}

function feedpublisher_cleanup_validate_rules($selectors, $regexes)
{
    $errors = array();
    $selectorLines = feedpublisher_cleanup_rule_lines($selectors);
    $regexLines = feedpublisher_cleanup_rule_lines($regexes);
    if (count($selectorLines) > 50) {
        $errors[] = 'Cleanup selectors are limited to 50 rules.';
    }
    if (count($regexLines) > 20) {
        $errors[] = 'Cleanup regular expressions are limited to 20 rules.';
    }
    foreach ($selectorLines as $selector) {
        if (strlen($selector) > 100 || feedpublisher_cleanup_selector_xpath($selector) === false) {
            $errors[] = 'Invalid cleanup selector: ' . $selector;
        }
    }
    foreach ($regexLines as $regex) {
        if (strlen($regex) > 500 || @preg_match($regex, '') === false) {
            $errors[] = 'Invalid cleanup regular expression: ' . $regex;
        }
    }
    return $errors;
}

function feedpublisher_cleanup_remove_nodes(DOMXPath $xpath, $query)
{
    $nodes = $xpath->query($query);
    if (!$nodes) {
        return;
    }
    for ($index = $nodes->length - 1; $index >= 0; --$index) {
        $node = $nodes->item($index);
        if ($node instanceof DOMElement && $node->getAttribute('id') === 'feedpublisher-root') {
            continue;
        }
        if ($node && $node->parentNode) {
            $node->parentNode->removeChild($node);
        }
    }
}

function feedpublisher_cleanup_html($html, $feed, $sourceUrl = '')
{
    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><div id="feedpublisher-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);

    $selectors = array_slice(feedpublisher_cleanup_rule_lines(isset($feed['strip_selectors']) ? $feed['strip_selectors'] : ''), 0, 50);
    if (!empty($feed['remove_bylines'])) {
        $selectors = array_merge($selectors, array('.author', '.byline', '.post-author'));
    }
    if (!empty($feed['remove_source_links'])) {
        $selectors = array_merge($selectors, array('.source', '.read-more', '.readmore'));
    }
    foreach (array_unique($selectors) as $selector) {
        $query = feedpublisher_cleanup_selector_xpath($selector);
        if ($query !== false) {
            feedpublisher_cleanup_remove_nodes($xpath, $query);
        }
    }

    if (!empty($feed['remove_bylines'])) {
        feedpublisher_cleanup_remove_nodes($xpath, "//*[@rel='author']");
    }
    if (!empty($feed['remove_source_links']) && $sourceUrl !== '') {
        $links = $xpath->query('//a[@href]');
        if ($links) {
            for ($index = $links->length - 1; $index >= 0; --$index) {
                $link = $links->item($index);
                if (trim($link->getAttribute('href')) !== trim($sourceUrl)) {
                    continue;
                }
                $remove = $link;
                if ($link->parentNode instanceof DOMElement
                    && in_array(strtolower($link->parentNode->nodeName), array('p', 'div'), true)
                    && mb_strlen(trim($link->parentNode->textContent)) <= 250) {
                    $remove = $link->parentNode;
                }
                if ($remove->parentNode) {
                    $remove->parentNode->removeChild($remove);
                }
            }
        }
    }

    $roots = $xpath->query("//*[@id='feedpublisher-root']");
    $root = $roots && $roots->length ? $roots->item(0) : null;
    $cleaned = '';
    if ($root) {
        foreach ($root->childNodes as $child) {
            $cleaned .= $document->saveHTML($child);
        }
    }
    foreach (array_slice(feedpublisher_cleanup_rule_lines(isset($feed['strip_regexes']) ? $feed['strip_regexes'] : ''), 0, 20) as $regex) {
        if (@preg_match($regex, '') !== false) {
            $result = @preg_replace($regex, '', $cleaned);
            if (is_string($result)) {
                $cleaned = $result;
            }
        }
    }
    return $cleaned;
}

function feedpublisher_prepare_item($feed, $item)
{
    $raw = (string) $item['content'];
    $cleaned = feedpublisher_cleanup_html($raw, $feed, isset($item['url']) ? $item['url'] : '');
    return array(
        'raw_html' => $raw,
        'cleaned_html' => $cleaned,
        'content' => feedpublisher_html_to_mycode($cleaned),
        'raw_bytes' => strlen($raw),
        'cleaned_bytes' => strlen($cleaned),
    );
}

function feedpublisher_html_to_mycode($html)
{
    $html = preg_replace('#<(script|style|iframe|object|embed|form)[^>]*>.*?</\\1>#is', '', $html);
    $html = preg_replace('/<!--.*?-->/s', '', $html);

    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $xpath = new DOMXPath($document);
    foreach ($xpath->query('//@*') as $attribute) {
        if (stripos($attribute->nodeName, 'on') === 0 || in_array(strtolower($attribute->nodeName), array('style', 'srcset'), true)) {
            $attribute->ownerElement->removeAttributeNode($attribute);
        }
    }

    $root = $document->getElementsByTagName('div')->item(0);
    $output = '';
    if ($root) {
        foreach ($root->childNodes as $child) {
            $output .= feedpublisher_node_to_mycode($child);
        }
    }

    $output = html_entity_decode($output, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $output = preg_replace("/[ \t]+\n/", "\n", $output);
    $output = preg_replace("/\n{3,}/", "\n\n", $output);
    return trim($output);
}

function feedpublisher_node_to_mycode(DOMNode $node)
{
    if ($node->nodeType === XML_TEXT_NODE) {
        return $node->nodeValue;
    }
    if ($node->nodeType !== XML_ELEMENT_NODE) {
        return '';
    }

    $content = '';
    foreach ($node->childNodes as $child) {
        $content .= feedpublisher_node_to_mycode($child);
    }

    $tag = strtolower($node->nodeName);
    if (in_array($tag, array('strong', 'b'), true)) return '[b]' . $content . '[/b]';
    if (in_array($tag, array('em', 'i'), true)) return '[i]' . $content . '[/i]';
    if ($tag === 'u') return '[u]' . $content . '[/u]';
    if ($tag === 'blockquote') return "[quote]" . trim($content) . "[/quote]\n";
    if ($tag === 'code' || $tag === 'pre') return "[code]" . trim($content) . "[/code]\n";
    if ($tag === 'br') return "\n";
    if (in_array($tag, array('p', 'div', 'section', 'article', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'), true)) return trim($content) . "\n\n";
    if ($tag === 'li') return '[*]' . trim($content) . "\n";
    if ($tag === 'ul') return "[list]\n" . $content . "[/list]\n";
    if ($tag === 'ol') return "[list=1]\n" . $content . "[/list]\n";

    if ($tag === 'a') {
        $url = trim($node->getAttribute('href'));
        return feedpublisher_safe_content_url($url) ? '[url=' . $url . ']' . $content . '[/url]' : $content;
    }
    if ($tag === 'img') {
        $url = trim($node->getAttribute('src'));
        return feedpublisher_safe_content_url($url) ? '[img]' . $url . '[/img]' : '';
    }

    return $content;
}

function feedpublisher_safe_content_url($url)
{
    $parts = parse_url($url);
    return $parts && isset($parts['scheme']) && in_array(strtolower($parts['scheme']), array('http', 'https'), true);
}
