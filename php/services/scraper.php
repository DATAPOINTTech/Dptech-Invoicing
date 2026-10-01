<?php

declare(strict_types=1);

const MAX_ITEMS = 200;

function _to_float($value): ?float
{
    if ($value === null) {
        return null;
    }

    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }

    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }

    if (preg_match('/(?:PKR|Rs\.?|?|?|USD|\$|£|€)\s*([\d][\d,]*(?:\.\d+)?)/i', $text, $matches)) {
        return (float) str_replace(',', '', $matches[1]);
    }

    if (preg_match('/([\d][\d,]*(?:\.\d+)?)/', $text, $matches)) {
        return (float) str_replace(',', '', $matches[1]);
    }

    return null;
}

function _clean_name(?string $text): string
{
    $text = preg_replace('/\s+/', ' ', (string) $text) ?? '';
    return trim(substr($text, 0, 200));
}

function _valid_name(?string $text): bool
{
    if ($text === null || trim($text) === '' || strlen($text) < 2 || strlen($text) > 200) {
        return false;
    }
    if (preg_match('/^[-+%\.\s,\d]+$/', $text)) {
        return false;
    }
    if (preg_match('/\b(there are|there is|products?|showing|results?|no products?|manufacturer|sort by|filter by|wishlist|out of stock|add to cart|read more|view cart)\b/i', $text)) {
        return false;
    }
    if (stripos($text, 'http://') !== false || stripos($text, 'https://') !== false || stripos($text, 'www.') !== false) {
        return false;
    }
    return true;
}

function _extract_json_ld(string $html): array
{
    $items = [];
    if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
        foreach ($matches[1] as $json_text) {
            $decoded = json_decode($json_text, true);
            if (!is_array($decoded)) {
                continue;
            }

            $queue = [$decoded];
            while (!empty($queue)) {
                $node = array_shift($queue);
                if (is_array($node)) {
                    $types = $node['@type'] ?? null;
                    if ($types === 'Product' || (is_array($types) && in_array('Product', $types, true))) {
                        $price = null;
                        if (isset($node['offers'])) {
                            $offers = $node['offers'];
                            if (is_array($offers)) {
                                foreach (['price', 'lowPrice', 'highPrice'] as $key) {
                                    if (isset($offers[$key])) {
                                        $price = _to_float($offers[$key]);
                                        if ($price !== null && $price > 0) {
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                        $name = $node['name'] ?? null;
                        if ($name && $price !== null && $price > 0) {
                            $items[] = ['name' => $name, 'unit_price' => $price, 'category' => null, 'description' => $node['description'] ?? null];
                        }
                    }
                    foreach ($node as $value) {
                        if (is_array($value)) {
                            $queue[] = $value;
                        }
                    }
                }
            }
        }
    }

    return $items;
}

function scrape_products(string $url, int $timeout = 20): array
{
    $context = stream_context_create([
        'http' => [
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36\r\nAccept-Language: en-US,en;q=0.9,ur;q=0.8\r\n",
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);
    $html = @file_get_contents($url, false, $context);
    if ($html === false || trim($html) === '') {
        return [];
    }

    $items = _extract_json_ld($html);
    if (count($items) >= 2) {
        return $items;
    }

    if (preg_match('/<meta[^>]+property=["\']product:price:amount["\'][^>]+content=["\']([^"\']+)["\']/is', $html, $match)) {
        $price = _to_float($match[1]);
        if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/is', $html, $match2)) {
            $name = _clean_name($match2[1]);
            if ($price !== null && $price > 0 && _valid_name($name)) {
                return [['name' => $name, 'unit_price' => $price, 'category' => null, 'description' => null]];
            }
        }
    }

    $pattern = '/(?:<h1[^>]*>|<h2[^>]*>|<h3[^>]*>|<h4[^>]*>|<strong[^>]*>)(.*?)(?:<\/h[1-6]>|<\/strong>)/is';
    preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $name = _clean_name(strip_tags($match[1]));
        $price = null;
        if (preg_match('/(?:PKR|Rs\.?|?|?|USD|\$|£|€)\s*([\d][\d,]*(?:\.\d+)?)/i', $match[0], $p)) {
            $price = _to_float($p[0]);
        }
        if ($price !== null && $price > 0 && _valid_name($name)) {
            $items[] = ['name' => $name, 'unit_price' => $price, 'category' => null, 'description' => null];
        }
    }

    return array_slice($items, 0, MAX_ITEMS);
}
