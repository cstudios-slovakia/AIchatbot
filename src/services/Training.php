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
        // Only live entries belong in the index. Disabled and expired ones are
        // content the site has deliberately taken down — answering from them
        // tells visitors about products that are gone and links them to pages
        // that 404. Drop whatever was indexed before rather than leaving it.
        $entryStatus = (string)$entry->getStatus();
        if ($entryStatus !== Entry::STATUS_LIVE) {
            Plugin::getInstance()->embeddings->deleteChunks('entry', (int)$rec->id);
            $rec->chunkCount = 0;
            $rec->status = 'skipped';
            $rec->errorMessage = "Not indexed: entry is {$entryStatus}.";
            $rec->lastTrainedAt = Db::prepareDateForDb(new \DateTime());
            $rec->save(false);
            return;
        }

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
        $header['URL'] = $this->absoluteUrl($el);
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
     * An element's public URL, but only when it is genuinely absolute.
     *
     * Craft's default site baseUrl is the `@web` alias, which resolves from the
     * current request — and indexing runs in the queue, normally started from
     * cron, where there is no request and `@web` resolves to nothing. Left
     * alone that writes a root-relative path into the index, which the model
     * later hands to the visitor as a broken link. A missing URL is recoverable
     * (retrieval resolves one at answer time); a wrong one is not.
     */
    private function absoluteUrl(\craft\base\Element $el): ?string
    {
        try {
            $url = (string)$el->getUrl();
        } catch (Throwable) {
            return null; // element type or site without URLs
        }
        if (!preg_match('#^https?://#i', $url)) {
            if ($url !== '') {
                Craft::warning(
                    "Skipping non-absolute URL '{$url}' for element {$el->id} — set an absolute site baseUrl "
                    . 'so indexed content carries real links.',
                    __METHOD__,
                );
            }
            return null;
        }
        return $url;
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
                'siteId' => $rec->siteId ? (int)$rec->siteId : null,
                'language' => $this->siteLanguage($rec->siteId ? (int)$rec->siteId : null),
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
                'siteId' => $rec->siteId ? (int)$rec->siteId : null,
                'language' => $this->siteLanguage($rec->siteId ? (int)$rec->siteId : null),
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
     * Most URLs one sitemap import will take on.
     *
     * Every discovered URL becomes an HTTP fetch and an embedding call, so a
     * large sitemap can spend real money and hammer the target site before
     * anyone notices. Importing the first slice and saying so is recoverable;
     * queuing ten thousand crawls from one click is not.
     */
    public const SITEMAP_URL_LIMIT = 500;

    /**
     * @param int|null $siteId site the discovered URLs belong to; null = all sites
     * @return string[] URLs imported
     */
    public function importSitemap(string $sitemapUrl, ?int $siteId = null): array
    {
        $xml = $this->fetch($sitemapUrl);
        $urls = $this->parseSitemap($xml);
        $discovered = count($urls);
        $urls = array_slice($urls, 0, self::SITEMAP_URL_LIMIT);
        if ($discovered > count($urls)) {
            Craft::warning(
                "Sitemap {$sitemapUrl} listed {$discovered} URLs; importing the first "
                . self::SITEMAP_URL_LIMIT . '.',
                __METHOD__,
            );
        }
        foreach ($urls as $u) {
            $existing = TrainingUrlRecord::find()
                ->where(['url' => $u])
                ->one();
            if ($existing) {
                continue;
            }
            $rec = new TrainingUrlRecord();
            $rec->url = $u;
            $rec->siteId = $siteId;
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

        $question = (string)$rec->question;
        $answer = (string)$rec->answer;
        $title = mb_substr($question, 0, 200);
        $variants = [];

        if ($rec->siteId) {
            // Pinned to one site.
            $variants[] = [
                'text' => "Q: {$question}\nA: {$answer}",
                'siteId' => (int)$rec->siteId,
                'language' => $this->siteLanguage((int)$rec->siteId),
                'title' => $title,
            ];
        } elseif ($rec->translate) {
            // One authored pair, indexed once per site in that site's language.
            // Retrieval is an embedding match against how the visitor phrased
            // the question, so a Hungarian visitor needs a Hungarian embedding
            // however good the Slovak answer is.
            foreach (Craft::$app->sites->getAllSites() as $site) {
                $language = (string)$site->language;
                [$q, $a] = $this->translateQa($question, $answer, $language);
                $variants[] = [
                    'text' => "Q: {$q}\nA: {$a}",
                    'siteId' => (int)$site->id,
                    'language' => $language,
                    'title' => mb_substr($q, 0, 200),
                ];
            }
        } else {
            // Shared across every site, exactly as written.
            $variants[] = [
                'text' => "Q: {$question}\nA: {$answer}",
                'siteId' => null,
                'language' => null,
                'title' => $title,
            ];
        }

        Plugin::getInstance()->embeddings->reindexSourceVariants('qa', (int)$rec->id, $variants);
        $rec->lastTrainedAt = Db::prepareDateForDb(new \DateTime());
        $rec->save(false);
    }

    /**
     * Translate a Q&A pair into $language, cached on the source text so a
     * retrain does not pay for the same translation twice.
     *
     * Falls back to the original on any failure: an untranslated pair still
     * answers the question, a missing one does not.
     *
     * @return array{0:string, 1:string} [question, answer]
     */
    private function translateQa(string $question, string $answer, string $language): array
    {
        $cache = Craft::$app->getCache();
        $key = 'cs-chatbot:qa-translation:' . md5($language . "\x00" . $question . "\x00" . $answer);
        $cached = $cache->get($key);
        if (is_array($cached) && count($cached) === 2) {
            return $cached;
        }
        try {
            $settings = Plugin::getInstance()->getSettings();
            $message = Plugin::getInstance()->openAi->chatRaw(
                [
                    [
                        'role' => 'system',
                        'content' => "Translate the question and answer into the locale {$language}. "
                            . 'Keep the meaning exact and the tone natural for a website visitor. '
                            . 'Leave proper nouns, product names, addresses, phone numbers, prices and URLs as they are. '
                            . 'Respond with ONLY compact JSON: {"question": string, "answer": string}.',
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode(['question' => $question, 'answer' => $answer], JSON_UNESCAPED_UNICODE),
                    ],
                ],
                ['model' => $settings->helperModel, 'temperature' => 0.0],
            );
            $data = json_decode($this->stripJsonFence((string)($message['content'] ?? '')), true);
            $q = trim((string)($data['question'] ?? ''));
            $a = trim((string)($data['answer'] ?? ''));
            if ($q !== '' && $a !== '') {
                $cache->set($key, [$q, $a], 60 * 60 * 24 * 30);
                return [$q, $a];
            }
        } catch (Throwable $e) {
            Craft::warning("Q&A translation to {$language} failed: " . $e->getMessage(), __METHOD__);
        }
        return [$question, $answer];
    }

    private function stripJsonFence(string $s): string
    {
        $s = trim($s);
        if (str_starts_with($s, '```')) {
            $s = (string)preg_replace('/^```[a-zA-Z]*\s*/', '', $s);
            $s = (string)preg_replace('/\s*```$/', '', $s);
        }
        return trim($s);
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

    // ---------- INDEX HEALTH ----------

    /**
     * What is wrong with the index right now.
     *
     * An assistant answers confidently from whatever it was trained on, so the
     * failure modes that matter are the silent ones: a page edited after it was
     * indexed, a section nobody ever trained, a source that errored months ago,
     * a source that indexed to nothing because its text could not be extracted.
     * None of those announce themselves in a chat transcript.
     *
     * @return array{
     *   stale: array<int, array{id:int, elementId:int, siteId:int, kind:string}>,
     *   failed: array<int, array{id:int, kind:string, message:string}>,
     *   blank: array<int, array{id:int, kind:string}>,
     *   orphaned: array<int, array{id:int, kind:string}>,
     *   untrainedBySection: array<string, int>,
     *   totals: array{sources:int, chunks:int}
     * }
     */
    public function indexHealth(): array
    {
        $stale = [];
        $failed = [];
        $blank = [];
        $orphaned = [];

        $elementKinds = [
            'entry' => [TrainingEntryRecord::class, 'entryId'],
            'category' => [TrainingCategoryRecord::class, 'categoryId'],
            'global' => [TrainingGlobalSetRecord::class, 'globalSetId'],
        ];
        foreach ($elementKinds as $kind => [$recordClass, $idAttribute]) {
            /** @var class-string<\craft\db\ActiveRecord> $recordClass */
            $rows = (new \craft\db\Query())
                ->select([
                    'id' => 't.id',
                    'elementId' => "t.{$idAttribute}",
                    'siteId' => 't.siteId',
                    'status' => 't.status',
                    'chunkCount' => 't.chunkCount',
                    'errorMessage' => 't.errorMessage',
                    'lastTrainedAt' => 't.lastTrainedAt',
                    'elementUpdated' => 'e.dateUpdated',
                    'elementDeleted' => 'e.dateDeleted',
                ])
                ->from(['t' => $recordClass::tableName()])
                ->leftJoin(['e' => \craft\db\Table::ELEMENTS], "e.id = t.{$idAttribute}")
                ->all();

            foreach ($rows as $row) {
                $entry = ['id' => (int)$row['id'], 'kind' => $kind];
                if ($row['elementUpdated'] === null || $row['elementDeleted'] !== null) {
                    $orphaned[] = $entry;
                    continue;
                }
                if ($row['status'] === 'error') {
                    $failed[] = $entry + ['message' => (string)($row['errorMessage'] ?? 'unknown error')];
                    continue;
                }
                if ((int)$row['chunkCount'] === 0 && $row['status'] !== 'skipped') {
                    $blank[] = $entry;
                    continue;
                }
                if ($row['lastTrainedAt'] === null || $row['elementUpdated'] > $row['lastTrainedAt']) {
                    $stale[] = $entry + [
                        'elementId' => (int)$row['elementId'],
                        'siteId' => (int)$row['siteId'],
                    ];
                }
            }
        }

        // Sources that have no element behind them: only their own status counts.
        $standaloneKinds = [
            'file' => TrainingFileRecord::class,
            'url' => TrainingUrlRecord::class,
            'source' => TrainingSourceRecord::class,
        ];
        foreach ($standaloneKinds as $kind => $recordClass) {
            /** @var class-string<\craft\db\ActiveRecord> $recordClass */
            foreach ($recordClass::find()->all() as $rec) {
                if ($rec->status === 'error') {
                    $failed[] = ['id' => (int)$rec->id, 'kind' => $kind, 'message' => (string)($rec->errorMessage ?? '')];
                } elseif ((int)($rec->chunkCount ?? 0) === 0) {
                    $blank[] = ['id' => (int)$rec->id, 'kind' => $kind];
                }
            }
        }

        return [
            'stale' => $stale,
            'failed' => $failed,
            'blank' => $blank,
            'orphaned' => $orphaned,
            'untrainedBySection' => $this->untrainedBySection(),
            'totals' => [
                'sources' => count($stale) + count($failed) + count($blank) + count($orphaned),
                'chunks' => (int)(new \craft\db\Query())->from('{{%chatbot_chunks}}')->count(),
            ],
        ];
    }

    /**
     * Live entries in each section selected for training that have never been
     * indexed, keyed by section name. A section can be ticked in the settings
     * and still contain nothing the assistant knows about.
     *
     * @return array<string, int>
     */
    private function untrainedBySection(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $trained = (new \craft\db\Query())
            ->select(['entryId', 'siteId'])
            ->from('{{%chatbot_training_entries}}')
            ->all();
        $known = [];
        foreach ($trained as $row) {
            $known[$row['entryId'] . ':' . $row['siteId']] = true;
        }

        $out = [];
        foreach ($settings->trainingSections as $uid) {
            $section = \cstudiossro\craftcschatbot\helpers\CraftCompat::getSectionByUid((string)$uid);
            if (!$section) {
                continue;
            }
            $missing = 0;
            foreach (Craft::$app->sites->getAllSites() as $site) {
                $entries = Entry::find()
                    ->sectionId($section->id)
                    ->siteId($site->id)
                    ->status(Entry::STATUS_LIVE)
                    ->ids();
                foreach ($entries as $entryId) {
                    if (!isset($known[$entryId . ':' . $site->id])) {
                        $missing++;
                    }
                }
            }
            if ($missing > 0) {
                $out[$section->name] = $missing;
            }
        }
        return $out;
    }

    /**
     * Re-queue every source whose content changed after it was last indexed.
     *
     * @return int number queued
     */
    public function retrainStale(): int
    {
        $health = $this->indexHealth();
        $queue = Craft::$app->queue;
        $jobs = [
            'entry' => IndexEntryJob::class,
            'category' => IndexCategoryJob::class,
            'global' => IndexGlobalSetJob::class,
        ];
        $keys = ['entry' => 'entryId', 'category' => 'categoryId', 'global' => 'globalSetId'];
        $n = 0;
        foreach ($health['stale'] as $item) {
            $jobClass = $jobs[$item['kind']] ?? null;
            if (!$jobClass) {
                continue;
            }
            $queue->push(new $jobClass([
                $keys[$item['kind']] => $item['elementId'],
                'siteId' => $item['siteId'],
            ]));
            $n++;
        }
        return $n;
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
