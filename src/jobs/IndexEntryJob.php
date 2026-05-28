<?php

namespace cstudiossro\craftcschatbot\jobs;

use Craft;
use craft\queue\BaseJob;
use cstudiossro\craftcschatbot\Plugin;

class IndexEntryJob extends BaseJob
{
    public int $entryId;
    public ?int $siteId = null;

    public function execute($queue): void
    {
        Plugin::getInstance()->training->trainEntry($this->entryId, $this->siteId);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('interactive-ai-assistant', 'Indexing entry #{id}', ['id' => $this->entryId]);
    }
}
