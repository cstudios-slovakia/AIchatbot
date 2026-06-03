<?php

namespace cstudiossro\craftcschatbot\jobs;

use Craft;
use craft\queue\BaseJob;
use cstudiossro\craftcschatbot\Plugin;

class IndexSourceJob extends BaseJob
{
    public string $handle = '';
    public int $itemId = 0;
    public ?int $siteId = null;

    public function execute($queue): void
    {
        Plugin::getInstance()->training->trainSource($this->handle, $this->itemId, $this->siteId);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('interactive-ai-assistant', 'Indexing {handle} #{id}', [
            'handle' => $this->handle,
            'id' => $this->itemId,
        ]);
    }
}
