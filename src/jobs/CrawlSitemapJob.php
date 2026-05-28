<?php

namespace cstudiossro\craftcschatbot\jobs;

use Craft;
use craft\queue\BaseJob;
use cstudiossro\craftcschatbot\Plugin;
use cstudiossro\craftcschatbot\records\TrainingUrlRecord;

class CrawlSitemapJob extends BaseJob
{
    public string $sitemapUrl;
    public bool $autoIndex = true;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $urls = $plugin->training->importSitemap($this->sitemapUrl);
        if (!$this->autoIndex) {
            return;
        }
        $pending = TrainingUrlRecord::find()->where(['status' => 'pending'])->all();
        $total = count($pending);
        $i = 0;
        foreach ($pending as $rec) {
            $this->setProgress($queue, $total > 0 ? $i / $total : 1);
            Craft::$app->queue->push(new IndexUrlJob(['urlRecId' => (int)$rec->id]));
            $i++;
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('_cs-chatbot', 'Importing sitemap');
    }
}
