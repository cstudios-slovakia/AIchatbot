<?php

namespace cstudiossro\craftcschatbot\jobs;

use Craft;
use craft\queue\BaseJob;
use cstudiossro\craftcschatbot\Plugin;

class IndexFileJob extends BaseJob
{
    public int $fileRecId;
    public string $absolutePath;

    public function execute($queue): void
    {
        Plugin::getInstance()->training->trainFile($this->fileRecId, $this->absolutePath);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('interactive-ai-assistant', 'Indexing training file #{id}', ['id' => $this->fileRecId]);
    }
}
