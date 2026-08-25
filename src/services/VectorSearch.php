<?php

namespace cstudiossro\craftcschatbot\services;

use Craft;
use cstudiossro\craftcschatbot\helpers\Vector;
use cstudiossro\craftcschatbot\Plugin;
use yii\base\Component;
use yii\caching\TagDependency;

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
     * How long BM25 corpus statistics stay cached.
     *
     * They describe the whole trained corpus — how many chunks there are, how
     * long they are on average, and how many contain each term — so they only
     * move when training does. Recomputing them on every query meant tokenizing
     * every chunk of the site to answer one question. The TTL is a backstop for
     * a write that forgot to invalidate; ranking weights an hour out of date
     * shift nothing a visitor would notice.
     */
    private const STATS_CACHE_DURATION = 3600;

    /** Cache tag dropped whenever the chunk table changes. */
    public const CORPUS_CACHE_TAG = 'chatbot-chunks';

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
        // Resolved once, here: the scan filters on it and the corpus statistics
        // are cached per filtered set, so the two have to agree on what "the
        // corpus" means for this query.
        $scopeSiteId = ($siteId !== null && $settings->siteFilterEnabled) ? $siteId : null;

        $scan = $this->scan($query, $queryNorm, $minScore, $queryTerms, $poolSize, $includeVectors, $scopeSiteId);
        $byCosine = $scan['cosine'];
        if (empty($byCosine)) {
            return [];
        }

        if (!$hybrid || empty($queryTerms) || empty($scan['lexical'])) {
            usort($byCosine, fn($a, $b) => $b['score'] <=> $a['score']);
            return array_slice(array_values($byCosine), 0, $k);
        }

        return $this->fuse($byCosine, $scan['lexical'], $this->corpusStats($scopeSiteId), $k);
    }

    /**
     * One streaming pass over the chunk table.
     *
     * Returns two bounded candidate pools — the best by cosine, and the best by
     * lexical overlap. Keeping a lexical pool of its own is what lets an
     * exact-term match survive that the embedding ranked poorly, which is the
     * entire reason for hybrid search. The corpus statistics BM25 also needs are
     * not gathered here — see {@see self::corpusStats()}.
     *
     * @param float[] $query
     * @param string[] $queryTerms
     * @param int|null $siteId already-resolved site filter (null = whole corpus)
     * @return array{
     *   cosine: array<int, array<string, mixed>>,
     *   lexical: array<int, array<string, mixed>>
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
        $termLookup = array_flip($queryTerms);

        $cosine = [];
        $lexical = [];
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
            if ($siteId !== null) {
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
                    $folded = self::foldDiacritics(mb_strtolower($content, 'UTF-8'));
                    // Prefilter before tokenizing. Splitting a chunk into words,
                    // stemming them and counting them is the most expensive thing
                    // this loop does, and on any real query the overwhelming
                    // majority of chunks share no term with it at all. A plain
                    // substring test over-approximates the stem match (the stem
                    // may sit mid-word), so it costs the occasional wasted
                    // tokenize and can never drop a genuine candidate.
                    if ($this->mayContainTerm($folded, $queryTerms)) {
                        $tokens = $this->stemKeys($this->tokenizeFolded($folded));
                        $counts = array_intersect_key(array_count_values($tokens), $termLookup);
                        if ($counts) {
                            $this->offer($lexical, $poolSize, [
                                'id' => (int)$row['id'],
                                'sourceType' => (string)$row['sourceType'],
                                'sourceId' => (int)$row['sourceId'],
                                'content' => $content,
                                'score' => $score,
                                'termFrequencies' => $counts,
                                'length' => max(1, count($tokens)),
                                // Pruning proxy: idf is unknown mid-scan, so rank
                                // by how many query terms matched and how densely.
                                // Only decides which weak lexical candidates get
                                // dropped.
                                '_rank' => count($counts) + (array_sum($counts) / max(1, count($tokens))),
                            ], '_rank');
                        }
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
     * @param array<int, array<string, mixed>> $lexical
     * @param array{df: array<string, int>, docs: int, totalLength: int} $stats
     * @return array<int, array<string, mixed>>
     */
    private function fuse(array $byCosine, array $lexical, array $stats, int $k): array
    {
        $rrfK = max(1, (int)Plugin::getInstance()->getSettings()->rrfK);

        // Union the pools, keeping one row object per chunk.
        $rows = [];
        foreach ($byCosine as $row) {
            $rows[$row['id']] = $row;
        }
        foreach ($lexical as $row) {
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

        $averageLength = $stats['docs'] > 0 ? $stats['totalLength'] / $stats['docs'] : 1.0;
        $bm25 = [];
        foreach ($lexical as $row) {
            $bm25[$row['id']] = $this->bm25(
                $row['termFrequencies'],
                $row['length'],
                $stats['df'],
                $stats['docs'],
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
     * Corpus statistics BM25 needs: how many chunks there are, how long they are
     * in total, and how many contain each stem.
     *
     * Cached per filtered corpus, because they are a property of what has been
     * trained rather than of the query. Gathering them used to be folded into
     * the per-query scan, which meant every question tokenized and stemmed every
     * chunk on the site before it could be answered — the single most expensive
     * step of retrieval, repeated for a result that had not changed since the
     * last training run. The first query after a retrain rebuilds them (one pass
     * over `content`, no vectors read); every query after that reads the cache.
     *
     * @param int|null $siteId already-resolved site filter (null = whole corpus)
     * @return array{df: array<string, int>, docs: int, totalLength: int}
     */
    private function corpusStats(?int $siteId): array
    {
        $cache = Craft::$app->getCache();
        $key = ['chatbot-bm25-corpus-stats', $siteId];
        $stats = $cache->get($key);
        if (is_array($stats) && isset($stats['df'], $stats['docs'], $stats['totalLength'])) {
            return $stats;
        }
        $stats = $this->buildCorpusStats($siteId);
        $cache->set(
            $key,
            $stats,
            self::STATS_CACHE_DURATION,
            new TagDependency(['tags' => self::CORPUS_CACHE_TAG]),
        );
        return $stats;
    }

    /**
     * One pass over every indexed chunk's text, counting document frequency for
     * the whole vocabulary. Stems are capped at {@see self::STEM_LENGTH}
     * characters, so the vocabulary stays bounded however much content is
     * trained. Deliberately selects no vectors — this pass is about words.
     *
     * @return array{df: array<string, int>, docs: int, totalLength: int}
     */
    private function buildCorpusStats(?int $siteId): array
    {
        $df = [];
        $docs = 0;
        $totalLength = 0;
        $lastId = 0;

        while (true) {
            $rowsQuery = (new \craft\db\Query())
                ->select(['id', 'content'])
                ->from('{{%chatbot_chunks}}')
                ->where(['>', 'id', $lastId])
                // Same population the scan ranks over: a chunk with no vector is
                // never retrieved, so it must not weigh on the statistics either.
                ->andWhere([
                    'or',
                    ['not', ['embeddingBlob' => null]],
                    ['not', ['embedding' => null]],
                ])
                ->orderBy(['id' => SORT_ASC])
                ->limit(self::SCAN_BATCH);
            if ($siteId !== null) {
                $rowsQuery->andWhere(['or', ['siteId' => $siteId], ['siteId' => null]]);
            }
            $rows = $rowsQuery->all(Craft::$app->db);
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $tokens = $this->stemKeys($this->tokenize((string)$row['content']));
                $docs++;
                $totalLength += count($tokens);
                foreach (array_keys(array_count_values($tokens)) as $term) {
                    $df[$term] = ($df[$term] ?? 0) + 1;
                }
            }

            if (count($rows) < self::SCAN_BATCH) {
                break;
            }
        }

        return ['df' => $df, 'docs' => $docs, 'totalLength' => $totalLength];
    }

    /**
     * Drop the cached corpus statistics. Called wherever the chunk table is
     * written, so the next query rebuilds them from the new content.
     */
    public static function invalidateCorpusStats(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), self::CORPUS_CACHE_TAG);
    }

    /**
     * Whether a chunk's folded text could contain any of the query stems.
     *
     * A substring test, not a token test: it answers "is it worth tokenizing
     * this chunk" and nothing more. False positives are harmless, false
     * negatives would silently lose lexical matches — so it must stay a
     * superset of what the real stem comparison would accept.
     *
     * @param string[] $queryTerms
     */
    private function mayContainTerm(string $folded, array $queryTerms): bool
    {
        foreach ($queryTerms as $term) {
            if ($term !== '' && str_contains($folded, $term)) {
                return true;
            }
        }
        return false;
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
        return $this->tokenizeFolded(self::foldDiacritics(mb_strtolower($text, 'UTF-8')));
    }

    /**
     * The splitting half of {@see self::tokenize()}, for callers that already
     * hold the lowercased, diacritic-folded text and must not fold it twice.
     *
     * @return string[]
     */
    private function tokenizeFolded(string $folded): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $folded) ?: [];
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
