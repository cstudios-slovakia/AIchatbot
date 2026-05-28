<?php

namespace cstudiossro\craftcschatbot\jobs;

use Craft;
use craft\queue\BaseJob;
use cstudiossro\craftcschatbot\Plugin;

class IndexGlobalSetJob extends BaseJob
{
    public int $globalSetId;
    public ?int $siteId = null;

    public function execute($queue): void
    {
        Plugin::getInstance()->training->trainGlobalSet($this->globalSetId, $this->siteId);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('interactive-ai-assistant', 'Indexing global set #{id}', ['id' => $this->globalSetId]);
    }
}
