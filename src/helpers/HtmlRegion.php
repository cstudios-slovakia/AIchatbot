<?php

namespace cstudiossro\craftcschatbot\helpers;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Narrows a fetched page down to the part a visitor actually reads.
 *
 * Indexing a rendered page is the only way to reach facts that live in
 * templates rather than in fields — a security class printed as `{{ 'RC2'|t }}`
 * exists nowhere else. The cost is that every page also carries the nav, the
 * cookie bar and the footer, and those repeat on every page in the site. Left
 * in, they are the most common text in the whole index: they match every query
 * a little, they push real passages out of the candidate pool, and they waste a
 * chunk per page.
 *
 * Two passes deal with that. A configured selector is exact and preferred. When
 * there is none — the normal case on a site nobody wants to re-audit — the
 * structural pass drops the elements that are chrome by tag or by name, which
 * is right often enough to be worth doing by default and is always guarded by
 * the "did this delete the page?" check below.
 */
class HtmlRegion
{
    /**
     * Elements that hold no readable text at all. Dropped before anything is
     * measured — a page with 19 inline scripts carries more JSON than prose,
     * and counting that as "text the page had" makes every later ratio lie.
     */
    private const NEVER_CONTENT = ['script', 'style', 'noscript', 'template', 'svg', 'iframe'];

    /** Elements that are chrome wherever they appear. */
    private const CHROME_TAGS = ['nav', 'header', 'footer', 'aside', 'form', 'button', 'select'];

    /** id/class names that mark a wrapper as chrome rather than content. */
    private const CHROME_NAMES = '/(^|[-_ ])(nav|navbar|navigation|menu|topbar|header|footer|sidebar|aside|cookie|consent|gdpr|banner|breadcrumbs?|social|share|newsletter|subscribe|popup|modal|offcanvas|skip-link|pagination|widget)([-_ ]|$)/i';

    /** Selectors tried in order when none is configured. */
    private const FALLBACK_SELECTORS = ['main', '[role=main]', 'article', '#content', '#main'];

    /**
     * A page's readable region as HTML, ready for the normal text pipeline.
     *
     * @param string $selector comma-separated CSS selectors; first match wins
     */
    public static function extract(string $html, string $selector = '', bool $stripChrome = true): string
    {
        if (trim($html) === '') {
            return $html;
        }
        $dom = self::load($html);
        if (!$dom) {
            return $html; // unparseable — let the text pipeline do what it can
        }
        $xpath = new DOMXPath($dom);
        $root = $xpath->query('//body')->item(0) ?? $dom->documentElement;
        if (!$root) {
            return $html;
        }
        self::dropTags($xpath, $root, self::NEVER_CONTENT);

        $region = self::firstMatch($xpath, $selector)
            ?? ($selector !== '' ? null : self::firstMatch($xpath, implode(',', self::FALLBACK_SELECTORS)));

        // A selector that matches nothing is a misconfiguration, not a reason to
        // index an empty page. Fall back to the body and let the chrome pass run.
        $scope = $region ?? $root;
        if (!$stripChrome) {
            return (string)$dom->saveHTML($scope);
        }

        // Keep a clean copy to fall back to, because the chrome pass edits the
        // tree in place and there is no undo.
        $safe = (string)$dom->saveHTML($scope);
        $before = self::textLength($scope);

        self::dropChrome($xpath, $scope, $region !== null);

        // Guard: a template that names its content wrapper "main-banner", or one
        // that builds its hero out of <header>, can leave the pass having deleted
        // the article itself. Losing the page is worse than keeping its nav.
        //
        // Deliberately near-total: "most of the text went" is the normal result
        // on a landing page that really is mostly nav, and reverting there keeps
        // the chrome it was right to drop. Only near-total destruction is
        // evidence the pass took the article with it.
        $after = self::textLength($scope);
        if ($before > 0 && ($after < 40 || $after < $before * 0.02)) {
            return $safe;
        }

        return (string)$dom->saveHTML($scope);
    }

    private static function load(string $html): ?DOMDocument
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The meta declaration is what tells libxml the bytes are UTF-8; without
        // it every accented character in the page comes back mangled.
        $ok = $dom->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok ? $dom : null;
    }

    private static function firstMatch(DOMXPath $xpath, string $selector): ?DOMElement
    {
        foreach (array_filter(array_map('trim', explode(',', $selector))) as $one) {
            $expr = self::toXPath($one);
            if ($expr === null) {
                continue;
            }
            $found = $xpath->query($expr);
            if ($found && $found->length > 0 && $found->item(0) instanceof DOMElement) {
                return $found->item(0);
            }
        }
        return null;
    }

    /**
     * The slice of CSS worth supporting here: tag, #id, .class, [attr=value] and
     * descendant combinations of those. Anything richer belongs in a real
     * selector library, and anything richer than this is a sign the template
     * should grow a stable id instead.
     */
    private static function toXPath(string $selector): ?string
    {
        $expr = '';
        foreach (preg_split('/\s+/', trim($selector)) ?: [] as $step) {
            if ($step === '') {
                continue;
            }
            if (!preg_match('/^([a-z][a-z0-9-]*)?((?:[#.][\w-]+|\[[\w-]+(?:=["\']?[^\]"\']*["\']?)?\])*)$/i', $step, $m)) {
                return null;
            }
            $tag = $m[1] ?: '*';
            $predicates = '';
            preg_match_all('/[#.][\w-]+|\[[\w-]+(?:=["\']?[^\]"\']*["\']?)?\]/', $m[2] ?? '', $bits);
            foreach ($bits[0] as $bit) {
                if ($bit[0] === '#') {
                    $predicates .= sprintf("[@id=%s]", self::quote(substr($bit, 1)));
                } elseif ($bit[0] === '.') {
                    $predicates .= sprintf(
                        "[contains(concat(' ',normalize-space(@class),' '),%s)]",
                        self::quote(' ' . substr($bit, 1) . ' '),
                    );
                } else {
                    $inner = trim($bit, '[]');
                    if (str_contains($inner, '=')) {
                        [$attr, $value] = explode('=', $inner, 2);
                        $predicates .= sprintf('[@%s=%s]', $attr, self::quote(trim($value, '"\'')));
                    } else {
                        $predicates .= sprintf('[@%s]', $inner);
                    }
                }
            }
            $expr .= '//' . $tag . $predicates;
        }
        return $expr !== '' ? $expr : null;
    }

    /** XPath has no escape syntax, so a value containing a quote needs concat(). */
    private static function quote(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'{$value}'";
        }
        return "concat('" . str_replace("'", "',\"'\",'", $value) . "')";
    }

    /**
     * @param bool $insideRegion true when a selector already picked the content
     *                           out, so only structural noise is left to remove
     */
    private static function dropChrome(DOMXPath $xpath, DOMNode $scope, bool $insideRegion): void
    {
        self::dropTags($xpath, $scope, self::CHROME_TAGS);
        if ($insideRegion) {
            // The author said where the content is. Second-guessing their class
            // names inside it only risks deleting the thing they pointed at.
            return;
        }
        $named = $xpath->query('.//*[@class or @id]', $scope);
        $doomed = [];
        foreach ($named ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $name = $node->getAttribute('class') . ' ' . $node->getAttribute('id');
            if (preg_match(self::CHROME_NAMES, $name)) {
                $doomed[] = $node;
            }
        }
        foreach ($doomed as $node) {
            // A match nested inside an earlier match is already gone.
            $node->parentNode?->removeChild($node);
        }
    }

    /**
     * @param string[] $tags
     */
    private static function dropTags(DOMXPath $xpath, DOMNode $scope, array $tags): void
    {
        $expr = implode('|', array_map(fn($t) => ".//{$t}", $tags));
        $found = $xpath->query($expr, $scope);
        $doomed = [];
        foreach ($found ?: [] as $node) {
            $doomed[] = $node;
        }
        foreach ($doomed as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private static function textLength(DOMNode $node): int
    {
        return mb_strlen(trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? ''));
    }
}
