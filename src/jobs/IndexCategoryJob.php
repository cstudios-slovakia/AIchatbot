<?php

namespace cstudiossro\craftcschatbot\jobs;

use Craft;
use craft\queue\BaseJob;
use cstudiossro\craftcschatbot\Plugin;

class IndexCategoryJob extends BaseJob
{
    public int $categoryId;
    public ?int $siteId = null;

    public function execute($queue): void
    {
        Plugin::getInstance()->training->trainCategory($this->categoryId, $this->siteId);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('interactive-ai-assistant', 'Indexing category #{id}', ['id' => $this->categoryId]);
    }
}
