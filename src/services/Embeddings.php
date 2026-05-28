<?php

namespace cstudiossro\craftcschatbot\services;

use Craft;
use cstudiossro\craftcschatbot\Plugin;
use cstudiossro\craftcschatbot\records\ChunkRecord;
use yii\base\Component;

class Embeddings extends Component
{
    public int $chunkSize = 1800;   // ~450 tokens
    public int $chunkOverlap = 200;

    /**
     * Replace all chunks for a source with new ones generated from given text.
     * Returns chunk count.
     */
    public function reindexSource(string $sourceType, int $sourceId, string $text): int
    {
        $this->deleteChunks($sourceType, $sourceId);
        $text = $this->normalize($text);
        if ($text === '') {
            return 0;
        }
        $chunks = $this->chunk($text);
        if (empty($chunks)) {
            return 0;
        }
        $vectors = Plugin::getInstance()->openAi->embed($chunks);
        foreach ($chunks as $i => $content) {
            $rec = new ChunkRecord();
            $rec->sourceType = $sourceType;
            $rec->sourceId = $sourceId;
            $rec->position = $i;
            $rec->content = $content;
            $rec->embedding = isset($vectors[$i]) ? json_encode($vectors[$i]) : null;
            $rec->tokens = (int)ceil(strlen($content) / 4);
            $rec->save(false);
        }
        return count($chunks);
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
     * @return string[]
     */
    public function chunk(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $size = $this->chunkSize;
        $overlap = $this->chunkOverlap;
        $len = mb_strlen($text);
        if ($len <= $size) {
            return [$text];
        }

        $chunks = [];
        $start = 0;
        while ($start < $len) {
            $end = min($start + $size, $len);
            // try to break on paragraph or sentence boundary
            if ($end < $len) {
                $slice = mb_substr($text, $start, $end - $start);
                $break = max(
                    mb_strrpos($slice, "\n\n") ?: -1,
                    mb_strrpos($slice, ". ") ?: -1,
                    mb_strrpos($slice, "! ") ?: -1,
                    mb_strrpos($slice, "? ") ?: -1,
                );
                if ($break !== -1 && $break > $size * 0.5) {
                    $end = $start + $break + 1;
                }
            }
            $chunk = trim(mb_substr($text, $start, $end - $start));
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
            if ($end >= $len) {
                break;
            }
            $start = max($end - $overlap, $start + 1);
        }
        return $chunks;
    }

    public function normalize(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\r\n?/', "\n", $text);
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim((string)$text);
    }
}
