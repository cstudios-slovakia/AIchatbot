<?php

namespace cstudiossro\craftcschatbot\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use yii\web\Response;

class OgController extends Controller
{
    protected array|bool|int $allowAnonymous = ['fetch'];
    public $enableCsrfValidation = false;

    private const CACHE_KEY = 'cs-chatbot:og:';
    private const CACHE_TTL = 3600;

    public function actionFetch(): Response
    {
        $url = trim((string)Craft::$app->request->getQueryParam('url', ''));
        if ($url === '') {
            return $this->asJson(['ok' => false, 'error' => 'missing url']);
        }
        $matchedSite = self::matchSite($url);
        if (!$matchedSite) {
            return $this->asJson(['ok' => false, 'error' => 'off-site']);
        }

        $cacheKey = self::CACHE_KEY . md5($url);
        $cached = Craft::$app->cache->get($cacheKey);
        if (is_array($cached)) {
            return $this->asJson($cached);
        }

        $meta = self::resolveEntryMeta($url, $matchedSite) ?? ['ok' => false, 'error' => 'no entry'];
        Craft::$app->cache->set($cacheKey, $meta, ($meta['ok'] ?? false) ? self::CACHE_TTL : 30);
        return $this->asJson($meta);
    }

    private static function matchSite(string $url): ?\craft\models\Site
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return null;
        }
        foreach (Craft::$app->sites->getAllSites() as $site) {
            $siteHost = strtolower((string)parse_url((string)$site->getBaseUrl(), PHP_URL_HOST));
            if ($siteHost !== '' && $siteHost === $host) {
                return $site;
            }
        }
        return null;
    }

    private static function resolveEntryMeta(string $url, \craft\models\Site $site): ?array
    {
        $path = trim((string)parse_url($url, PHP_URL_PATH), '/');
        $uri = $path === '' ? '__home__' : $path;
        // Any element with a URI, not only entries: categories and the product
        // types other plugins add are linked just as often, and resolving by URI
        // means a card works for all of them without knowing their class.
        $entry = Craft::$app->elements->getElementByUri($uri, $site->id);
        if (!$entry instanceof \craft\base\Element) {
            return null;
        }

        $title = trim((string)$entry->title) ?: null;
        $description = self::seoDescription($entry)
            ?? self::firstStringFieldValue($entry, ['summary', 'description', 'excerpt', 'metaDescription', 'seoDescription']);
        if (!$title && !$description) {
            return null;
        }
        $image = self::seoImageUrl($entry) ?? self::headOgImage($url);
        return [
            'ok' => true,
            'url' => $url,
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'siteName' => $site->name ?: null,
        ];
    }

    /**
     * The `og:image` the page itself declares, for entries whose SEO field has
     * none — the template often sets a default or derives one from a hero image,
     * and that is what the page shares on social networks anyway.
     *
     * Reads only as far as `</head>`: the response is streamed and dropped once
     * the head is in, so a long page costs a few kilobytes rather than the whole
     * document. Only ever called for URLs on this install's own sites, and a
     * redirect to another host is refused, so it can't be pointed elsewhere.
     */
    private static function headOgImage(string $url): ?string
    {
        $head = self::fetchHead($url);
        if ($head === null || !preg_match_all('/<meta\b[^>]*>/i', $head, $tags)) {
            return null;
        }
        $found = [];
        foreach ($tags[0] as $tag) {
            if (!preg_match('/\b(?:property|name)\s*=\s*["\']?([^"\'\s>]+)/i', $tag, $key)
                || !preg_match('/\bcontent\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $content)) {
                continue;
            }
            $value = trim(html_entity_decode($content[1] . ($content[2] ?? '') . ($content[3] ?? ''), ENT_QUOTES | ENT_HTML5));
            if ($value !== '') {
                $found[strtolower($key[1])] ??= $value;
            }
        }
        $image = $found['og:image'] ?? $found['og:image:url'] ?? $found['og:image:secure_url'] ?? null;
        if ($image === null) {
            return null;
        }
        // Relative or protocol-relative values resolve against the page.
        if (str_starts_with($image, '//')) {
            $image = (parse_url($url, PHP_URL_SCHEME) ?: 'https') . ':' . $image;
        } elseif (!preg_match('#^https?://#i', $image)) {
            $origin = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST)
                . (($port = parse_url($url, PHP_URL_PORT)) ? ':' . $port : '');
            $image = $origin . '/' . ltrim($image, '/');
        }
        return $image;
    }

    private static function fetchHead(string $url): ?string
    {
        $maxBytes = 65536;
        try {
            $response = Craft::createGuzzleClient()->get($url, [
                'stream' => true,
                'connect_timeout' => 2,
                'timeout' => 4,
                'http_errors' => false,
                'headers' => ['Accept' => 'text/html'],
                'allow_redirects' => [
                    'max' => 3,
                    'on_redirect' => function($request, $response, $uri): void {
                        if (self::matchSite((string)$uri) === null) {
                            throw new \RuntimeException('redirect off-site');
                        }
                    },
                ],
            ]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $body = $response->getBody();
            $html = '';
            while (!$body->eof() && strlen($html) < $maxBytes) {
                $html .= $body->read(8192);
                $end = stripos($html, '</head>');
                if ($end !== false) {
                    $html = substr($html, 0, $end);
                    break;
                }
            }
            $body->close();
            return $html;
        } catch (\Throwable $e) {
            Craft::info("OG head fetch failed for {$url}: {$e->getMessage()}", 'cs-chatbot');
            return null;
        }
    }

    private static function firstStringFieldValue(\craft\base\Element $entry, array $handles): ?string
    {
        foreach ($handles as $handle) {
            try {
                $val = $entry->getFieldValue($handle);
                if (is_string($val) && trim($val) !== '') {
                    return mb_substr(trim(strip_tags($val)), 0, 400);
                }
            } catch (\Throwable) {
                // field not present on this entry type
            }
        }
        return null;
    }

    /**
     * Best-effort OG image lookup from a SEO field on the entry.
     * Supports SEOmatic (field type SeoSettings, typically handle "seo") and a few other common SEO plugins.
     * If no SEO field is present or no image set, returns null and the card just shows the title.
     */
    private static function seoImageUrl(\craft\base\Element $entry): ?string
    {
        $seoHandles = ['seo', 'seoSettings', 'seomatic', 'seoPro'];
        foreach ($seoHandles as $handle) {
            try {
                $seo = $entry->getFieldValue($handle);
            } catch (\Throwable) {
                continue;
            }
            if (!$seo) {
                continue;
            }
            // Ether SEO: per-entry social image. facebook → twitter.
            $social = self::safeProp($seo, 'social');
            if (is_array($social) || is_object($social)) {
                foreach (['facebook', 'twitter'] as $network) {
                    $entry_ = is_array($social) ? ($social[$network] ?? null) : self::safeProp($social, $network);
                    if (!$entry_) {
                        continue;
                    }
                    $url = self::resolveAssetUrl(self::safeProp($entry_, 'image'));
                    if ($url !== null) {
                        return $url;
                    }
                }
            }
            // Ether SEO: default OG image from the field settings (socialImage asset ID).
            $url = self::etherSeoFieldDefaultImage($handle);
            if ($url !== null) {
                return $url;
            }
            // Direct string URL (some plugins expose a resolved URL).
            foreach (['ogImage', 'metaImage', 'socialImage', 'shareImage'] as $stringProp) {
                $val = self::safeProp($seo, $stringProp);
                if (is_string($val) && $val !== '') {
                    return $val;
                }
            }
            // Asset query / asset element.
            foreach (['image', 'ogImage', 'socialImage', 'shareImage', 'metaImage'] as $assetProp) {
                $val = self::safeProp($seo, $assetProp);
                $url = self::resolveAssetUrl($val);
                if ($url !== null) {
                    return $url;
                }
            }
            // SEOmatic nested structure: $seo->metaGlobalVars->ogImage (resolved URL string).
            $globals = self::safeProp($seo, 'metaGlobalVars');
            if ($globals) {
                foreach (['ogImage', 'twitterImage'] as $prop) {
                    $val = self::safeProp($globals, $prop);
                    if (is_string($val) && $val !== '') {
                        return $val;
                    }
                }
            }
        }
        // SEOmatic global accessor on the entry (when no per-entry field exists).
        if (class_exists('\\nystudio107\\seomatic\\Seomatic')) {
            try {
                $plugin = \nystudio107\seomatic\Seomatic::$plugin ?? null;
                if ($plugin && isset($plugin->metaBundles)) {
                    $bundle = $plugin->metaBundles->getMetaBundleByElement($entry);
                    if ($bundle && !empty($bundle->metaGlobalVars->ogImage)) {
                        return (string)$bundle->metaGlobalVars->ogImage;
                    }
                }
            } catch (\Throwable) {
                // SEOmatic not loaded for this request — fine
            }
        }
        return null;
    }

    private static function seoDescription(\craft\base\Element $entry): ?string
    {
        foreach (['seo', 'seoSettings', 'seomatic', 'seoPro'] as $handle) {
            try {
                $seo = $entry->getFieldValue($handle);
            } catch (\Throwable) {
                continue;
            }
            if (!$seo) {
                continue;
            }
            // Ether SEO: social-network specific description (facebook → twitter).
            $social = self::safeProp($seo, 'social');
            if (is_array($social) || is_object($social)) {
                foreach (['facebook', 'twitter'] as $network) {
                    $node = is_array($social) ? ($social[$network] ?? null) : self::safeProp($social, $network);
                    if (!$node) continue;
                    $val = self::safeProp($node, 'description');
                    $str = self::stringifyMarkup($val);
                    if ($str !== null) return $str;
                }
            }
            // Field-level description (rendered or raw).
            foreach (['descriptionRaw', 'description', 'metaDescription'] as $prop) {
                $val = self::safeProp($seo, $prop);
                $str = self::stringifyMarkup($val);
                if ($str !== null) return $str;
            }
            // SEOmatic nested.
            $globals = self::safeProp($seo, 'metaGlobalVars');
            if ($globals) {
                foreach (['ogDescription', 'twitterDescription', 'description'] as $prop) {
                    $val = self::safeProp($globals, $prop);
                    $str = self::stringifyMarkup($val);
                    if ($str !== null) return $str;
                }
            }
        }
        return null;
    }

    private static function stringifyMarkup(mixed $val): ?string
    {
        if ($val === null) return null;
        if (is_object($val)) {
            // Twig\Markup, Stringable, etc.
            try { $val = (string)$val; } catch (\Throwable) { return null; }
        }
        if (!is_string($val)) return null;
        $s = trim(strip_tags($val));
        if ($s === '') return null;
        return mb_substr($s, 0, 400);
    }

    private static function etherSeoFieldDefaultImage(string $handle): ?string
    {
        try {
            $field = Craft::$app->fields->getFieldByHandle($handle);
            if (!$field) {
                return null;
            }
            $settings = method_exists($field, 'getSettings') ? $field->getSettings() : (array)($field->settings ?? []);
            if (!is_array($settings)) {
                return null;
            }
            $val = $settings['socialImage'] ?? null;
            if (!$val) {
                return null;
            }
            $id = is_array($val) ? (int)($val[0] ?? 0) : (int)$val;
            if ($id <= 0) {
                return null;
            }
            $asset = \craft\elements\Asset::find()->id($id)->one();
            if ($asset && method_exists($asset, 'getUrl')) {
                $u = (string)$asset->getUrl();
                return $u !== '' ? $u : null;
            }
        } catch (\Throwable) {
            return null;
        }
        return null;
    }

    private static function safeProp(mixed $obj, string $name): mixed
    {
        if (!is_object($obj)) {
            return null;
        }
        try {
            if (isset($obj->$name)) {
                return $obj->$name;
            }
            $getter = 'get' . ucfirst($name);
            if (method_exists($obj, $getter)) {
                return $obj->$getter();
            }
        } catch (\Throwable) {
            return null;
        }
        return null;
    }

    private static function resolveAssetUrl(mixed $val): ?string
    {
        if (!$val) {
            return null;
        }
        try {
            if (method_exists($val, 'one')) {
                $asset = $val->one();
                if ($asset && method_exists($asset, 'getUrl')) {
                    $u = (string)$asset->getUrl();
                    return $u !== '' ? $u : null;
                }
            }
            if (method_exists($val, 'getUrl')) {
                $u = (string)$val->getUrl();
                return $u !== '' ? $u : null;
            }
        } catch (\Throwable) {
            return null;
        }
        return null;
    }
}
