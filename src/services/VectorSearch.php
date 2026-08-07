<?php

namespace cstudiossro\craftcschatbot\services;

use Craft;
use cstudiossro\craftcschatbot\helpers\Vector;
use cstudiossro\craftcschatbot\Plugin;
use yii\base\Component;

class VectorSearch extends Component
{
    /**
     * Chunks read per database round trip while scanning.
     *
     * The scan is streamed rather than loaded whole: holding every chunk's
     * vector at once cost roughly 6 KB each even packed, which put a hard
     * ceiling on how much content a site could be trained on. Peak memory is
     * now this batch plus the two bounded candidate pools, whatever the table
     * size.
     */
    private const SCAN_BATCH = 500;

    /**
     * Smallest candidate pool kept from the scan, per ranking. Generous enough
     * that reranking has something to work with on a small site, and bounded
     * enough to stay flat on a large one.
     */
    private const MIN_POOL = 100;

    /**
     * How many leading characters of a word decide a lexical match. Long enough
     * that unrelated words rarely collide, short enough to survive the case
     * endings of Slavic languages.
     */
    private const STEM_LENGTH = 5;

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
     * @param int|null $siteId when set (and site filtering enabled), restrict to
     *        chunks of that site plus site-agnostic chunks (url/file/qa, siteId null).
     * @return array<int, array{id:int, sourceType:string, sourceId:int, content:string, score:float, _vector?:float[]}>
     */
    public function topK(
        array $query,
        int $k = 5,
        float $minScore = 0.0,
        ?string $queryText = null,
        bool $includeVectors = false,
        ?int $siteId = null,
    ): array {
        if (empty($query)) {
            return [];
        }
        $queryNorm = $this->norm($query);
        if ($queryNorm === 0.0) {
            return [];
        }

        $settings = Plugin::getInstance()->getSettings();
        $hybrid = $queryText !== null && trim($queryText) !== '' && $settings->hybridEnabled;
        $queryTerms = $hybrid ? array_values(array_unique($this->stemKeys($this->tokenize($queryText)))) : [];
        $poolSize = max($k, self::MIN_POOL, (int)$settings->retrievalCandidatePool);

        $scan = $this->scan($query, $queryNorm, $minScore, $queryTerms, $poolSize, $includeVectors, $siteId);
        $byCosine = $scan['cosine'];
        if (empty($byCosine)) {
            return [];
        }

        if (!$hybrid || empty($queryTerms) || empty($scan['lexical'])) {
            usort($byCosine, fn($a, $b) => $b['score'] <=> $a['score']);
            return array_slice(array_values($byCosine), 0, $k);
        }

        return $this->fuse($byCosine, $scan, $k);
    }

    /**
     * One streaming pass over the chunk table.
     *
     * Returns two bounded candidate pools — the best by cosine, and the best by
     * lexical overlap — plus the corpus statistics BM25 needs. Keeping a lexical
     * pool of its own is what lets an exact-term match survive that the
     * embedding ranked poorly, which is the entire reason for hybrid search.
     *
     * @param float[] $query
     * @param string[] $queryTerms
     * @return array{
     *   cosine: array<int, array<string, mixed>>,
     *   lexical: array<int, array<string, mixed>>,
     *   df: array<string, int>,
     *   docs: int,
     *   totalLength: int
     * }
     */
    private function scan(
        array $query,
        float $queryNorm,
        float $minScore,
        array $queryTerms,
        int $poolSize,
        bool $includeVectors,
        ?int $siteId,
    ): array {
        $siteFilter = $siteId !== null && Plugin::getInstance()->getSettings()->siteFilterEnabled;
        $termLookup = array_flip($queryTerms);

        $cosine = [];
        $lexical = [];
        $df = [];
        $docs = 0;
        $totalLength = 0;
        $lastId = 0;

        while (true) {
            $rowsQuery = (new \craft\db\Query())
                ->select(['id', 'sourceType', 'sourceId', 'content', 'embedding', 'embeddingBlob'])
                ->from('{{%chatbot_chunks}}')
                ->where(['>', 'id', $lastId])
                ->andWhere([
                    'or',
                    ['not', ['embeddingBlob' => null]],
                    ['not', ['embedding' => null]],
                ])
                ->orderBy(['id' => SORT_ASC])
                ->limit(self::SCAN_BATCH);
            if ($siteFilter) {
                // Match the requested site, plus site-agnostic chunks (siteId IS NULL).
                $rowsQuery->andWhere(['or', ['siteId' => $siteId], ['siteId' => null]]);
            }
            $rows = $rowsQuery->all(Craft::$app->db);
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $vector = Vector::unpack($row['embeddingBlob'] ?? null, $row['embedding'] ?? null);
                if (empty($vector)) {
                    continue;
                }
                $content = (string)$row['content'];
                $score = $this->cosine($query, $queryNorm, $vector);

                if ($queryTerms) {
                    $tokens = $this->stemKeys($this->tokenize($content));
                    $docs++;
                    $totalLength += count($tokens);
                    $counts = array_intersect_key(array_count_values($tokens), $termLookup);
                    foreach (array_keys($counts) as $term) {
                        $df[$term] = ($df[$term] ?? 0) + 1;
                    }
                    if ($counts) {
                        $this->offer($lexical, $poolSize, [
                            'id' => (int)$row['id'],
                            'sourceType' => (string)$row['sourceType'],
                            'sourceId' => (int)$row['sourceId'],
                            'content' => $content,
                            'score' => $score,
                            'termFrequencies' => $counts,
                            'length' => max(1, count($tokens)),
                            // Pruning proxy: idf is unknown mid-scan, so rank by
                            // how many query terms matched and how densely. Only
                            // decides which weak lexical candidates get dropped.
                            '_rank' => count($counts) + (array_sum($counts) / max(1, count($tokens))),
                        ], '_rank');
                    }
                }

                if ($score < $minScore) {
                    continue;
                }
                $entry = [
                    'id' => (int)$row['id'],
                    'sourceType' => (string)$row['sourceType'],
                    'sourceId' => (int)$row['sourceId'],
                    'content' => $content,
                    'score' => $score,
                ];
                if ($includeVectors) {
                    $entry['_vector'] = $vector;
                }
                $this->offer($cosine, $poolSize, $entry, 'score');
            }

            if (count($rows) < self::SCAN_BATCH) {
                break;
            }
        }

        return [
            'cosine' => $cosine,
            'lexical' => $lexical,
            'df' => $df,
            'docs' => $docs,
            'totalLength' => $totalLength,
        ];
    }

    /**
     * Add a candidate to a pool capped at $limit, dropping the current worst
     * once full. Keeps peak memory flat however many chunks are scanned.
     *
     * @param array<int, array<string, mixed>> $pool
     * @param array<string, mixed> $candidate
     */
    private function offer(array &$pool, int $limit, array $candidate, string $key): void
    {
        if (count($pool) < $limit) {
            $pool[] = $candidate;
            return;
        }
        $worstIndex = null;
        $worstValue = INF;
        foreach ($pool as $index => $existing) {
            if ($existing[$key] < $worstValue) {
                $worstValue = $existing[$key];
                $worstIndex = $index;
            }
        }
        if ($worstIndex !== null && $candidate[$key] > $worstValue) {
            $pool[$worstIndex] = $candidate;
        }
    }

    /**
     * Fuse the cosine and lexical rankings with Reciprocal Rank Fusion.
     *
     * @param array<int, array<string, mixed>> $byCosine
     * @param array{lexical: array<int, array<string, mixed>>, df: array<string, int>, docs: int, totalLength: int} $scan
     * @return array<int, array<string, mixed>>
     */
    private function fuse(array $byCosine, array $scan, int $k): array
    {
        $rrfK = max(1, (int)Plugin::getInstance()->getSettings()->rrfK);

        // Union the pools, keeping one row object per chunk.
        $rows = [];
        foreach ($byCosine as $row) {
            $rows[$row['id']] = $row;
        }
        foreach ($scan['lexical'] as $row) {
            if (!isset($rows[$row['id']])) {
                $rows[$row['id']] = [
                    'id' => $row['id'],
                    'sourceType' => $row['sourceType'],
                    'sourceId' => $row['sourceId'],
                    'content' => $row['content'],
                    'score' => $row['score'],
                ];
            }
        }

        $averageLength = $scan['docs'] > 0 ? $scan['totalLength'] / $scan['docs'] : 1.0;
        $bm25 = [];
        foreach ($scan['lexical'] as $row) {
            $bm25[$row['id']] = $this->bm25(
                $row['termFrequencies'],
                $row['length'],
                $scan['df'],
                $scan['docs'],
                $averageLength,
            );
        }

        $cosineOrder = array_keys($rows);
        usort($cosineOrder, fn($a, $b) => $rows[$b]['score'] <=> $rows[$a]['score']);
        $lexicalOrder = array_keys($bm25);
        usort($lexicalOrder, fn($a, $b) => $bm25[$b] <=> $bm25[$a]);

        $fused = array_fill_keys(array_keys($rows), 0.0);
        foreach ($cosineOrder as $rank => $id) {
            $fused[$id] += 1.0 / ($rrfK + $rank + 1);
        }
        foreach ($lexicalOrder as $rank => $id) {
            $fused[$id] += 1.0 / ($rrfK + $rank + 1);
        }
        arsort($fused);

        $out = [];
        foreach (array_slice(array_keys($fused), 0, $k) as $id) {
            $out[] = $rows[$id];
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
     * Okapi BM25 (k1=1.2, b=0.75) for one document, given corpus statistics
     * gathered over every chunk rather than only the shortlisted ones.
     *
     * @param array<string, int> $termFrequencies query terms present in the document
     * @param array<string, int> $df document frequency of each query term
     */
    private function bm25(array $termFrequencies, int $length, array $df, int $docs, float $averageLength): float
    {
        $k1 = 1.2;
        $b = 0.75;
        $score = 0.0;
        foreach ($termFrequencies as $term => $frequency) {
            $termDocs = $df[$term] ?? 0;
            $idf = log(1 + ($docs - $termDocs + 0.5) / ($termDocs + 0.5));
            $score += $idf * ($frequency * ($k1 + 1))
                / ($frequency + $k1 * (1 - $b + $b * ($length / max(1e-9, $averageLength))));
        }
        return $score;
    }

    /**
     * Reduce tokens to the prefix they are matched on.
     *
     * There is no stemmer here and there cannot be a good one for every
     * language a Craft site runs in. Comparing a leading slice instead handles
     * the common case anyway: inflection happens at the end of a word, so
     * "kosice" and "kosiciach", "dvere" and "dverami", "door" and "doors" all
     * share a prefix. Short words must still match exactly, since truncating
     * them would collide too much.
     *
     * @param string[] $tokens
     * @return string[]
     */
    private function stemKeys(array $tokens): array
    {
        $length = self::STEM_LENGTH;
        return array_map(
            fn(string $token): string => mb_strlen($token) > $length ? mb_substr($token, 0, $length) : $token,
            $tokens,
        );
    }

    /**
     * Lowercase, diacritic-folded, Unicode-aware word tokenizer.
     * Language-agnostic, no stemming or stopword list — keeps names and numbers
     * intact. Single-character tokens dropped as noise.
     *
     * Folding accents is what makes the lexical half work outside English:
     * visitors type "Kosice", "Prerov", "Dusseldorf" on keyboards that make the
     * accented form awkward, while the content is spelled "Košice", "Přerov",
     * "Düsseldorf". Without folding those never match and BM25 quietly
     * contributes nothing on exactly the queries it exists to rescue.
     *
     * @return string[]
     */
    private function tokenize(string $text): array
    {
        $text = self::foldDiacritics(mb_strtolower($text, 'UTF-8'));
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [];
        return array_values(array_filter($parts, fn($t) => mb_strlen($t) >= 2));
    }

    /**
     * Strip combining accents so "košický" and "kosicky" tokenize identically.
     */
    private static function foldDiacritics(string $text): string
    {
        if (!preg_match('/[^\x00-\x7F]/', $text)) {
            return $text;
        }
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                // Drop the combining marks left behind by decomposition. Scripts
                // that don't decompose (Greek, Cyrillic, CJK) pass through as-is,
                // which is correct — they are matched on their own letters.
                return (string)preg_replace('/\p{Mn}+/u', '', $decomposed);
            }
        }
        return \craft\helpers\StringHelper::toAscii($text);
    }

    /**
     * @param float[] $v
     */
    private function norm(array $v): float
    {
        $sum = 0.0;
        foreach ($v as $x) {
            $sum += $x * $x;
        }
        return sqrt($sum);
    }

    /**
     * @param float[] $a
     * @param float[] $b
     */
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
