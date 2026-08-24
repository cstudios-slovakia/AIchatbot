<?php

namespace cstudiossro\craftcschatbot\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use cstudiossro\craftcschatbot\helpers\CraftCompat;
use cstudiossro\craftcschatbot\helpers\Vector;
use cstudiossro\craftcschatbot\jobs\IndexCategoryJob;
use cstudiossro\craftcschatbot\jobs\IndexEntryJob;
use cstudiossro\craftcschatbot\jobs\IndexFileJob;
use cstudiossro\craftcschatbot\jobs\IndexGlobalSetJob;
use cstudiossro\craftcschatbot\jobs\IndexSourceJob;
use cstudiossro\craftcschatbot\jobs\IndexUrlJob;
use cstudiossro\craftcschatbot\Plugin;
use cstudiossro\craftcschatbot\records\ChunkRecord;
use cstudiossro\craftcschatbot\records\TrainingCategoryRecord;
use cstudiossro\craftcschatbot\records\TrainingEntryRecord;
use cstudiossro\craftcschatbot\records\TrainingFileRecord;
use cstudiossro\craftcschatbot\records\TrainingGlobalSetRecord;
use cstudiossro\craftcschatbot\records\TrainingQaRecord;
use cstudiossro\craftcschatbot\records\TrainingSourceRecord;
use cstudiossro\craftcschatbot\records\TrainingUrlRecord;
use RuntimeException;
use Throwable;
use yii\base\Component;

/**
 * Moves a trained index between installs.
 *
 * Train locally where a bad crawl costs nothing, then carry the result to the
 * live site instead of paying for every embedding twice and waiting out the
 * queue on a site that is already serving visitors.
 *
 * What makes this more than a table dump: every row that points at Craft
 * content points at it by *local* id — `entryId`, `sectionId`, `categoryId`,
 * `globalSetId`, `siteId` — and `chatbot_chunks.sourceId` points at the
 * training row's auto-increment id on top of that. Copied verbatim into another
 * database those ids address different content, and the assistant answers
 * confidently from the wrong page. So the bundle stores element UIDs and site
 * handles, resolves them against the target on the way in, and reports what it
 * could not place rather than guessing.
 *
 * The bundle is gzipped NDJSON: one header line, then one line per trained
 * source with its chunks, with uploaded documents following their line in
 * base64 slices. Nothing has to be held in memory whole, at either end.
 */
class Transfer extends Component
{
    /** Bundle format. Bumped when a reader written for an older one would misread it. */
    public const FORMAT = 1;

    /** Source kinds, in the vocabulary `rag/retrain-all --only` already uses. */
    public const KINDS = ['entries', 'categories', 'globals', 'files', 'urls', 'qa', 'sources'];

    /** kind => the `chatbot_chunks.sourceType` its chunks are stored under. */
    private const CHUNK_TYPES = [
        'entries' => 'entry',
        'categories' => 'category',
        'globals' => 'global',
        'files' => 'file',
        'urls' => 'url',
        'qa' => 'qa',
        // 'sources' is per-item: the custom source's own handle.
    ];

    /** Raw bytes per base64 slice of an uploaded document. */
    private const FILE_SLICE = 768 * 1024;

    // ---------------------------------------------------------------- export

    /**
     * Write every trained source, its chunks and its uploaded documents to a
     * gzipped bundle.
     *
     * @param array{only?:string[]|null, includeFiles?:bool} $options
     * @return array{path:string, bytes:int, counts:array<string,int>, chunks:int, warnings:string[]}
     */
    public function export(string $path, array $options = []): array
    {
        $only = $options['only'] ?? null;
        $includeFiles = (bool)($options['includeFiles'] ?? true);
        $wants = fn(string $kind): bool => $only === null || in_array($kind, $only, true);

        $sites = $this->siteHandlesById();
        $counts = array_fill_keys(self::KINDS, 0);
        $warnings = [];
        $chunks = 0;

        FileHelper::createDirectory(dirname($path));
        $fp = gzopen($path, 'wb6');
        if ($fp === false) {
            throw new RuntimeException("Could not open {$path} for writing.");
        }

        try {
            $this->writeLine($fp, $this->header());

            if ($wants('entries')) {
                $sections = [];
                foreach (CraftCompat::getAllSections() as $section) {
                    $sections[(int)$section->id] = (string)$section->handle;
                }
                foreach (TrainingEntryRecord::find()->orderBy(['id' => SORT_ASC])->each(100) as $rec) {
                    $site = $sites[(int)$rec->siteId] ?? null;
                    $uid = $this->elementUid((int)$rec->entryId);
                    if ($site === null || $uid === null) {
                        $warnings[] = "Skipped entry #{$rec->entryId}: " . ($uid === null ? 'the element is gone.' : 'unknown site.');
                        continue;
                    }
                    $chunks += $this->writeSource($fp, 'entries', 'entry', (int)$rec->id, [
                        'uid' => $uid,
                        'site' => $site,
                        'section' => $sections[(int)$rec->sectionId] ?? null,
                    ], $this->statusOf($rec), $sites);
                    $counts['entries']++;
                }
            }

            if ($wants('categories')) {
                $groups = [];
                foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
                    $groups[(int)$group->id] = (string)$group->handle;
                }
                foreach (TrainingCategoryRecord::find()->orderBy(['id' => SORT_ASC])->each(100) as $rec) {
                    $site = $sites[(int)$rec->siteId] ?? null;
                    $uid = $this->elementUid((int)$rec->categoryId);
                    if ($site === null || $uid === null) {
                        $warnings[] = "Skipped category #{$rec->categoryId}: " . ($uid === null ? 'the element is gone.' : 'unknown site.');
                        continue;
                    }
                    $chunks += $this->writeSource($fp, 'categories', 'category', (int)$rec->id, [
                        'uid' => $uid,
                        'site' => $site,
                        'group' => $groups[(int)$rec->groupId] ?? null,
                    ], $this->statusOf($rec), $sites);
                    $counts['categories']++;
                }
            }

            if ($wants('globals')) {
                $handles = [];
                foreach (Craft::$app->getGlobals()->getAllSets() as $set) {
                    $handles[(int)$set->id] = (string)$set->handle;
                }
                foreach (TrainingGlobalSetRecord::find()->orderBy(['id' => SORT_ASC])->each(100) as $rec) {
                    $site = $sites[(int)$rec->siteId] ?? null;
                    $handle = $handles[(int)$rec->globalSetId] ?? null;
                    if ($site === null || $handle === null) {
                        $warnings[] = "Skipped global set #{$rec->globalSetId}: " . ($handle === null ? 'the set is gone.' : 'unknown site.');
                        continue;
                    }
                    $chunks += $this->writeSource($fp, 'globals', 'global', (int)$rec->id, [
                        'handle' => $handle,
                        'uid' => $this->elementUid((int)$rec->globalSetId),
                        'site' => $site,
                    ], $this->statusOf($rec), $sites);
                    $counts['globals']++;
                }
            }

            if ($wants('files')) {
                $dir = Plugin::getInstance()->getUploadPath();
                foreach (TrainingFileRecord::find()->orderBy(['id' => SORT_ASC])->each(50) as $rec) {
                    $site = $this->optionalSiteHandle($rec->siteId, $sites);
                    if ($site === false) {
                        $warnings[] = "Skipped file “{$rec->originalName}”: unknown site.";
                        continue;
                    }
                    $chunks += $this->writeSource($fp, 'files', 'file', (int)$rec->id, [
                        'filename' => (string)$rec->filename,
                        'originalName' => (string)$rec->originalName,
                        'size' => (int)$rec->size,
                        'site' => $site,
                    ], $this->statusOf($rec), $sites);
                    $counts['files']++;

                    $absolute = $dir . DIRECTORY_SEPARATOR . $rec->filename;
                    if (!$includeFiles) {
                        continue;
                    }
                    if (!is_file($absolute)) {
                        $warnings[] = "Document “{$rec->originalName}” is missing from storage; its chunks travel but the file does not.";
                        continue;
                    }
                    $this->writeFileBytes($fp, (string)$rec->filename, $absolute);
                }
            }

            if ($wants('urls')) {
                foreach (TrainingUrlRecord::find()->orderBy(['id' => SORT_ASC])->each(100) as $rec) {
                    $site = $this->optionalSiteHandle($rec->siteId, $sites);
                    if ($site === false) {
                        $warnings[] = "Skipped URL {$rec->url}: unknown site.";
                        continue;
                    }
                    $chunks += $this->writeSource($fp, 'urls', 'url', (int)$rec->id, [
                        'url' => (string)$rec->url,
                        'source' => (string)$rec->source,
                        'site' => $site,
                    ], $this->statusOf($rec), $sites);
                    $counts['urls']++;
                }
            }

            if ($wants('qa')) {
                foreach (TrainingQaRecord::find()->orderBy(['id' => SORT_ASC])->each(100) as $rec) {
                    $site = $this->optionalSiteHandle($rec->siteId, $sites);
                    if ($site === false) {
                        $warnings[] = 'Skipped a Q&A pair: unknown site.';
                        continue;
                    }
                    $chunks += $this->writeSource($fp, 'qa', 'qa', (int)$rec->id, [
                        'question' => (string)$rec->question,
                        'answer' => (string)$rec->answer,
                        'source' => (string)$rec->source,
                        'active' => (bool)$rec->active,
                        'translate' => (bool)$rec->translate,
                        'site' => $site,
                    ], ['lastTrainedAt' => $this->date($rec->lastTrainedAt)], $sites);
                    $counts['qa']++;
                }
            }

            if ($wants('sources')) {
                foreach (TrainingSourceRecord::find()->orderBy(['id' => SORT_ASC])->each(100) as $rec) {
                    $site = $sites[(int)$rec->siteId] ?? null;
                    if ($site === null) {
                        $warnings[] = "Skipped {$rec->sourceKey} item #{$rec->itemId}: unknown site.";
                        continue;
                    }
                    $chunks += $this->writeSource($fp, 'sources', (string)$rec->sourceKey, (int)$rec->id, [
                        'sourceKey' => (string)$rec->sourceKey,
                        'itemId' => (int)$rec->itemId,
                        'title' => (string)($rec->title ?? ''),
                        'site' => $site,
                    ], $this->statusOf($rec), $sites);
                    $counts['sources']++;
                }
            }
        } finally {
            gzclose($fp);
        }

        if ($counts['sources'] > 0) {
            $warnings[] = 'Plugin sources are matched by the id their own plugin gave them. '
                . 'Unless that plugin\'s content came from the same database, re-train them on the target instead.';
        }

        return [
            'path' => $path,
            'bytes' => (int)@filesize($path),
            'counts' => $counts,
            'chunks' => $chunks,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{format:int, ...}
     */
    private function header(): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $sites = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $sites[(string)$site->handle] = [
                'name' => (string)$site->name,
                'language' => (string)$site->language,
                'primary' => (bool)$site->primary,
            ];
        }
        return [
            't' => 'header',
            'format' => self::FORMAT,
            'plugin' => 'interactive-ai-assistant',
            'pluginVersion' => (string)$plugin->getVersion(),
            'schemaVersion' => (string)$plugin->schemaVersion,
            'exportedAt' => gmdate('c'),
            'sites' => $sites,
            'embedding' => [
                'model' => (string)$settings->embeddingModel,
                'dimensions' => (int)$settings->embeddingDimensions,
                'vectorLength' => $this->storedVectorLength(),
            ],
            'chunking' => [
                'size' => (int)$settings->chunkSize,
                'overlap' => (int)$settings->chunkOverlap,
                'contextualPrefix' => (bool)$settings->contextualPrefixEnabled,
            ],
        ];
    }

    /**
     * Write one source line, chunks included. Returns the chunk count.
     *
     * @param resource $fp
     * @param array<string, mixed> $ref
     * @param array<string, mixed> $row
     * @param array<int, string> $sites
     */
    private function writeSource($fp, string $kind, string $chunkType, int $sourceId, array $ref, array $row, array $sites): int
    {
        $chunks = [];
        $rows = (new Query())
            ->from(['{{%chatbot_chunks}}'])
            ->where(['sourceType' => $chunkType, 'sourceId' => $sourceId])
            ->orderBy(['position' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        foreach ($rows as $chunk) {
            $siteId = $chunk['siteId'] !== null ? (int)$chunk['siteId'] : null;
            // A chunk scoped to a site this install no longer has cannot be
            // placed on the target either; leave it behind rather than let it
            // land unscoped and answer to every language.
            if ($siteId !== null && !isset($sites[$siteId])) {
                continue;
            }
            $blob = $chunk['embeddingBlob'] ?? null;
            if (is_resource($blob)) {
                $blob = stream_get_contents($blob);
            }
            if (($blob === null || $blob === '') && !empty($chunk['embedding'])) {
                // Chunks indexed before the packed format still hold JSON.
                $blob = Vector::pack(
                    Vector::unpack(null, (string)$chunk['embedding']),
                );
            }
            $chunks[] = [
                'site' => $siteId !== null ? $sites[$siteId] : null,
                'language' => $chunk['language'] !== null ? (string)$chunk['language'] : null,
                'position' => (int)$chunk['position'],
                'section' => $chunk['section'] !== null ? (string)$chunk['section'] : null,
                'content' => (string)$chunk['content'],
                'tokens' => (int)$chunk['tokens'],
                'embedding' => ($blob !== null && $blob !== '') ? base64_encode((string)$blob) : null,
            ];
        }

        $this->writeLine($fp, [
            't' => 'source',
            'kind' => $kind,
            'ref' => $ref,
            'row' => $row,
            'chunks' => $chunks,
        ]);
        return count($chunks);
    }

    /**
     * @param resource $fp
     */
    private function writeFileBytes($fp, string $filename, string $absolutePath): void
    {
        $in = fopen($absolutePath, 'rb');
        if ($in === false) {
            return;
        }
        try {
            $seq = 0;
            while (!feof($in)) {
                $slice = fread($in, self::FILE_SLICE);
                if ($slice === false || $slice === '') {
                    break;
                }
                $this->writeLine($fp, [
                    't' => 'file',
                    'filename' => $filename,
                    'seq' => $seq++,
                    'data' => base64_encode($slice),
                ]);
            }
        } finally {
            fclose($in);
        }
    }

    // ---------------------------------------------------------------- import

    /**
     * Read a bundle and reproduce its trained state here.
     *
     * @param array{only?:string[]|null, reembed?:bool, dryRun?:bool, siteMap?:array<string,string>, overwriteFiles?:bool} $options
     * @return array{header:array<string,mixed>, imported:array<string,int>, skipped:array<string,int>, chunks:int, queued:int, files:int, warnings:string[], dryRun:bool}
     */
    public function import(string $path, array $options = []): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("No bundle at {$path}.");
        }
        $only = $options['only'] ?? null;
        $reembed = (bool)($options['reembed'] ?? false);
        $dryRun = (bool)($options['dryRun'] ?? false);
        $siteMap = $options['siteMap'] ?? [];
        $overwriteFiles = (bool)($options['overwriteFiles'] ?? false);
        $wants = fn(string $kind): bool => $only === null || in_array($kind, $only, true);

        $fp = gzopen($path, 'rb');
        if ($fp === false) {
            throw new RuntimeException("Could not open {$path}.");
        }

        $header = [];
        $imported = array_fill_keys(self::KINDS, 0);
        $skipped = array_fill_keys(self::KINDS, 0);
        $warnings = [];
        $chunkTotal = 0;
        $queued = 0;
        $filesWritten = 0;
        /** @var array<string, string> path => original name, checked once the bundle is read */
        $documents = [];
        /** @var array{filename:string, path:string|null, handle:resource|null}|null $pendingFile */
        $pendingFile = null;

        try {
            $header = $this->readHeader($fp);
            $this->assertCompatible($header, $reembed, $warnings);

            while (($line = $this->readLine($fp)) !== false) {
                if ($line === '') {
                    continue;
                }
                $data = json_decode($line, true);
                if (!is_array($data)) {
                    $warnings[] = 'Skipped a line the bundle could not be read from.';
                    continue;
                }

                if (($data['t'] ?? '') === 'file') {
                    $pendingFile = $this->appendFileSlice($pendingFile, $data, $dryRun);
                    continue;
                }
                if (($data['t'] ?? '') !== 'source') {
                    continue;
                }
                $pendingFile = $this->closeFile($pendingFile, $filesWritten);

                $kind = (string)($data['kind'] ?? '');
                if (!in_array($kind, self::KINDS, true) || !$wants($kind)) {
                    continue;
                }

                $ref = (array)($data['ref'] ?? []);
                $row = (array)($data['row'] ?? []);
                $chunks = (array)($data['chunks'] ?? []);

                $resolved = $this->resolveTarget($kind, $ref, $siteMap, $dryRun, $warnings);
                if ($resolved === null) {
                    $skipped[$kind]++;
                    continue;
                }
                [$record, $chunkType] = $resolved;
                $imported[$kind]++;

                if ($kind === 'files') {
                    // Bytes for this document follow; only fetch them when the
                    // record is new or the caller asked to replace what is here.
                    $pendingFile = $this->prepareFile($record, (string)($ref['filename'] ?? ''), $overwriteFiles, $dryRun);
                    $documents[Plugin::getInstance()->getUploadPath() . DIRECTORY_SEPARATOR . $record->getAttribute('filename')]
                        = (string)($ref['originalName'] ?? $ref['filename'] ?? '');
                }

                if ($dryRun) {
                    $chunkTotal += count($chunks);
                    continue;
                }

                if ($reembed) {
                    $queued += $this->queueRetrain($kind, $record, $ref);
                    continue;
                }

                $this->applyRow($record, $row);
                $chunkTotal += $this->writeChunks($chunkType, (int)$record->id, $chunks, $siteMap, $warnings);
            }
            $this->closeFile($pendingFile, $filesWritten);
        } finally {
            gzclose($fp);
        }

        // A record whose document never arrived — exported with --no-files, or
        // missing from the source install's storage — indexes to an error the
        // next time it is trained. Say so now, while it can still be uploaded.
        if (!$dryRun) {
            foreach ($documents as $path => $name) {
                if (!is_file($path)) {
                    $warnings[] = "The file behind “{$name}” did not travel with the bundle; upload it again under Training → File Upload.";
                }
            }
        }

        return [
            'header' => $header,
            'imported' => $imported,
            'skipped' => $skipped,
            'chunks' => $chunkTotal,
            'queued' => $queued,
            'files' => $filesWritten,
            'warnings' => $warnings,
            'dryRun' => $dryRun,
        ];
    }

    /**
     * @param resource $fp
     * @return array<string, mixed>
     */
    private function readHeader($fp): array
    {
        $line = $this->readLine($fp);
        $header = $line !== false ? json_decode($line, true) : null;
        if (!is_array($header) || ($header['t'] ?? '') !== 'header') {
            throw new RuntimeException('That file is not a training bundle.');
        }
        if ((int)($header['format'] ?? 0) > self::FORMAT) {
            throw new RuntimeException(sprintf(
                'The bundle was written in format %d; this plugin reads up to %d. Update the plugin on this site first.',
                (int)$header['format'],
                self::FORMAT,
            ));
        }
        return $header;
    }

    /**
     * Vectors from another embedding model are not comparable with the ones this
     * site will embed questions with — every similarity score would be noise.
     * Refuse rather than quietly poison retrieval.
     *
     * @param array<string, mixed> $header
     * @param string[] $warnings
     */
    private function assertCompatible(array $header, bool $reembed, array &$warnings): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $bundle = (array)($header['embedding'] ?? []);
        $theirModel = strtolower(trim((string)($bundle['model'] ?? '')));
        $ourModel = strtolower(trim((string)$settings->embeddingModel));
        $theirDims = (int)($bundle['dimensions'] ?? 0);
        $ourDims = (int)$settings->embeddingDimensions;

        if ($theirModel === $ourModel && $theirDims === $ourDims) {
            return;
        }
        if ($reembed) {
            $warnings[] = "The bundle was embedded with {$theirModel} ({$theirDims} dimensions); "
                . 'its vectors are being discarded and the content re-embedded here.';
            return;
        }
        throw new RuntimeException(sprintf(
            "The bundle was embedded with %s (dimensions setting %d); this site uses %s (%d). "
            . 'Vectors from different models cannot be compared. Either match the setting under '
            . 'Settings → AI Configuration, or import with --reembed to embed the content here.',
            $theirModel !== '' ? $theirModel : '(unknown)',
            $theirDims,
            $ourModel,
            $ourDims,
        ));
    }

    /**
     * Find or create the local training record a bundled source belongs to.
     *
     * @param array<string, mixed> $ref
     * @param array<string, string> $siteMap
     * @param string[] $warnings
     * @return array{0:\craft\db\ActiveRecord, 1:string}|null record + chunk sourceType
     */
    private function resolveTarget(string $kind, array $ref, array $siteMap, bool $dryRun, array &$warnings): ?array
    {
        $siteHandle = isset($ref['site']) && $ref['site'] !== null ? (string)$ref['site'] : null;
        $siteId = $this->resolveSiteId($siteHandle, $siteMap);
        if ($siteId === false) {
            $warnings[] = "Skipped a {$kind} item: this site has no “" . $this->mapSite($siteHandle, $siteMap) . '” site.';
            return null;
        }

        switch ($kind) {
            case 'entries':
                $entry = $this->entryByUid((string)($ref['uid'] ?? ''), (int)$siteId);
                if ($entry === null) {
                    $warnings[] = 'Skipped an entry: no entry with that UID exists here (it was probably authored separately).';
                    return null;
                }
                $rec = TrainingEntryRecord::findOne(['entryId' => $entry['id'], 'siteId' => $siteId])
                    ?? new TrainingEntryRecord();
                $rec->entryId = $entry['id'];
                $rec->siteId = (int)$siteId;
                $rec->sectionId = $entry['sectionId'];
                return [$this->persist($rec, $dryRun), 'entry'];

            case 'categories':
                $category = $this->categoryByUid((string)($ref['uid'] ?? ''), (int)$siteId);
                if ($category === null) {
                    $warnings[] = 'Skipped a category: no category with that UID exists here.';
                    return null;
                }
                $rec = TrainingCategoryRecord::findOne(['categoryId' => $category['id'], 'siteId' => $siteId])
                    ?? new TrainingCategoryRecord();
                $rec->categoryId = $category['id'];
                $rec->siteId = (int)$siteId;
                $rec->groupId = $category['groupId'];
                return [$this->persist($rec, $dryRun), 'category'];

            case 'globals':
                $handle = (string)($ref['handle'] ?? '');
                $set = $handle !== '' ? Craft::$app->getGlobals()->getSetByHandle($handle) : null;
                if (!$set && !empty($ref['uid'])) {
                    $set = CraftCompat::getGlobalSetByUid((string)$ref['uid']);
                }
                if (!$set) {
                    $warnings[] = "Skipped global set “{$handle}”: this site has no such set.";
                    return null;
                }
                $rec = TrainingGlobalSetRecord::findOne(['globalSetId' => (int)$set->id, 'siteId' => $siteId])
                    ?? new TrainingGlobalSetRecord();
                $rec->globalSetId = (int)$set->id;
                $rec->siteId = (int)$siteId;
                return [$this->persist($rec, $dryRun), 'global'];

            case 'files':
                $filename = (string)($ref['filename'] ?? '');
                $originalName = (string)($ref['originalName'] ?? '');
                if ($filename === '') {
                    $warnings[] = 'Skipped a document with no filename.';
                    return null;
                }
                // Match the stored name first; failing that, the same document
                // uploaded here under a different random name.
                $rec = TrainingFileRecord::findOne(['filename' => $filename])
                    ?? TrainingFileRecord::findOne([
                        'originalName' => $originalName,
                        'size' => (int)($ref['size'] ?? 0),
                        'siteId' => $siteId,
                    ])
                    ?? new TrainingFileRecord();
                if ($rec->getIsNewRecord()) {
                    $rec->filename = $filename;
                }
                $rec->originalName = $originalName;
                $rec->size = (int)($ref['size'] ?? 0);
                $rec->siteId = $siteId;
                return [$this->persist($rec, $dryRun), 'file'];

            case 'urls':
                $url = (string)($ref['url'] ?? '');
                if ($url === '') {
                    $warnings[] = 'Skipped a URL with no address.';
                    return null;
                }
                $rec = TrainingUrlRecord::findOne(['url' => $url, 'siteId' => $siteId]) ?? new TrainingUrlRecord();
                $rec->url = $url;
                $rec->siteId = $siteId;
                $rec->source = (string)($ref['source'] ?? 'manual');
                return [$this->persist($rec, $dryRun), 'url'];

            case 'qa':
                $question = (string)($ref['question'] ?? '');
                if ($question === '') {
                    $warnings[] = 'Skipped a Q&A pair with no question.';
                    return null;
                }
                $rec = TrainingQaRecord::findOne(['question' => $question, 'siteId' => $siteId]) ?? new TrainingQaRecord();
                $rec->question = $question;
                $rec->answer = (string)($ref['answer'] ?? '');
                $rec->siteId = $siteId;
                $rec->source = (string)($ref['source'] ?? 'manual');
                $rec->active = (bool)($ref['active'] ?? true);
                $rec->translate = (bool)($ref['translate'] ?? false);
                // Points at a chat message in the source install's logs.
                $rec->sourceMessageId = null;
                return [$this->persist($rec, $dryRun), 'qa'];

            case 'sources':
                $handle = (string)($ref['sourceKey'] ?? '');
                if (!Plugin::getInstance()->sources->has($handle)) {
                    $warnings[] = "Skipped {$handle} items: no plugin here registers that training source.";
                    return null;
                }
                $rec = TrainingSourceRecord::findOne([
                    'sourceKey' => $handle,
                    'itemId' => (int)($ref['itemId'] ?? 0),
                    'siteId' => $siteId,
                ]) ?? new TrainingSourceRecord();
                $rec->sourceKey = $handle;
                $rec->itemId = (int)($ref['itemId'] ?? 0);
                $rec->siteId = (int)$siteId;
                $rec->title = (string)($ref['title'] ?? '');
                return [$this->persist($rec, $dryRun), $handle];
        }
        return null;
    }

    /**
     * Copy the status the source carried, so the target's Training screens and
     * the dashboard's health check read the same as the install it came from.
     *
     * @param array<string, mixed> $row
     */
    private function applyRow(\craft\db\ActiveRecord $record, array $row): void
    {
        foreach (['status', 'chunkCount', 'errorMessage', 'lastTrainedAt'] as $attr) {
            if (!array_key_exists($attr, $row) || !$record->hasAttribute($attr)) {
                continue;
            }
            $value = $row[$attr];
            if ($attr === 'lastTrainedAt') {
                $value = $value !== null ? Db::prepareDateForDb(new \DateTime((string)$value)) : null;
            }
            $record->setAttribute($attr, $value);
        }
        $record->save(false);
    }

    /**
     * Replace a source's chunks with the bundled ones. Same swap-in-one-go the
     * indexer uses: a half-written source is worse than an untouched one.
     *
     * @param array<int, array<string, mixed>> $chunks
     * @param array<string, string> $siteMap
     * @param string[] $warnings
     */
    private function writeChunks(string $chunkType, int $sourceId, array $chunks, array $siteMap, array &$warnings): int
    {
        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            Plugin::getInstance()->embeddings->deleteChunks($chunkType, $sourceId);
            $written = 0;
            $dropped = 0;
            foreach ($chunks as $chunk) {
                $handle = isset($chunk['site']) && $chunk['site'] !== null ? (string)$chunk['site'] : null;
                $siteId = $this->resolveSiteId($handle, $siteMap);
                if ($siteId === false) {
                    $dropped++;
                    continue;
                }
                $rec = new ChunkRecord();
                $rec->sourceType = $chunkType;
                $rec->sourceId = $sourceId;
                $rec->siteId = $siteId;
                $rec->language = isset($chunk['language']) && $chunk['language'] !== null ? (string)$chunk['language'] : null;
                $rec->position = (int)($chunk['position'] ?? 0);
                $rec->section = isset($chunk['section']) && $chunk['section'] !== null ? (string)$chunk['section'] : null;
                $rec->content = (string)($chunk['content'] ?? '');
                $blob = isset($chunk['embedding']) && $chunk['embedding'] !== null
                    ? base64_decode((string)$chunk['embedding'], true)
                    : false;
                $rec->embeddingBlob = $blob === false ? null : $blob;
                $rec->tokens = (int)($chunk['tokens'] ?? 0);
                $rec->save(false);
                $written++;
            }
            $transaction?->commit();
            if ($dropped > 0) {
                $warnings[] = "Dropped {$dropped} chunk(s) scoped to a site this install does not have.";
            }
            return $written;
        } catch (Throwable $e) {
            $transaction?->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $ref
     */
    private function queueRetrain(string $kind, \craft\db\ActiveRecord $record, array $ref): int
    {
        $queue = Craft::$app->getQueue();
        foreach (['status' => 'pending', 'chunkCount' => 0, 'errorMessage' => null] as $attr => $value) {
            if ($record->hasAttribute($attr)) {
                $record->setAttribute($attr, $value);
            }
        }
        $record->save(false);

        switch ($kind) {
            case 'entries':
                $queue->push(new IndexEntryJob(['entryId' => (int)$record->getAttribute('entryId'), 'siteId' => (int)$record->getAttribute('siteId')]));
                return 1;
            case 'categories':
                $queue->push(new IndexCategoryJob(['categoryId' => (int)$record->getAttribute('categoryId'), 'siteId' => (int)$record->getAttribute('siteId')]));
                return 1;
            case 'globals':
                $queue->push(new IndexGlobalSetJob(['globalSetId' => (int)$record->getAttribute('globalSetId'), 'siteId' => (int)$record->getAttribute('siteId')]));
                return 1;
            case 'files':
                $path = Plugin::getInstance()->getUploadPath() . DIRECTORY_SEPARATOR . $record->getAttribute('filename');
                $queue->push(new IndexFileJob(['fileRecId' => (int)$record->id, 'absolutePath' => $path]));
                return 1;
            case 'urls':
                $queue->push(new IndexUrlJob(['urlRecId' => (int)$record->id]));
                return 1;
            case 'sources':
                $queue->push(new IndexSourceJob([
                    'handle' => (string)$record->getAttribute('sourceKey'),
                    'itemId' => (int)$record->getAttribute('itemId'),
                    'siteId' => (int)$record->getAttribute('siteId'),
                ]));
                return 1;
            case 'qa':
                // Q&A has no queue job — translating a pair is part of training it.
                if ($record->getAttribute('active')) {
                    Plugin::getInstance()->training->trainQa((int)$record->id);
                }
                return 1;
        }
        return 0;
    }

    // ------------------------------------------------------- file streaming

    /**
     * @param array{filename:string, path:string|null, handle:resource|null}|null $pending
     * @return array{filename:string, path:string|null, handle:resource|null}|null
     */
    private function prepareFile(\craft\db\ActiveRecord $record, string $filename, bool $overwrite, bool $dryRun): ?array
    {
        if ($filename === '' || $dryRun) {
            return null;
        }
        $stored = (string)$record->getAttribute('filename');
        $path = Plugin::getInstance()->getUploadPath() . DIRECTORY_SEPARATOR . $stored;
        if (is_file($path) && !$overwrite) {
            return null;
        }
        return ['filename' => $filename, 'path' => $path, 'handle' => null];
    }

    /**
     * @param array{filename:string, path:string|null, handle:resource|null}|null $pending
     * @param array<string, mixed> $data
     * @return array{filename:string, path:string|null, handle:resource|null}|null
     */
    private function appendFileSlice(?array $pending, array $data, bool $dryRun): ?array
    {
        if ($dryRun || $pending === null || $pending['path'] === null) {
            return $pending;
        }
        if ((string)($data['filename'] ?? '') !== $pending['filename']) {
            return $pending;
        }
        if ($pending['handle'] === null) {
            $handle = fopen($pending['path'], 'wb');
            if ($handle === false) {
                return ['filename' => $pending['filename'], 'path' => null, 'handle' => null];
            }
            $pending['handle'] = $handle;
        }
        $bytes = base64_decode((string)($data['data'] ?? ''), true);
        if ($bytes !== false && $bytes !== '') {
            fwrite($pending['handle'], $bytes);
        }
        return $pending;
    }

    /**
     * @param array{filename:string, path:string|null, handle:resource|null}|null $pending
     */
    private function closeFile(?array $pending, int &$written): ?array
    {
        if ($pending !== null && $pending['handle'] !== null) {
            fclose($pending['handle']);
            $written++;
        }
        return null;
    }

    // -------------------------------------------------------------- helpers

    /**
     * @return array<int, string> siteId => handle
     */
    private function siteHandlesById(): array
    {
        $map = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $map[(int)$site->id] = (string)$site->handle;
        }
        return $map;
    }

    /**
     * @param array<int, string> $sites
     * @return string|null|false handle, null for "all sites", false when unknown
     */
    private function optionalSiteHandle(mixed $siteId, array $sites): string|null|false
    {
        if ($siteId === null || (int)$siteId === 0) {
            return null;
        }
        return $sites[(int)$siteId] ?? false;
    }

    /**
     * @param array<string, string> $siteMap
     * @return int|null|false site id, null for "all sites", false when the site is missing here
     */
    private function resolveSiteId(?string $handle, array $siteMap): int|null|false
    {
        if ($handle === null || $handle === '') {
            return null;
        }
        $site = Craft::$app->getSites()->getSiteByHandle($this->mapSite($handle, $siteMap));
        return $site ? (int)$site->id : false;
    }

    /**
     * @param array<string, string> $siteMap
     */
    private function mapSite(?string $handle, array $siteMap): string
    {
        $handle = (string)$handle;
        return $siteMap[$handle] ?? $handle;
    }

    /**
     * @return array{id:int, sectionId:int}|null
     */
    private function entryByUid(string $uid, int $siteId): ?array
    {
        if ($uid === '') {
            return null;
        }
        $row = (new Query())
            ->select(['e.id', 'en.sectionId'])
            ->from(['e' => Table::ELEMENTS])
            ->innerJoin(['en' => Table::ENTRIES], '[[en.id]] = [[e.id]]')
            ->innerJoin(['es' => Table::ELEMENTS_SITES], '[[es.elementId]] = [[e.id]]')
            ->where(['e.uid' => $uid, 'e.dateDeleted' => null, 'es.siteId' => $siteId])
            ->one();
        if (!$row || $row['sectionId'] === null) {
            return null;
        }
        return ['id' => (int)$row['id'], 'sectionId' => (int)$row['sectionId']];
    }

    /**
     * @return array{id:int, groupId:int}|null
     */
    private function categoryByUid(string $uid, int $siteId): ?array
    {
        if ($uid === '') {
            return null;
        }
        $row = (new Query())
            ->select(['e.id', 'c.groupId'])
            ->from(['e' => Table::ELEMENTS])
            ->innerJoin(['c' => Table::CATEGORIES], '[[c.id]] = [[e.id]]')
            ->innerJoin(['es' => Table::ELEMENTS_SITES], '[[es.elementId]] = [[e.id]]')
            ->where(['e.uid' => $uid, 'e.dateDeleted' => null, 'es.siteId' => $siteId])
            ->one();
        if (!$row) {
            return null;
        }
        return ['id' => (int)$row['id'], 'groupId' => (int)$row['groupId']];
    }

    private function elementUid(int $elementId): ?string
    {
        $uid = (new Query())
            ->select(['uid'])
            ->from([Table::ELEMENTS])
            ->where(['id' => $elementId])
            ->scalar();
        return $uid !== false && $uid !== null ? (string)$uid : null;
    }

    /**
     * Dimensions of the vectors actually stored, so an import can tell a
     * dimension-capped index from a full-width one at a glance.
     */
    private function storedVectorLength(): int
    {
        $blob = (new Query())
            ->select(['embeddingBlob'])
            ->from(['{{%chatbot_chunks}}'])
            ->where(['not', ['embeddingBlob' => null]])
            ->limit(1)
            ->scalar();
        if (is_resource($blob)) {
            $blob = stream_get_contents($blob);
        }
        return is_string($blob) && $blob !== '' ? (int)floor(strlen($blob) / 4) : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function statusOf(\craft\db\ActiveRecord $rec): array
    {
        return [
            'status' => (string)$rec->getAttribute('status'),
            'chunkCount' => (int)$rec->getAttribute('chunkCount'),
            'errorMessage' => $rec->getAttribute('errorMessage'),
            'lastTrainedAt' => $this->date($rec->getAttribute('lastTrainedAt')),
        ];
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return (new \DateTime((string)$value))->format('c');
        } catch (Throwable) {
            return null;
        }
    }

    private function persist(\craft\db\ActiveRecord $record, bool $dryRun): \craft\db\ActiveRecord
    {
        if (!$dryRun) {
            // Q&A pairs carry no status column; every other kind does.
            if ($record->hasAttribute('status') && $record->getAttribute('status') === null) {
                $record->setAttribute('status', 'pending');
            }
            $record->save(false);
        }
        return $record;
    }

    /**
     * @param resource $fp
     * @param array<string, mixed> $data
     */
    private function writeLine($fp, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new RuntimeException('Could not encode a bundle line: ' . json_last_error_msg());
        }
        gzwrite($fp, $json . "\n");
    }

    /**
     * One NDJSON line, however long. gzgets() stops at the buffer size, and a
     * source line carrying a few hundred chunks runs well past any buffer worth
     * allocating, so read until the newline actually arrives.
     *
     * @param resource $fp
     * @return string|false false at end of file
     */
    private function readLine($fp): string|false
    {
        if (gzeof($fp)) {
            return false;
        }
        $line = '';
        while (!gzeof($fp)) {
            $part = gzgets($fp, 262144);
            if ($part === false) {
                break;
            }
            $line .= $part;
            if (str_ends_with($part, "\n")) {
                break;
            }
        }
        if ($line === '') {
            return false;
        }
        return rtrim($line, "\r\n");
    }
}
