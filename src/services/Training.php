<?php

namespace cstudiossro\craftcschatbot\services;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\helpers\Db;
use cstudiossro\craftcschatbot\jobs\IndexCategoryJob;
use cstudiossro\craftcschatbot\jobs\IndexEntryJob;
use cstudiossro\craftcschatbot\jobs\IndexFileJob;
use cstudiossro\craftcschatbot\jobs\IndexGlobalSetJob;
use cstudiossro\craftcschatbot\jobs\IndexSourceJob;
use cstudiossro\craftcschatbot\jobs\IndexUrlJob;
use cstudiossro\craftcschatbot\Plugin;
use cstudiossro\craftcschatbot\records\TrainingCategoryRecord;
use cstudiossro\craftcschatbot\records\TrainingEntryRecord;
use cstudiossro\craftcschatbot\records\TrainingFileRecord;
use cstudiossro\craftcschatbot\records\TrainingGlobalSetRecord;
use cstudiossro\craftcschatbot\records\TrainingQaRecord;
use cstudiossro\craftcschatbot\records\TrainingSourceRecord;
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
            $count = Plugin::getInstance()->embeddings->reindexSource('entry', (int)$rec->id, $text, [
                'siteId' => (int)$entry->siteId,
                'language' => $entry->getSite()->language ?? null,
                'title' => (string)$entry->title,
            ]);
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
        $section = null;
        try {
            $section = $entry->getSection()?->name;
        } catch (Throwable) {
            // entry type without a section (Craft 5 nested entries)
        }
        return $this->buildElementText($entry, [
            'Section' => $section,
            'Published' => $entry->postDate?->format('Y-m-d'),
        ]);
    }

    /**
     * Render an element as labelled plain text: a metadata header followed by
     * one "Field label: value" block per non-empty custom field.
     *
     * The labels are the point. Values alone ("Secum Euro / 649 / Košický")
     * embed and read as noise — nothing says the number is a price or the word
     * is a region, so the model cannot answer "how much is it?" or "where are
     * you?" from them. Naming each value costs a few tokens per chunk and makes
     * the difference between a retrievable fact and a floating string.
     *
     * @param array<string, string|null> $extraHeader metadata lines to add after Title/URL
     */
    private function buildElementText(\craft\base\Element $el, array $extraHeader = [], string $prefix = ''): string
    {
        $header = [];
        if ($prefix !== '') {
            $header['Name'] = $prefix;
        }
        if (!empty($el->title)) {
            $header['Title'] = (string)$el->title;
        }
        try {
            $header['URL'] = $el->getUrl();
        } catch (Throwable) {
            // element type or site without URLs
        }
        foreach ($extraHeader as $label => $value) {
            $header[$label] = $value;
        }

        $parts = [];
        foreach ($header as $label => $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $parts[] = $label . ': ' . $value;
            }
        }
        // The header lines belong together as one block; field blocks follow.
        $parts = $parts ? [implode("\n", $parts)] : [];

        foreach ($this->labelledFieldValues($el) as [$label, $value]) {
            // Multi-line values (rich text, tables, matrix) start on their own
            // line so headings and list markers stay at the start of a line and
            // the chunker can still see them as structure.
            $parts[] = str_contains($value, "\n")
                ? $label . ":\n" . $value
                : $label . ': ' . $value;
        }

        return Plugin::getInstance()->embeddings->normalize(implode("\n\n", $parts));
    }

    /**
     * Each non-empty custom field of an element as [label, text], using the
     * field's control-panel name and falling back to a humanized handle.
     *
     * @return array<int, array{0:string, 1:string}>
     */
    private function labelledFieldValues(\craft\base\Element $el): array
    {
        $labels = $this->fieldLabels($el);
        $out = [];
        foreach ($el->getFieldValues() as $handle => $value) {
            $text = trim($this->fieldValueToText($value));
            if ($text === '') {
                continue;
            }
            $out[] = [$labels[$handle] ?? $this->humanizeHandle($handle), $text];
        }
        return $out;
    }

    /**
     * handle => control-panel field name for an element's layout.
     *
     * @return array<string, string>
     */
    private function fieldLabels(\craft\base\Element $el): array
    {
        $labels = [];
        try {
            $layout = $el->getFieldLayout();
            if (!$layout) {
                return $labels;
            }
            // getCustomFields() is Craft 4.4+/5; getFields() covers older Craft 4.
            $fields = method_exists($layout, 'getCustomFields')
                ? $layout->getCustomFields()
                : $layout->getFields();
            foreach ($fields as $field) {
                if (!empty($field->handle)) {
                    $labels[$field->handle] = (string)($field->name ?: $field->handle);
                }
            }
        } catch (Throwable) {
            // unreadable layout — fall back to humanized handles
        }
        return $labels;
    }

    /**
     * True for the filenames cameras and phones generate — "IMG 6159",
     * "DSC_6370", "PXL 20240101 120000", "20240101 123456".
     */
    private function looksLikeCameraFilename(string $name): bool
    {
        return (bool)preg_match('/^(img|dsc|dscn|dscf|pxl|gopr|p|photo|foto|image)?[ _-]?\d{3,}[ _-]?\w{0,8}$/i', trim($name));
    }

    private function humanizeHandle(string $handle): string
    {
        $words = preg_replace('/(?<!^)[A-Z]/', ' $0', $handle) ?? $handle;
        return ucfirst(trim(str_replace(['_', '-'], ' ', $words)));
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
        // Data objects contributed by field-type plugins (SEO metadata and
        // similar). They carry hand-written prose next to machine config, and
        // that prose is often the best one-line summary of the page — so read
        // the prose properties and ignore the rest.
        if (is_object($value)) {
            return $this->proseProperties($value);
        }
        return '';
    }

    /**
     * Human-written prose exposed by an arbitrary object, by convention over
     * property names. Returns '' for objects that carry no such text.
     */
    private function proseProperties(object $value): string
    {
        $parts = [];
        foreach (['description', 'summary', 'text'] as $property) {
            try {
                if (!isset($value->$property)) {
                    continue;
                }
                $text = $value->$property;
            } catch (Throwable) {
                continue;
            }
            if (is_string($text) && trim($text) !== '') {
                $parts[] = trim($text);
            }
        }
        return implode("\n", array_unique($parts));
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
        if ($el instanceof \craft\elements\Asset) {
            // Alt text is written for humans; an asset title is usually just the
            // camera's filename ("IMG 6159", "DSC 6370"), which embeds as noise
            // and pollutes every chunk that relates to an image.
            $alt = trim((string)($el->alt ?? ''));
            $title = trim((string)($el->title ?? ''));
            if ($alt !== '') {
                $parts[] = $alt;
            } elseif ($title !== '' && !$this->looksLikeCameraFilename($title)) {
                $parts[] = $title;
            }
        } elseif (!empty($el->title)) {
            $parts[] = (string)$el->title;
        }
        if ($depth < 2 && $el instanceof \craft\base\Element) {
            try {
                // Label nested values too: a Matrix block's fields are as
                // meaningless unlabelled as the owner element's are.
                $labels = $this->fieldLabels($el);
                foreach ($el->getFieldValues() as $handle => $v) {
                    $t = trim($this->fieldValueToText($v, $depth + 1));
                    if ($t === '') {
                        continue;
                    }
                    $label = $labels[$handle] ?? $this->humanizeHandle($handle);
                    $parts[] = str_contains($t, "\n") ? $label . ":\n" . $t : $label . ': ' . $t;
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
            $count = Plugin::getInstance()->embeddings->reindexSource('category', (int)$rec->id, $text, [
                'siteId' => (int)$cat->siteId,
                'language' => $cat->getSite()->language ?? null,
                'title' => (string)$cat->title,
            ]);
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
            $count = Plugin::getInstance()->embeddings->reindexSource('global', (int)$rec->id, $text, [
                'siteId' => (int)$set->siteId,
                'language' => $set->getSite()->language ?? null,
                'title' => (string)($set->name ?? ''),
            ]);
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

    /**
     * Labelled plain text for any element — the entry point custom training
     * sources use for element kinds this plugin doesn't handle natively.
     */
    public function extractElementText(\craft\base\Element $el, string $prefix = ''): string
    {
        if ($el instanceof Entry) {
            return $this->extractEntryText($el);
        }
        $extra = [];
        if ($el instanceof Category) {
            try {
                $extra['Group'] = $el->getGroup()->name ?? null;
            } catch (Throwable) {
                // group no longer exists
            }
        }
        return $this->buildElementText($el, $extra, $prefix);
    }

    // ---------- CUSTOM SOURCES (plugin-contributed) ----------

    /**
     * Create or update the tracking record for one item of a custom source,
     * caching its title for the control-panel list. Returns the record so the
     * caller can queue an {@see \cstudiossro\craftcschatbot\jobs\IndexSourceJob}.
     */
    public function upsertSourceItem(string $handle, int $itemId, ?int $siteId, string $title = ''): TrainingSourceRecord
    {
        $siteId = $siteId ?: (int)Craft::$app->sites->getPrimarySite()->id;
        $rec = TrainingSourceRecord::findOne(['sourceKey' => $handle, 'itemId' => $itemId, 'siteId' => $siteId])
            ?? new TrainingSourceRecord();
        $rec->sourceKey = $handle;
        $rec->itemId = $itemId;
        $rec->siteId = $siteId;
        if ($title !== '') {
            $rec->title = $title;
        }
        if ($rec->status === null) {
            $rec->status = 'pending';
        }
        $rec->save(false);
        return $rec;
    }

    /**
     * Embed one item from a custom training source. The source's handle is used
     * as the chunk sourceType so its chunks live alongside (but distinct from)
     * the built-in kinds.
     */
    public function trainSource(string $handle, int $itemId, ?int $siteId = null): void
    {
        $source = Plugin::getInstance()->sources->get($handle);
        $siteId = $siteId ?: (int)Craft::$app->sites->getPrimarySite()->id;
        $rec = TrainingSourceRecord::findOne(['sourceKey' => $handle, 'itemId' => $itemId, 'siteId' => $siteId])
            ?? new TrainingSourceRecord();
        $rec->sourceKey = $handle;
        $rec->itemId = $itemId;
        $rec->siteId = $siteId;

        if (!$source) {
            $rec->status = 'error';
            $rec->errorMessage = "Unknown training source: {$handle}";
            $rec->save(false);
            return;
        }

        $rec->status = 'training';
        $rec->errorMessage = null;
        $rec->save(false);

        try {
            $text = $source->extractText($itemId, $siteId);
            $count = Plugin::getInstance()->embeddings->reindexSource($handle, (int)$rec->id, $text, [
                'siteId' => (int)$siteId,
                'language' => $this->siteLanguage($siteId),
                'title' => method_exists($source, 'label') ? (string)$source->label() : '',
            ]);
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

    public function removeSource(int $trainingSourceId): void
    {
        $rec = TrainingSourceRecord::findOne($trainingSourceId);
        if (!$rec) {
            return;
        }
        Plugin::getInstance()->embeddings->deleteChunks((string)$rec->sourceKey, (int)$rec->id);
        $rec->delete();
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
            $count = Plugin::getInstance()->embeddings->reindexSource('file', (int)$rec->id, $raw, [
                'title' => (string)($rec->originalName ?: pathinfo((string)$rec->filename, PATHINFO_FILENAME)),
            ]);
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
            $pageTitle = preg_match('#<title\b[^>]*>(.*?)</title>#is', $html, $tm)
                ? trim(html_entity_decode(strip_tags($tm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                : (string)$rec->url;
            $text = $this->htmlToText($html);
            $count = Plugin::getInstance()->embeddings->reindexSource('url', (int)$rec->id, $text, [
                'title' => $pageTitle,
            ]);
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

    private function siteLanguage(?int $siteId): ?string
    {
        if (!$siteId) {
            return null;
        }
        return Craft::$app->sites->getSiteById($siteId)?->language;
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
        // normalize() handles boilerplate stripping, structure preservation and denoising.
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
        $count = Plugin::getInstance()->embeddings->reindexSource('qa', (int)$rec->id, $text, [
            'title' => mb_substr((string)$rec->question, 0, 200),
        ]);
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

    // ---------- RETRAIN ALL ----------

    /**
     * Re-queue every already-trained source so all content is re-chunked and
     * re-embedded under the current chunker/embedding settings. Required after
     * changing chunk size, contextual prefix, embedding model or dimensions.
     * Job-backed sources are queued (processed by the worker); Q&A pairs run
     * inline as they have no dedicated job.
     *
     * @param string[]|null $types limit to these source kinds (entries, categories,
     *        globals, files, urls, sources, qa); null retrains everything. Useful
     *        to re-embed local content without re-crawling remote URLs.
     * @return int number of sources queued/reindexed
     */
    public function reindexAll(?array $types = null): int
    {
        $queue = Craft::$app->queue;
        $n = 0;
        $wants = fn(string $type): bool => $types === null || in_array($type, $types, true);

        foreach ($wants('entries') ? TrainingEntryRecord::find()->all() : [] as $rec) {
            $queue->push(new IndexEntryJob(['entryId' => (int)$rec->entryId, 'siteId' => (int)$rec->siteId]));
            $n++;
        }
        foreach ($wants('categories') ? TrainingCategoryRecord::find()->all() : [] as $rec) {
            $queue->push(new IndexCategoryJob(['categoryId' => (int)$rec->categoryId, 'siteId' => (int)$rec->siteId]));
            $n++;
        }
        foreach ($wants('globals') ? TrainingGlobalSetRecord::find()->all() : [] as $rec) {
            $queue->push(new IndexGlobalSetJob(['globalSetId' => (int)$rec->globalSetId, 'siteId' => (int)$rec->siteId]));
            $n++;
        }
        foreach ($wants('files') ? TrainingFileRecord::find()->all() : [] as $rec) {
            $path = Plugin::getInstance()->getUploadPath() . DIRECTORY_SEPARATOR . $rec->filename;
            $queue->push(new IndexFileJob(['fileRecId' => (int)$rec->id, 'absolutePath' => $path]));
            $n++;
        }
        foreach ($wants('urls') ? TrainingUrlRecord::find()->all() : [] as $rec) {
            $queue->push(new IndexUrlJob(['urlRecId' => (int)$rec->id]));
            $n++;
        }
        foreach ($wants('sources') ? TrainingSourceRecord::find()->all() : [] as $rec) {
            $queue->push(new IndexSourceJob([
                'handle' => (string)$rec->sourceKey,
                'itemId' => (int)$rec->itemId,
                'siteId' => (int)$rec->siteId,
            ]));
            $n++;
        }
        foreach ($wants('qa') ? TrainingQaRecord::find()->where(['active' => true])->all() : [] as $rec) {
            $this->trainQa((int)$rec->id);
            $n++;
        }

        return $n;
    }
}
