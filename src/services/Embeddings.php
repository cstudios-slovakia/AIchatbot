<?php

namespace cstudiossro\craftcschatbot\services;

use Craft;
use cstudiossro\craftcschatbot\Plugin;
use cstudiossro\craftcschatbot\records\ChunkRecord;
use yii\base\Component;

class Embeddings extends Component
{
    // Defaults; overridden per-reindex from plugin Settings.
    public int $chunkSize = 1200;   // ~300 tokens
    public int $chunkOverlap = 150;

    /**
     * Replace all chunks for a source with new ones generated from given text.
     *
     * Pipeline: clean/denoise → structure-preserving normalize → structure-aware
     * chunking → optional contextual "Title > Section" prefix → embed → store.
     * $meta may carry siteId, language and title so retrieval can filter by site
     * and each chunk records where it came from.
     *
     * @param array{siteId?:?int, language?:?string, title?:?string} $meta
     * @return int chunk count
     */
    public function reindexSource(string $sourceType, int $sourceId, string $text, array $meta = []): int
    {
        $settings = Plugin::getInstance()->getSettings();
        $this->chunkSize = max(300, (int)($settings->chunkSize ?: 1200));
        $this->chunkOverlap = max(0, min((int)($settings->chunkOverlap ?: 150), (int)floor($this->chunkSize / 2)));

        $text = $this->normalize($text);
        if ($text === '') {
            $this->deleteChunks($sourceType, $sourceId);
            return 0;
        }
        $pieces = $this->chunk($text);
        if (empty($pieces)) {
            $this->deleteChunks($sourceType, $sourceId);
            return 0;
        }

        $title = trim((string)($meta['title'] ?? ''));
        $usePrefix = (bool)$settings->contextualPrefixEnabled;
        $siteId = array_key_exists('siteId', $meta) && $meta['siteId'] !== null ? (int)$meta['siteId'] : null;
        $language = isset($meta['language']) && $meta['language'] !== '' ? (string)$meta['language'] : null;

        // Build stored content — prepend a breadcrumb so both the embedding and
        // the generator see what document/section each chunk belongs to.
        $contents = [];
        foreach ($pieces as $p) {
            $prefix = '';
            if ($usePrefix) {
                $crumb = array_values(array_filter([$title, $p['section'] ?? null], fn($s) => (string)$s !== ''));
                if (!empty($crumb)) {
                    $prefix = implode(' > ', $crumb) . "\n\n";
                }
            }
            $contents[] = $prefix . $p['content'];
        }

        // Embed before touching what is already indexed. Replacing a source used
        // to start by deleting its chunks, so an embedding failure — a rate
        // limit, a network blip — left the source with nothing at all and the
        // assistant silently lost that content until someone retrained it.
        $vectors = Plugin::getInstance()->openAi->embed($contents);

        $transaction = Craft::$app->db->beginTransaction();
        try {
            $this->deleteChunks($sourceType, $sourceId);
            foreach ($pieces as $i => $p) {
                $content = $contents[$i];
                $rec = new ChunkRecord();
                $rec->sourceType = $sourceType;
                $rec->sourceId = $sourceId;
                $rec->siteId = $siteId;
                $rec->language = $language;
                $rec->position = $i;
                $rec->section = ($p['section'] ?? null) !== null ? mb_substr((string)$p['section'], 0, 500) : null;
                $rec->content = $content;
                $rec->embedding = isset($vectors[$i]) ? json_encode($vectors[$i]) : null;
                $rec->tokens = (int)ceil(mb_strlen($content) / 4);
                $rec->save(false);
            }
            $transaction?->commit();
        } catch (\Throwable $e) {
            $transaction?->rollBack();
            throw $e;
        }
        return count($pieces);
    }

    public function deleteChunks(string $sourceType, int $sourceId): void
    {
        Craft::$app->db->createCommand()
            ->delete('{{%chatbot_chunks}}', [
                'sourceType' => $sourceType,
                'sourceId' => $sourceId,
            ])
            ->execute();
    }

    /**
     * Structure-aware chunking. Splits on blank-line boundaries (headings /
     * paragraphs / list blocks), tracks the current markdown heading as the
     * chunk's section, and packs blocks up to chunkSize with a character overlap
     * carried between adjacent chunks. Oversized single blocks are hard-split.
     *
     * @return array<int, array{content:string, section:?string}>
     */
    public function chunk(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $blocks = preg_split('/\n{2,}/', $text) ?: [$text];
        $size = $this->chunkSize;
        $overlap = $this->chunkOverlap;

        $chunks = [];
        $buffer = '';
        $bufferSection = null;   // section active when the buffer started
        $currentSection = null;

        $flush = function () use (&$buffer, &$bufferSection, &$chunks, $overlap) {
            $content = trim($buffer);
            if ($content !== '') {
                $chunks[] = ['content' => $content, 'section' => $bufferSection];
            }
            // carry an overlap tail into the next buffer for context continuity
            if ($overlap > 0 && mb_strlen($content) > $overlap) {
                $buffer = mb_substr($content, -$overlap) . "\n\n";
            } else {
                $buffer = '';
            }
        };

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            if (($heading = $this->headingText($block)) !== null) {
                $currentSection = $heading;
            }

            // A single block bigger than the target is hard-split on its own.
            if (mb_strlen($block) > $size) {
                if (trim($buffer) !== '') {
                    $flush();
                }
                foreach ($this->hardSplit($block, $size, $overlap) as $sub) {
                    $chunks[] = ['content' => $sub, 'section' => $currentSection];
                }
                $buffer = '';
                $bufferSection = null;
                continue;
            }

            if (trim($buffer) === '') {
                $bufferSection = $currentSection;
            }

            if (mb_strlen($buffer) + mb_strlen($block) + 2 > $size && trim($buffer) !== '') {
                $flush();
                $bufferSection = $currentSection;
            }
            $buffer .= ($buffer === '' ? '' : "\n\n") . $block;
        }
        if (trim($buffer) !== '') {
            $chunks[] = ['content' => trim($buffer), 'section' => $bufferSection];
        }

        return $chunks;
    }

    /**
     * Hard-split a single oversized block on sentence/space boundaries.
     *
     * @return string[]
     */
    private function hardSplit(string $text, int $size, int $overlap): array
    {
        $len = mb_strlen($text);
        if ($len <= $size) {
            return [trim($text)];
        }
        $out = [];
        $start = 0;
        while ($start < $len) {
            $end = min($start + $size, $len);
            if ($end < $len) {
                $slice = mb_substr($text, $start, $end - $start);
                $break = max(
                    mb_strrpos($slice, ". ") ?: -1,
                    mb_strrpos($slice, "! ") ?: -1,
                    mb_strrpos($slice, "? ") ?: -1,
                    mb_strrpos($slice, " ") ?: -1,
                );
                if ($break !== -1 && $break > $size * 0.5) {
                    $end = $start + $break + 1;
                }
            }
            $piece = trim(mb_substr($text, $start, $end - $start));
            if ($piece !== '') {
                $out[] = $piece;
            }
            if ($end >= $len) {
                break;
            }
            $start = max($end - $overlap, $start + 1);
        }
        return $out;
    }

    /**
     * The heading text of a block if it is a markdown heading (produced by
     * normalize() from <h1-6>), else null.
     */
    private function headingText(string $block): ?string
    {
        if (preg_match('/^#{1,6}\s+(.+?)\s*$/u', trim($block), $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Turn raw source text (often HTML) into clean, structure-preserving plain
     * text ready for chunking: keep headings/list structure as markdown, strip
     * everything else, then denoise boilerplate that would pollute embeddings.
     */
    public function normalize(string $text): string
    {
        $text = $this->htmlStructureToText($text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = $this->denoise($text);
        return trim((string)$text);
    }

    /**
     * Convert structural HTML to a markdown-ish skeleton so headings and lists
     * survive as real chunk boundaries, before the remaining tags are stripped.
     */
    private function htmlStructureToText(string $html): string
    {
        if (stripos($html, '<') === false) {
            return $html;
        }
        // Drop non-content containers entirely.
        $drop = ['script', 'style', 'nav', 'footer', 'header', 'aside', 'form', 'svg', 'noscript'];
        foreach ($drop as $tag) {
            $html = preg_replace('#<' . $tag . '\b[^>]*>.*?</' . $tag . '>#is', ' ', $html) ?? $html;
        }
        // Headings → "## text"
        $html = preg_replace_callback('#<h([1-6])\b[^>]*>(.*?)</h\1>#is', function ($m) {
            $level = str_repeat('#', (int)$m[1]);
            return "\n\n{$level} " . trim(strip_tags($m[2])) . "\n";
        }, $html) ?? $html;
        // List items → "- text"
        $html = preg_replace('#<li\b[^>]*>(.*?)</li>#is', "\n- $1", $html) ?? $html;
        // Block/line breaks → newlines
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|tr|section|article|h[1-6])>#i', "\n\n", $html) ?? $html;
        return $html;
    }

    /**
     * Lines that are pure UI chrome rather than content.
     *
     * Every pattern is anchored to the whole line on purpose. Boilerplate is a
     * *shape* ("Back to top" standing alone), not a topic: a sentence that
     * mentions cookies, consent or a copyright is content, and on a privacy or
     * terms page it is the only content there is. Matching such words as
     * substrings deletes precisely the pages visitors ask about most, so keep
     * this list structural and never add a bare content word to it.
     */
    private const BOILERPLATE_LINES = [
        '/^skip to (main )?content$/iu',
        '/^toggle (navigation|menu)$/iu',
        '/^(back|scroll|return) to top$/iu',
        '/^share (this|on .{1,30})$/iu',
        '/^subscribe( to our newsletter)?$/iu',
        '/^all rights reserved\.?$/iu',
        // Copyright notices: "©"/"(c)" opens a line only in a footer credit.
        '/^(©|\(c\))\s*\S/iu',
        '/^copyright\b.*\b\d{4}\b/iu',
    ];

    /**
     * Remove boilerplate lines and normalize whitespace so page chrome
     * (nav menus, footer credits, repeated headers, control chars) does not
     * degrade retrieval quality.
     */
    private function denoise(string $text): string
    {
        // Normalize newlines and strip control/zero-width chars.
        $text = preg_replace('/\r\n?/', "\n", $text) ?? $text;
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', ' ', $text) ?? $text;
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? $text;

        $lines = explode("\n", $text);
        $out = [];
        $prev = null;
        foreach ($lines as $line) {
            $trimmed = trim(preg_replace('/[ \t]+/', ' ', $line) ?? $line);
            if ($trimmed === '') {
                if (end($out) !== '') {
                    $out[] = '';
                }
                $prev = '';
                continue;
            }
            if ($this->isBoilerplateLine($trimmed)) {
                continue;
            }
            // Drop consecutive duplicate lines (repeated nav/header/footer chrome).
            if ($prev !== null && mb_strtolower($trimmed) === mb_strtolower($prev)) {
                continue;
            }
            $out[] = $trimmed;
            $prev = $trimmed;
        }

        $text = implode("\n", $out);
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    private function isBoilerplateLine(string $line): bool
    {
        foreach (self::BOILERPLATE_LINES as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }
        return false;
    }
}
