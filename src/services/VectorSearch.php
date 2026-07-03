<?php

namespace cstudiossro\craftcschatbot\services;

use Craft;
use cstudiossro\craftcschatbot\Plugin;
use yii\base\Component;

class VectorSearch extends Component
{
    /**
     * Retrieve the top-$k chunks for a query.
     *
     * Vector cosine is always computed. When $queryText is given and hybrid
     * search is enabled, a BM25 lexical score is computed over the same rows and
     * the two rankings are fused with Reciprocal Rank Fusion so exact terms,
     * names and numbers aren't lost by embeddings alone. Returned rows are
     * ordered by the fused rank but each row's `score` stays its raw query-cosine
     * (0–1) — callers rely on that for confidence/handoff gating.
     *
     * @param float[] $query embedding of the query
     * @param string|null $queryText raw query text, enabling the lexical half
     * @param bool $includeVectors also return each row's decoded embedding under
     *        `_vector` (needed for MMR reranking); strip before exposing rows.
     * @return array<int, array{id:int, sourceType:string, sourceId:int, content:string, score:float, _vector?:float[]}>
     */
    public function topK(
        array $query,
        int $k = 5,
        float $minScore = 0.0,
        ?string $queryText = null,
        bool $includeVectors = false,
    ): array {
        if (empty($query)) {
            return [];
        }
        $rows = (new \craft\db\Query())
            ->select(['id', 'sourceType', 'sourceId', 'content', 'embedding'])
            ->from('{{%chatbot_chunks}}')
            ->where(['not', ['embedding' => null]])
            ->all(Craft::$app->db);

        $qNorm = $this->norm($query);
        if ($qNorm === 0.0) {
            return [];
        }

        $hybrid = $queryText !== null
            && trim($queryText) !== ''
            && Plugin::getInstance()->getSettings()->hybridEnabled;

        // Decode vectors, score cosine, and keep candidates above the floor.
        $cands = [];
        foreach ($rows as $row) {
            $vec = json_decode((string)$row['embedding'], true);
            if (!is_array($vec) || empty($vec)) {
                continue;
            }
            $score = $this->cosine($query, $qNorm, $vec);
            if ($score < $minScore) {
                continue;
            }
            $entry = [
                'id' => (int)$row['id'],
                'sourceType' => (string)$row['sourceType'],
                'sourceId' => (int)$row['sourceId'],
                'content' => (string)$row['content'],
                'score' => $score,
            ];
            if ($includeVectors) {
                $entry['_vector'] = $vec;
            }
            $cands[] = $entry;
        }
        if (empty($cands)) {
            return [];
        }

        if (!$hybrid) {
            usort($cands, fn($a, $b) => $b['score'] <=> $a['score']);
            return array_slice($cands, 0, $k);
        }

        // Lexical half: BM25 over the candidate contents.
        $bm25 = $this->bm25Scores((string)$queryText, array_column($cands, 'content'));

        // Two ranked lists over the same candidate indices.
        $cosOrder = array_keys($cands);
        usort($cosOrder, fn($i, $j) => $cands[$j]['score'] <=> $cands[$i]['score']);
        $bmOrder = array_keys($cands);
        usort($bmOrder, fn($i, $j) => $bm25[$j] <=> $bm25[$i]);

        // Reciprocal Rank Fusion: Σ 1/(k + rank).
        $rrfK = max(1, (int)Plugin::getInstance()->getSettings()->rrfK);
        $rrf = array_fill_keys(array_keys($cands), 0.0);
        foreach ($cosOrder as $rank => $idx) {
            $rrf[$idx] += 1.0 / ($rrfK + $rank + 1);
        }
        foreach ($bmOrder as $rank => $idx) {
            $rrf[$idx] += 1.0 / ($rrfK + $rank + 1);
        }
        $fused = array_keys($rrf);
        usort($fused, fn($i, $j) => $rrf[$j] <=> $rrf[$i]);

        $out = [];
        foreach (array_slice($fused, 0, $k) as $idx) {
            $out[] = $cands[$idx];
        }
        return $out;
    }

    /**
     * Cosine similarity between two raw vectors (public helper for callers that
     * need chunk-to-chunk similarity, e.g. MMR reranking).
     *
     * @param float[] $a
     * @param float[] $b
     */
    public function similarity(array $a, array $b): float
    {
        $aNorm = $this->norm($a);
        if ($aNorm === 0.0) {
            return 0.0;
        }
        return $this->cosine($a, $aNorm, $b);
    }

    /**
     * BM25 relevance of each document to the query, aligned to $docs' order.
     * Classic Okapi BM25 (k1=1.2, b=0.75) with IDF and length normalization
     * computed over the given document set.
     *
     * @param string[] $docs
     * @return array<int, float>
     */
    private function bm25Scores(string $query, array $docs): array
    {
        $k1 = 1.2;
        $b = 0.75;
        $n = count($docs);
        $scores = array_fill(0, $n, 0.0);
        $qTerms = array_unique($this->tokenize($query));
        if ($n === 0 || empty($qTerms)) {
            return $scores;
        }

        $docTerms = [];   // index => [term => freq]
        $docLen = [];     // index => token count
        $df = [];         // term => document frequency
        $totalLen = 0;
        foreach ($docs as $i => $doc) {
            $counts = array_count_values($this->tokenize((string)$doc));
            $docTerms[$i] = $counts;
            $docLen[$i] = array_sum($counts);
            $totalLen += $docLen[$i];
            foreach (array_keys($counts) as $term) {
                $df[$term] = ($df[$term] ?? 0) + 1;
            }
        }
        $avgdl = $totalLen / max(1, $n);

        foreach ($docs as $i => $doc) {
            $len = $docLen[$i];
            $sum = 0.0;
            foreach ($qTerms as $term) {
                $f = $docTerms[$i][$term] ?? 0;
                if ($f === 0) {
                    continue;
                }
                $dfi = $df[$term] ?? 0;
                $idf = log(1 + ($n - $dfi + 0.5) / ($dfi + 0.5));
                $sum += $idf * ($f * ($k1 + 1)) / ($f + $k1 * (1 - $b + $b * ($len / max(1e-9, $avgdl))));
            }
            $scores[$i] = $sum;
        }
        return $scores;
    }

    /**
     * Lowercase, Unicode-aware word tokenizer. Language-agnostic, no stemming or
     * stopword list — keeps names/numbers intact. Single-character tokens dropped
     * as noise.
     *
     * @return string[]
     */
    private function tokenize(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [];
        return array_values(array_filter($parts, fn($t) => mb_strlen($t) >= 2));
    }

    private function norm(array $v): float
    {
        $sum = 0.0;
        foreach ($v as $x) {
            $sum += $x * $x;
        }
        return sqrt($sum);
    }

    private function cosine(array $a, float $aNorm, array $b): float
    {
        $dot = 0.0;
        $bSum = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $bSum += $b[$i] * $b[$i];
        }
        $bNorm = sqrt($bSum);
        if ($aNorm === 0.0 || $bNorm === 0.0) {
            return 0.0;
        }
        return $dot / ($aNorm * $bNorm);
    }
}
