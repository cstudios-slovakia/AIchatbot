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
    /** Site the discovered URLs belong to; null = all sites. */
    public ?int $siteId = null;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $urls = $plugin->training->importSitemap($this->sitemapUrl, $this->siteId);
        if (!$this->autoIndex) {
            return;
        }
        // Only the URLs this import just discovered — another pending URL may be
        // waiting for a different sitemap, or for a person who added it by hand.
        $pending = $urls
            ? TrainingUrlRecord::find()->where(['status' => 'pending', 'url' => $urls])->all()
            : [];
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
        return Craft::t('interactive-ai-assistant', 'Importing sitemap');
    }
}
