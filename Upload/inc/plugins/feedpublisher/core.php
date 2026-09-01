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
