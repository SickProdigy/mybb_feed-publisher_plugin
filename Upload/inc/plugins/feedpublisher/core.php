<?php
/**
 * Feed fetching, parsing, and conversion helpers.
 * Copyright (c) 2026 SickProdigy
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!defined('IN_MYBB')) {
    die('Direct access is not allowed.');
}

function feedpublisher_validate_url($url)
{
    $parts = parse_url($url);
    if (!$parts || empty($parts['host']) || empty($parts['scheme'])) {
        return false;
    }

    if (!in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
        return false;
    }

    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }

    $addresses = gethostbynamel($parts['host']);
    if (!$addresses) {
        return false;
    }

    foreach ($addresses as $address) {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
    }

    return true;
}

function feedpublisher_fetch($url, $maxBytes = 2097152)
{
    if (!feedpublisher_validate_url($url)) {
        throw new RuntimeException('The feed URL is invalid or resolves to a non-public address.');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required.');
    }

    $body = '';
    $curl = curl_init($url);
    curl_setopt_array($curl, array(
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'MyBB Feed Publisher/0.1',
        CURLOPT_HTTPHEADER => array('Accept: application/rss+xml, application/atom+xml, application/xml, text/xml'),
        CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body, $maxBytes) {
            if (strlen($body) + strlen($chunk) > $maxBytes) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ));

    $ok = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($ok === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('Feed request failed' . ($error ? ': ' . $error : ' with HTTP ' . $status) . '.');
    }

    return $body;
}

function feedpublisher_parse($xml)
{
    $previous = libxml_use_internal_errors(true);
    $document = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($document === false) {
        throw new RuntimeException('The response is not valid XML.');
    }

    $items = array();
    if (isset($document->channel->item)) {
        foreach ($document->channel->item as $item) {
            $content = $item->children('http://purl.org/rss/1.0/modules/content/');
            $items[] = array(
                'key' => trim((string) ($item->guid ?: $item->link)),
                'title' => trim((string) $item->title),
                'url' => trim((string) $item->link),
                'content' => (string) ($content->encoded ?: $item->description),
                'published' => strtotime((string) $item->pubDate) ?: 0,
            );
        }
    } else {
        $document->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
        foreach ($document->xpath('//atom:entry') ?: array() as $entry) {
            $link = '';
            foreach ($entry->link as $candidate) {
                $attributes = $candidate->attributes();
                if (!$link || (string) $attributes['rel'] === 'alternate') {
                    $link = (string) $attributes['href'];
                }
            }
            $items[] = array(
                'key' => trim((string) ($entry->id ?: $link)),
                'title' => trim((string) $entry->title),
                'url' => trim($link),
                'content' => (string) ($entry->content ?: $entry->summary),
                'published' => strtotime((string) ($entry->published ?: $entry->updated)) ?: 0,
            );
        }
    }

    return array_values(array_filter($items, function ($item) {
        return $item['key'] !== '' && $item['title'] !== '';
    }));
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
