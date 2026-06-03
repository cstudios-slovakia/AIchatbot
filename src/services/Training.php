<?php

namespace cstudiossro\craftcschatbot\services;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\helpers\Db;
use cstudiossro\craftcschatbot\Plugin;
use cstudiossro\craftcschatbot\records\TrainingCategoryRecord;
use cstudiossro\craftcschatbot\records\TrainingEntryRecord;
use cstudiossro\craftcschatbot\records\TrainingFileRecord;
use cstudiossro\craftcschatbot\records\TrainingGlobalSetRecord;
use cstudiossro\craftcschatbot\records\TrainingQaRecord;
use cstudiossro\craftcschatbot\records\TrainingUrlRecord;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use Throwable;
use yii\base\Component;

class Training extends Component
{
    // ---------- ENTRIES ----------

    public function trainEntry(int $entryId, ?int $siteId = null): void
    {
        $entry = Entry::find()->id($entryId)->siteId($siteId)->status(null)->one();
        if (!$entry) {
            $this->markEntryError($entryId, $siteId ?? 0, 'Entry not found');
            return;
        }
        $rec = TrainingEntryRecord::findOne(['entryId' => $entry->id, 'siteId' => $entry->siteId])
            ?? new TrainingEntryRecord();
        $rec->entryId = $entry->id;
        $rec->siteId = $entry->siteId;
        $rec->sectionId = (int)$entry->sectionId;
        $rec->status = 'training';
        $rec->errorMessage = null;
        $rec->save(false);

        try {
            $text = $this->extractEntryText($entry);
            $count = Plugin::getInstance()->embeddings->reindexSource('entry', (int)$rec->id, $text);
            $rec->chunkCount = $count;
            $rec->status = $count > 0 ? 'indexed' : 'empty';
            $rec->lastTrainedAt = Db::prepareDateForDb(new \DateTime());
            $rec->save(false);
        } catch (Throwable $e) {
            $rec->status = 'error';
            $rec->errorMessage = $e->getMessage();
            $rec->save(false);
            throw $e;
        }
    }

    public function removeEntry(int $trainingEntryId): void
    {
        $rec = TrainingEntryRecord::findOne($trainingEntryId);
        if (!$rec) {
            return;
        }
        Plugin::getInstance()->embeddings->deleteChunks('entry', (int)$rec->id);
        $rec->delete();
    }

    private function markEntryError(int $entryId, int $siteId, string $message): void
    {
        $rec = TrainingEntryRecord::findOne(['entryId' => $entryId, 'siteId' => $siteId])
            ?? new TrainingEntryRecord();
        $rec->entryId = $entryId;
        $rec->siteId = $siteId ?: 1;
        $rec->sectionId = $rec->sectionId ?: 0;
        $rec->status = 'error';
        $rec->errorMessage = $message;
        $rec->save(false);
    }

    private function extractEntryText(Entry $entry): string
    {
        $parts = [$entry->title ?? ''];
        foreach ($entry->getFieldValues() as $handle => $value) {
            $parts[] = $this->fieldValueToText($value);
        }
        $text = implode("\n\n", array_filter(array_map('trim', $parts)));
        return Plugin::getInstance()->embeddings->normalize($text);
    }

    /**
     * Turn any Craft field value into plain, searchable text.
     *
     * Handles the built-in field data types — plain text/number/date,
     * dropdowns & radio buttons, checkboxes & multi-select, colours,
     * tables, and relation/Matrix fields (recursing into related elements
     * and Matrix blocks so their content adds context). $depth bounds the
     * relation recursion so cyclic relations can't loop forever.
     */
    private function fieldValueToText(mixed $value, int $depth = 0): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            // Lightswitch — the on/off flag carries no useful search text.
            return '';
        }
        if (is_numeric($value)) {
            return (string)$value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        // Checkboxes / multi-select — a collection of options.
        if ($value instanceof \craft\fields\data\MultiOptionFieldData) {
            $parts = [];
            foreach ($value as $opt) {
                $parts[] = $this->optionToText($opt);
            }
            return implode("\n", array_filter($parts));
        }
        // Dropdown / radio — a single option (prefer label over stored value).
        if ($value instanceof \craft\fields\data\OptionData) {
            return $this->optionToText($value);
        }
        // Relations (Entries/Assets/Categories/Users/Tags) and Matrix.
        if ($value instanceof \craft\elements\db\ElementQuery) {
            if ($depth >= 2) {
                return '';
            }
            $parts = [];
            foreach ($value->all() as $el) {
                $parts[] = $this->elementToText($el, $depth + 1);
            }
            return implode("\n", array_filter($parts));
        }
        // Table rows and other plain arrays.
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $v) {
                $parts[] = $this->fieldValueToText($v, $depth);
            }
            return implode("\n", array_filter($parts));
        }
        // Anything else that can stringify itself (ColorData, Money, Twig\Markup, …).
        if (is_object($value) && method_exists($value, '__toString')) {
            try {
                return (string)$value;
            } catch (\Throwable) {
                return '';
            }
        }
        return '';
    }

    /**
     * Human-readable text for a single option (dropdown/radio/checkbox item).
     * Falls back to the stored value when an option has no label.
     */
    private function optionToText(mixed $opt): string
    {
        if ($opt instanceof \craft\fields\data\OptionData) {
            $label = trim((string)($opt->label ?? ''));
            if ($label !== '') {
                return $label;
            }
            return trim((string)($opt->value ?? ''));
        }
        return is_scalar($opt) ? trim((string)$opt) : '';
    }

    /**
     * Text for a related element or Matrix block: its title plus, one level
     * deeper, its own custom field values (so Matrix content is captured).
     */
    private function elementToText(mixed $el, int $depth): string
    {
        if (!is_object($el)) {
            return is_scalar($el) ? trim((string)$el) : '';
        }
        $parts = [];
        if (!empty($el->title)) {
            $parts[] = (string)$el->title;
        }
        if ($el instanceof \craft\elements\Asset && !empty($el->alt)) {
            $parts[] = (string)$el->alt;
        }
        if ($depth < 2 && method_exists($el, 'getFieldValues')) {
            try {
                foreach ($el->getFieldValues() as $v) {
                    $t = $this->fieldValueToText($v, $depth + 1);
                    if ($t !== '') {
                        $parts[] = $t;
                    }
                }
            } catch (\Throwable) {
                // ignore unreadable field values
            }
        }
        $parts = array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== ''));
        return implode("\n", array_unique($parts));
    }

    // ---------- CATEGORIES ----------

    public function trainCategory(int $categoryId, ?int $siteId = null): void
    {
        $cat = Category::find()->id($categoryId)->siteId($siteId)->status(null)->one();
        if (!$cat) {
            return;
        }
        $rec = TrainingCategoryRecord::findOne(['categoryId' => $cat->id, 'siteId' => $cat->siteId])
            ?? new TrainingCategoryRecord();
        $rec->categoryId = (int)$cat->id;
        $rec->siteId = (int)$cat->siteId;
        $rec->groupId = (int)$cat->groupId;
        $rec->status = 'training';
        $rec->errorMessage = null;
        $rec->save(false);

        try {
            $text = $this->extractElementText($cat);
            $count = Plugin::getInstance()->embeddings->reindexSource('category', (int)$rec->id, $text);
            $rec->chunkCount = $count;
            $rec->status = $count > 0 ? 'indexed' : 'empty';
            $rec->lastTrainedAt = Db::prepareDateForDb(new \DateTime());
            $rec->save(false);
        } catch (Throwable $e) {
            $rec->status = 'error';
            $rec->errorMessage = $e->getMessage();
            $rec->save(false);
            throw $e;
        }
    }

    public function removeCategory(int $trainingCategoryId): void
    {
        $rec = TrainingCategoryRecord::findOne($trainingCategoryId);
        if (!$rec) {
            return;
        }
        Plugin::getInstance()->embeddings->deleteChunks('category', (int)$rec->id);
        $rec->delete();
    }

    // ---------- GLOBALS ----------

    public function trainGlobalSet(int $globalSetId, ?int $siteId = null): void
    {
        $set = GlobalSet::find()->id($globalSetId)->siteId($siteId)->status(null)->one();
        if (!$set) {
            return;
        }
        $rec = TrainingGlobalSetRecord::findOne(['globalSetId' => $set->id, 'siteId' => $set->siteId])
            ?? new TrainingGlobalSetRecord();
        $rec->globalSetId = (int)$set->id;
        $rec->siteId = (int)$set->siteId;
        $rec->status = 'training';
        $rec->errorMessage = null;
        $rec->save(false);

        try {
            $text = $this->extractElementText($set, prefix: (string)($set->name ?? ''));
            $count = Plugin::getInstance()->embeddings->reindexSource('global', (int)$rec->id, $text);
            $rec->chunkCount = $count;
            $rec->status = $count > 0 ? 'indexed' : 'empty';
            $rec->lastTrainedAt = Db::prepareDateForDb(new \DateTime());
            $rec->save(false);
        } catch (Throwable $e) {
            $rec->status = 'error';
            $rec->errorMessage = $e->getMessage();
            $rec->save(false);
            throw $e;
        }
    }

    public function removeGlobalSet(int $trainingGlobalId): void
    {
        $rec = TrainingGlobalSetRecord::findOne($trainingGlobalId);
        if (!$rec) {
            return;
        }
        Plugin::getInstance()->embeddings->deleteChunks('global', (int)$rec->id);
        $rec->delete();
    }

    private function extractElementText(\craft\base\Element $el, string $prefix = ''): string
    {
        $parts = [];
        if ($prefix !== '') {
            $parts[] = $prefix;
        }
        if (property_exists($el, 'title') && $el->title) {
            $parts[] = (string)$el->title;
        }
        foreach ($el->getFieldValues() as $value) {
            $parts[] = $this->fieldValueToText($value);
        }
        $text = implode("\n\n", array_filter(array_map('trim', $parts)));
        return Plugin::getInstance()->embeddings->normalize($text);
    }

    // ---------- FILES ----------

    public function trainFile(int $fileRecId, string $absolutePath): void
    {
        $rec = TrainingFileRecord::findOne($fileRecId);
        if (!$rec) {
            return;
        }
        $rec->status = 'training';
        $rec->errorMessage = null;
        $rec->save(false);
        try {
            if (!is_file($absolutePath)) {
                throw new RuntimeException('File missing: ' . $absolutePath);
            }
            $raw = file_get_contents($absolutePath) ?: '';
            $count = Plugin::getInstance()->embeddings->reindexSource('file', (int)$rec->id, $raw);
            $rec->chunkCount = $count;
            $rec->status = $count > 0 ? 'indexed' : 'empty';
            $rec->lastTrainedAt = Db::prepareDateForDb(new \DateTime());
            $rec->save(false);
        } catch (Throwable $e) {
            $rec->status = 'error';
            $rec->errorMessage = $e->getMessage();
            $rec->save(false);
            throw $e;
        }
    }

    public function removeFile(int $fileRecId): void
    {
        $rec = TrainingFileRecord::findOne($fileRecId);
        if (!$rec) {
            return;
        }
        Plugin::getInstance()->embeddings->deleteChunks('file', (int)$rec->id);
        $path = Plugin::getInstance()->getUploadPath() . DIRECTORY_SEPARATOR . $rec->filename;
        if (is_file($path)) {
            @unlink($path);
        }
        $rec->delete();
    }

    // ---------- URLS ----------

    public function trainUrl(int $urlRecId): void
    {
        $rec = TrainingUrlRecord::findOne($urlRecId);
        if (!$rec) {
            return;
        }
        $rec->status = 'crawling';
        $rec->errorMessage = null;
        $rec->save(false);
        try {
            $html = $this->fetch($rec->url);
            $text = $this->htmlToText($html);
            $count = Plugin::getInstance()->embeddings->reindexSource('url', (int)$rec->id, $text);
            $rec->chunkCount = $count;
            $rec->status = $count > 0 ? 'indexed' : 'empty';
            $rec->lastTrainedAt = Db::prepareDateForDb(new \DateTime());
            $rec->save(false);
        } catch (Throwable $e) {
            $rec->status = 'error';
            $rec->errorMessage = $e->getMessage();
            $rec->save(false);
            throw $e;
        }
    }

    public function removeUrl(int $urlRecId): void
    {
        $rec = TrainingUrlRecord::findOne($urlRecId);
        if (!$rec) {
            return;
        }
        Plugin::getInstance()->embeddings->deleteChunks('url', (int)$rec->id);
        $rec->delete();
    }

    /**
     * @return string[] URLs discovered
     */
    public function importSitemap(string $sitemapUrl): array
    {
        $xml = $this->fetch($sitemapUrl);
        $urls = $this->parseSitemap($xml);
        foreach ($urls as $u) {
            $existing = TrainingUrlRecord::find()
                ->where(['url' => $u])
                ->one();
            if ($existing) {
                continue;
            }
            $rec = new TrainingUrlRecord();
            $rec->url = $u;
            $rec->source = 'sitemap';
            $rec->status = 'pending';
            $rec->save(false);
        }
        return $urls;
    }

    /**
     * @return string[]
     */
    private function parseSitemap(string $xml): array
    {
        $urls = [];
        try {
            $sx = @new \SimpleXMLElement($xml);
        } catch (Throwable $e) {
            throw new RuntimeException('Invalid sitemap XML: ' . $e->getMessage());
        }
        // sitemap index
        if (isset($sx->sitemap)) {
            foreach ($sx->sitemap as $sm) {
                $loc = (string)$sm->loc;
                if ($loc !== '') {
                    try {
                        $childXml = $this->fetch($loc);
                        $urls = array_merge($urls, $this->parseSitemap($childXml));
                    } catch (Throwable) {
                        // skip
                    }
                }
            }
        }
        // urlset
        if (isset($sx->url)) {
            foreach ($sx->url as $u) {
                $loc = (string)$u->loc;
                if ($loc !== '') {
                    $urls[] = $loc;
                }
            }
        }
        return array_values(array_unique($urls));
    }

    private function fetch(string $url): string
    {
        $client = Craft::createGuzzleClient([
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'cs-chatbot-crawler/1.0',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
            'allow_redirects' => true,
        ]);
        try {
            $res = $client->get($url);
            return (string)$res->getBody();
        } catch (GuzzleException $e) {
            throw new RuntimeException('Fetch failed for ' . $url . ': ' . $e->getMessage(), 0, $e);
        }
    }

    private function htmlToText(string $html): string
    {
        // strip script/style first
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<nav\b[^>]*>.*?</nav>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<footer\b[^>]*>.*?</footer>#is', ' ', $html) ?? $html;
        return Plugin::getInstance()->embeddings->normalize($html);
    }

    // ---------- Q&A ----------

    public function trainQa(int $qaId): void
    {
        $rec = TrainingQaRecord::findOne($qaId);
        if (!$rec) {
            return;
        }
        if (!$rec->active) {
            Plugin::getInstance()->embeddings->deleteChunks('qa', (int)$rec->id);
            return;
        }
        $text = "Q: {$rec->question}\nA: {$rec->answer}";
        $count = Plugin::getInstance()->embeddings->reindexSource('qa', (int)$rec->id, $text);
        $rec->lastTrainedAt = Db::prepareDateForDb(new \DateTime());
        $rec->save(false);
    }

    public function removeQa(int $qaId): void
    {
        $rec = TrainingQaRecord::findOne($qaId);
        if (!$rec) {
            return;
        }
        Plugin::getInstance()->embeddings->deleteChunks('qa', (int)$rec->id);
        $rec->delete();
    }
}
