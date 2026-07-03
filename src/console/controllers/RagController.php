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
        $this->stdout("Queuing all trained sources for re-indexing...\n");
        $count = Plugin::getInstance()->training->reindexAll();
        $this->stdout("Queued/re-indexed {$count} source(s).\n");
        $this->stdout("Run the queue to process: php craft queue/run\n");
        return ExitCode::OK;
    }
}
