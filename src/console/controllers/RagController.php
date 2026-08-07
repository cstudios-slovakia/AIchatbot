<?php

namespace cstudiossro\craftcschatbot\console\controllers;

use craft\console\Controller;
use cstudiossro\craftcschatbot\Plugin;
use yii\console\ExitCode;

/**
 * RAG maintenance commands.
 *
 * Usage: php craft interactive-ai-assistant/rag/retrain-all
 */
class RagController extends Controller
{
    /**
     * Re-chunk and re-embed every trained source under the current indexing
     * settings (chunk size, contextual prefix, embedding model/dimensions).
     * Run after changing those settings or upgrading the plugin's chunker.
     */
    public function actionRetrainAll(): int
    {
        $types = null;
        if ($this->only !== null) {
            $types = array_values(array_filter(array_map('trim', explode(',', $this->only))));
            $this->stdout('Limiting to: ' . implode(', ', $types) . "\n");
        }
        $this->stdout("Queuing trained sources for re-indexing...\n");
        $count = Plugin::getInstance()->training->reindexAll($types);
        $this->stdout("Queued/re-indexed {$count} source(s).\n");
        $this->stdout("Run the queue to process: php craft queue/run\n");
        return ExitCode::OK;
    }

    /**
     * Session token to continue, so multi-turn behaviour can be exercised from
     * the CLI. Omit to start a fresh conversation.
     */
    public ?string $session = null;

    /**
     * Page URL the question is asked from. Determines which Craft site (and so
     * which system prompt, language and chunk filter) the answer uses.
     */
    public ?string $pageUrl = null;

    /**
     * Comma-separated source kinds to retrain: entries, categories, globals,
     * files, urls, sources, qa. Omit for everything. Lets you re-embed local
     * content without re-crawling remote URLs.
     */
    public ?string $only = null;

    /** Re-queue the sources whose content changed since they were indexed. */
    public bool $fix = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'ask') {
            $options[] = 'session';
            $options[] = 'pageUrl';
        }
        if ($actionID === 'retrain-all') {
            $options[] = 'only';
        }
        if ($actionID === 'doctor') {
            $options[] = 'fix';
        }
        return $options;
    }

    /**
     * Ask the assistant a question and print the reply with its retrieval
     * confidence and timing — the same path the widget uses.
     *
     * Usage: php craft interactive-ai-assistant/rag/ask "how much are the doors?"
     *        php craft interactive-ai-assistant/rag/ask "and the other ones?" --session=<token>
     */
    public function actionAsk(string $question): int
    {
        $start = microtime(true);
        $result = Plugin::getInstance()->chat->ask($question, $this->session, $this->pageUrl);
        $wall = microtime(true) - $start;

        $this->stdout("\n" . ($result['reply'] ?? '(no reply)') . "\n\n");
        $this->stdout(sprintf(
            "confidence %.3f · model %.2fs · total %.2fs · offerHuman %s\nsession %s\n",
            (float)($result['confidence'] ?? 0),
            (float)($result['responseTime'] ?? 0),
            $wall,
            !empty($result['offerHuman']) ? 'yes' : 'no',
            (string)($result['sessionToken'] ?? '?'),
        ));
        return ExitCode::OK;
    }

    /**
     * Report what is wrong with the index: content edited since it was indexed,
     * sources that failed or indexed to nothing, records whose element is gone,
     * and sections selected for training that still have entries nobody indexed.
     *
     * Usage: php craft interactive-ai-assistant/rag/doctor
     *        php craft interactive-ai-assistant/rag/doctor --fix
     */
    public function actionDoctor(): int
    {
        $training = Plugin::getInstance()->training;
        $health = $training->indexHealth();

        $this->stdout(sprintf(
            "%d chunk(s) indexed.\n\n",
            $health['totals']['chunks'],
        ));

        $describe = function (array $items, string $heading, callable $line): void {
            if (!$items) {
                return;
            }
            $this->stdout($heading . ' (' . count($items) . ")\n");
            foreach (array_slice($items, 0, 20) as $item) {
                $this->stdout('  ' . $line($item) . "\n");
            }
            if (count($items) > 20) {
                $this->stdout('  … and ' . (count($items) - 20) . " more\n");
            }
            $this->stdout("\n");
        };

        $describe($health['stale'], 'Changed since last indexed', fn($i) => "{$i['kind']} #{$i['id']}");
        $describe($health['failed'], 'Failed to index', fn($i) => "{$i['kind']} #{$i['id']}: {$i['message']}");
        $describe($health['blank'], 'Indexed to nothing (no text extracted)', fn($i) => "{$i['kind']} #{$i['id']}");
        $describe($health['orphaned'], 'Element no longer exists', fn($i) => "{$i['kind']} #{$i['id']}");

        if ($health['untrainedBySection']) {
            $this->stdout("Selected for training but never indexed\n");
            foreach ($health['untrainedBySection'] as $section => $count) {
                $this->stdout("  {$section}: {$count} live entr" . ($count === 1 ? 'y' : 'ies') . "\n");
            }
            $this->stdout("\n");
        }

        if ($health['totals']['sources'] === 0 && !$health['untrainedBySection']) {
            $this->stdout("Index is healthy.\n");
            return ExitCode::OK;
        }

        if ($this->fix) {
            $queued = $training->retrainStale();
            $this->stdout("Queued {$queued} changed source(s) for re-indexing. Run: php craft queue/run\n");
        } elseif ($health['stale']) {
            $this->stdout("Re-index the changed ones with --fix.\n");
        }
        return ExitCode::OK;
    }

    /**
     * List the questions the assistant answered with little or nothing to go on.
     *
     * Usage: php craft interactive-ai-assistant/rag/gaps
     */
    public function actionGaps(int $limit = 25): int
    {
        $gaps = Plugin::getInstance()->gaps;
        $result = $gaps->list(1, $limit);
        if (!$result['rows']) {
            $this->stdout("No gaps — every answered question had something to draw on.\n");
            return ExitCode::OK;
        }
        $this->stdout("{$result['total']} question(s) worth answering:\n\n");
        foreach ($result['rows'] as $row) {
            $this->stdout(sprintf(
                "  #%-5d %-15s %2d chunk(s) %.2f  %s\n",
                $row['id'],
                $row['reason'],
                (int)$row['contextChunks'],
                (float)$row['confidence'],
                mb_substr(preg_replace('/\s+/', ' ', (string)($row['question'] ?? '—')), 0, 70),
            ));
        }
        $common = $gaps->commonQuestions();
        $repeated = array_values(array_filter($common, fn($c) => $c['hits'] > 1));
        if ($repeated) {
            $this->stdout("\nAsked more than once:\n");
            foreach ($repeated as $item) {
                $this->stdout("  {$item['hits']}x  {$item['question']}\n");
            }
        }
        $this->stdout("\nAnswer them under Training -> Gaps in the control panel.\n");
        return ExitCode::OK;
    }

    /**
     * Print the exact text an entry contributes to the index, and how it chunks.
     * The first thing to check when the assistant "doesn't know" about a page:
     * it answers whether the content ever reached the index at all.
     *
     * Usage: php craft interactive-ai-assistant/rag/extract 3401
     */
    public function actionExtract(int $entryId, ?int $siteId = null): int
    {
        $entry = \craft\elements\Entry::find()->id($entryId)->siteId($siteId)->status(null)->one();
        if (!$entry) {
            $this->stderr("No entry {$entryId}.\n");
            return ExitCode::DATAERR;
        }
        $plugin = Plugin::getInstance();
        $text = $plugin->training->extractElementText($entry);
        $chunks = $plugin->embeddings->chunk($text);

        $this->stdout("--- extracted text (" . mb_strlen($text) . " chars) ---\n");
        $this->stdout($text . "\n\n");
        $this->stdout('--- ' . count($chunks) . " chunk(s) ---\n");
        foreach ($chunks as $i => $chunk) {
            $this->stdout(sprintf(
                "[%d] section=%s len=%d\n",
                $i + 1,
                $chunk['section'] ?? '-',
                mb_strlen($chunk['content']),
            ));
        }
        return ExitCode::OK;
    }

    /**
     * Show what retrieval returns for a query, so a bad answer can be traced to
     * bad context rather than guessed at.
     *
     * Usage: php craft interactive-ai-assistant/rag/retrieve "opening hours" 8
     */
    public function actionRetrieve(string $query, int $limit = 8): int
    {
        $plugin = Plugin::getInstance();
        $vector = $plugin->openAi->embed([$query])[0] ?? [];
        if (!$vector) {
            $this->stderr("Could not embed the query.\n");
            return ExitCode::UNAVAILABLE;
        }
        $hits = $plugin->vectorSearch->topK($vector, $limit, 0.0, $query);
        if (!$hits) {
            $this->stdout("No chunks matched — is anything trained?\n");
            return ExitCode::OK;
        }
        $floor = (float)$plugin->getSettings()->minSimilarityScore;
        foreach ($hits as $i => $hit) {
            $snippet = preg_replace('/\s+/', ' ', (string)$hit['content']);
            $this->stdout(sprintf(
                "%2d. %.4f %s %s#%d\n    %s\n",
                $i + 1,
                $hit['score'],
                $hit['score'] >= $floor ? '  ' : '(below floor)',
                $hit['sourceType'],
                $hit['sourceId'],
                mb_substr((string)$snippet, 0, 160),
            ));
        }
        return ExitCode::OK;
    }
}
