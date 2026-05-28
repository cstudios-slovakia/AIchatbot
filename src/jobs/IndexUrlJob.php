<?php

namespace cstudiossro\craftcschatbot\jobs;

use Craft;
use craft\queue\BaseJob;
use cstudiossro\craftcschatbot\Plugin;

class IndexUrlJob extends BaseJob
{
    public int $urlRecId;

    public function execute($queue): void
    {
        Plugin::getInstance()->training->trainUrl($this->urlRecId);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('_cs-chatbot', 'Crawling URL #{id}', ['id' => $this->urlRecId]);
    }
}
