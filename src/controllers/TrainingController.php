<?php

namespace cstudiossro\craftcschatbot\controllers;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\helpers\App;
use craft\helpers\ConfigHelper;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\web\Controller;
use craft\web\UploadedFile;
use cstudiossro\craftcschatbot\helpers\CraftCompat;
use cstudiossro\craftcschatbot\helpers\DocumentText;
use cstudiossro\craftcschatbot\jobs\CrawlSitemapJob;
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
use cstudiossro\craftcschatbot\services\Transfer;
use yii\web\Response;

class TrainingController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('accessPlugin-interactive-ai-assistant');
        return true;
    }

    // ---------- ENTRIES ----------

    public function actionEntries(): Response
    {
        $rows = TrainingEntryRecord::find()->orderBy(['dateUpdated' => SORT_DESC])->all();
        $entryTitles = [];
        if (!empty($rows)) {
            $ids = array_map(fn($r) => (int)$r->entryId, $rows);
            $entries = Entry::find()->id($ids)->status(null)->all();
            foreach ($entries as $e) {
                $entryTitles[(int)$e->id] = $e->title;
            }
        }
        $sections = CraftCompat::getAllSections();
        $settings = Plugin::getInstance()->getSettings();
        return $this->renderTemplate('interactive-ai-assistant/training/entries', [
            'rows' => $rows,
            'entryTitles' => $entryTitles,
            'sections' => $sections,
            'selectedSections' => $settings->trainingSections,
        ]);
    }

    public function actionTrainSections(): Response
    {
        $this->requirePostRequest();
        $sectionUids = (array)Craft::$app->request->getBodyParam('sections', []);
        if (empty($sectionUids)) {
            Craft::$app->session->setError('No sections selected.');
            return $this->redirectToPostedUrl();
        }
        $sectionIds = [];
        foreach ($sectionUids as $uid) {
            $s = CraftCompat::getSectionByUid($uid);
            if ($s) {
                $sectionIds[] = $s->id;
            }
        }
        if (empty($sectionIds)) {
            Craft::$app->session->setError('No matching sections.');
            return $this->redirectToPostedUrl();
        }
        $entries = Entry::find()->sectionId($sectionIds)->status(null)->all();
        $queued = 0;
        foreach ($entries as $e) {
            Craft::$app->queue->push(new IndexEntryJob(['entryId' => (int)$e->id, 'siteId' => (int)$e->siteId]));
            $queued++;
        }
        Craft::$app->session->setNotice("Queued {$queued} entries for training.");
        return $this->redirectToPostedUrl();
    }

    public function actionTrainEntry(): Response
    {
        $this->requirePostRequest();
        $id = (int)Craft::$app->request->getRequiredBodyParam('id');
        $rec = TrainingEntryRecord::findOne($id);
        if ($rec) {
            Craft::$app->queue->push(new IndexEntryJob(['entryId' => (int)$rec->entryId, 'siteId' => (int)$rec->siteId]));
            return $this->asJson(['success' => true]);
        }
        return $this->asJson(['success' => false, 'error' => 'Not found']);
    }

    public function actionDeleteEntry(): Response
    {
        $this->requirePostRequest();
        $id = (int)Craft::$app->request->getRequiredBodyParam('id');
        Plugin::getInstance()->training->removeEntry($id);
        return $this->asJson(['success' => true]);
    }

    public function actionEntryChunks(int $id): Response
    {
        $rec = TrainingEntryRecord::findOne($id);
        if (!$rec) {
            throw new \yii\web\NotFoundHttpException();
        }
        $chunks = ChunkRecord::find()
            ->where(['sourceType' => 'entry', 'sourceId' => $rec->id])
            ->orderBy(['position' => SORT_ASC])
            ->all();
        $entry = Entry::find()->id($rec->entryId)->siteId($rec->siteId)->status(null)->one();
        return $this->renderTemplate('interactive-ai-assistant/training/_chunks', [
            'title' => $entry ? $entry->title : ('Entry #' . $rec->entryId),
            'subtitle' => 'Entry',
            'chunks' => $chunks,
            'backUrl' => 'admin/interactive-ai-assistant/training/entries',
        ]);
    }

    // ---------- CATEGORIES ----------

    public function actionCategories(): \yii\web\Response
    {
        $rows = TrainingCategoryRecord::find()->orderBy(['dateUpdated' => SORT_DESC])->all();
        $titles = [];
        if (!empty($rows)) {
            $ids = array_map(fn($r) => (int)$r->categoryId, $rows);
            $cats = Category::find()->id($ids)->status(null)->all();
            foreach ($cats as $c) {
                $titles[(int)$c->id] = $c->title;
            }
        }
        $settings = Plugin::getInstance()->getSettings();
        return $this->renderTemplate('interactive-ai-assistant/training/categories', [
            'rows' => $rows,
            'titles' => $titles,
            'groups' => Craft::$app->categories->getAllGroups(),
            'selectedGroups' => $settings->trainingCategoryGroups,
        ]);
    }

    public function actionTrainCategoryGroups(): \yii\web\Response
    {
        $this->requirePostRequest();
        $groupUids = (array)Craft::$app->request->getBodyParam('groups', []);
        if (empty($groupUids)) {
            Craft::$app->session->setError('No category groups selected.');
            return $this->redirectToPostedUrl();
        }
        $groupIds = [];
        foreach ($groupUids as $uid) {
            $g = CraftCompat::getCategoryGroupByUid($uid);
            if ($g) {
                $groupIds[] = $g->id;
            }
        }
        if (empty($groupIds)) {
            Craft::$app->session->setError('No matching category groups.');
            return $this->redirectToPostedUrl();
        }
        $cats = Category::find()->groupId($groupIds)->status(null)->all();
        $queued = 0;
        foreach ($cats as $c) {
            Craft::$app->queue->push(new IndexCategoryJob(['categoryId' => (int)$c->id, 'siteId' => (int)$c->siteId]));
            $queued++;
        }
        Craft::$app->session->setNotice("Queued {$queued} categories for training.");
        return $this->redirectToPostedUrl();
    }

    public function actionTrainCategory(): \yii\web\Response
    {
        $this->requirePostRequest();
        $id = (int)Craft::$app->request->getRequiredBodyParam('id');
        $rec = TrainingCategoryRecord::findOne($id);
        if ($rec) {
            Craft::$app->queue->push(new IndexCategoryJob(['categoryId' => (int)$rec->categoryId, 'siteId' => (int)$rec->siteId]));
            return $this->asJson(['success' => true]);
        }
        return $this->asJson(['success' => false, 'error' => 'Not found']);
    }

    public function actionDeleteCategory(): \yii\web\Response
    {
        $this->requirePostRequest();
        $id = (int)Craft::$app->request->getRequiredBodyParam('id');
        Plugin::getInstance()->training->removeCategory($id);
        return $this->asJson(['success' => true]);
    }

    public function actionCategoryChunks(int $id): \yii\web\Response
    {
        $rec = TrainingCategoryRecord::findOne($id);
        if (!$rec) {
            throw new \yii\web\NotFoundHttpException();
        }
        $chunks = ChunkRecord::find()
            ->where(['sourceType' => 'category', 'sourceId' => $rec->id])
            ->orderBy(['position' => SORT_ASC])
            ->all();
        $cat = Category::find()->id($rec->categoryId)->siteId($rec->siteId)->status(null)->one();
        return $this->renderTemplate('interactive-ai-assistant/training/_chunks', [
            'title' => $cat ? $cat->title : ('Category #' . $rec->categoryId),
            'subtitle' => 'Category',
            'chunks' => $chunks,
            'backUrl' => 'admin/interactive-ai-assistant/training/categories',
        ]);
    }

    // ---------- GLOBALS ----------

    public function actionGlobals(): \yii\web\Response
    {
        $rows = TrainingGlobalSetRecord::find()->orderBy(['dateUpdated' => SORT_DESC])->all();
        $names = [];
        if (!empty($rows)) {
            $ids = array_map(fn($r) => (int)$r->globalSetId, $rows);
            $sets = GlobalSet::find()->id($ids)->status(null)->all();
            foreach ($sets as $s) {
                $names[(int)$s->id] = $s->name;
            }
        }
        $settings = Plugin::getInstance()->getSettings();
        return $this->renderTemplate('interactive-ai-assistant/training/globals', [
            'rows' => $rows,
            'names' => $names,
            'allSets' => Craft::$app->globals->getAllSets(),
            'selectedSets' => $settings->trainingGlobalSets,
        ]);
    }

    public function actionTrainGlobalSets(): \yii\web\Response
    {
        $this->requirePostRequest();
        $setUids = (array)Craft::$app->request->getBodyParam('sets', []);
        if (empty($setUids)) {
            Craft::$app->session->setError('No global sets selected.');
            return $this->redirectToPostedUrl();
        }
        $queued = 0;
        foreach ($setUids as $uid) {
            $s = CraftCompat::getGlobalSetByUid($uid);
            if ($s) {
                Craft::$app->queue->push(new IndexGlobalSetJob(['globalSetId' => (int)$s->id, 'siteId' => (int)$s->siteId]));
                $queued++;
            }
        }
        Craft::$app->session->setNotice("Queued {$queued} global sets for training.");
        return $this->redirectToPostedUrl();
    }

    public function actionTrainGlobalSet(): \yii\web\Response
    {
        $this->requirePostRequest();
        $id = (int)Craft::$app->request->getRequiredBodyParam('id');
        $rec = TrainingGlobalSetRecord::findOne($id);
        if ($rec) {
            Craft::$app->queue->push(new IndexGlobalSetJob(['globalSetId' => (int)$rec->globalSetId, 'siteId' => (int)$rec->siteId]));
            return $this->asJson(['success' => true]);
        }
        return $this->asJson(['success' => false, 'error' => 'Not found']);
    }

    public function actionDeleteGlobalSet(): \yii\web\Response
    {
        $this->requirePostRequest();
        $id = (int)Craft::$app->request->getRequiredBodyParam('id');
        Plugin::getInstance()->training->removeGlobalSet($id);
        return $this->asJson(['success' => true]);
    }

    public function actionGlobalChunks(int $id): \yii\web\Response
    {
        $rec = TrainingGlobalSetRecord::findOne($id);
        if (!$rec) {
            throw new \yii\web\NotFoundHttpException();
        }
        $chunks = ChunkRecord::find()
            ->where(['sourceType' => 'global', 'sourceId' => $rec->id])
            ->orderBy(['position' => SORT_ASC])
            ->all();
        $set = GlobalSet::find()->id($rec->globalSetId)->siteId($rec->siteId)->status(null)->one();
        return $this->renderTemplate('interactive-ai-assistant/training/_chunks', [
            'title' => $set ? $set->name : ('Global Set #' . $rec->globalSetId),
            'subtitle' => 'Global Set',
            'chunks' => $chunks,
            'backUrl' => 'admin/interactive-ai-assistant/training/globals',
        ]);
    }

    // ---------- FILES ----------

    /**
     * Largest upload this accepts, before PHP's own limits are considered.
     * {@see self::maxUploadBytes()} is what anything should actually test
     * against — a server configured below this never sees the file at all.
     */
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

    /**
     * The real ceiling on this server: our own cap, or PHP's, whichever bites
     * first. Quoting our number when php.ini is stricter tells an admin their
     * 4 MB file should have worked, and leaves them with nowhere to look.
     */
    public static function maxUploadBytes(): int
    {
        $limit = self::MAX_UPLOAD_BYTES;
        foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
            $bytes = ConfigHelper::sizeInBytes((string)ini_get($directive));
            if ($bytes > 0) {
                $limit = min($limit, (int)$bytes);
            }
        }
        return $limit;
    }

    public function actionFiles(): Response
    {
        $rows = TrainingFileRecord::find()->orderBy(['dateUpdated' => SORT_DESC])->all();
        return $this->renderTemplate('interactive-ai-assistant/training/files', [
            'rows' => $rows,
            'sites' => Craft::$app->sites->getAllSites(),
            'maxUploadBytes' => self::maxUploadBytes(),
        ]);
    }

    public function actionUploadFile(): Response
    {
        $this->requirePostRequest();
        $limit = self::maxUploadBytes();
        $upload = UploadedFile::getInstanceByName('file');
        if (!$upload) {
            // A body over post_max_size is discarded whole before PHP populates
            // $_FILES, so "no file" and "far too big" arrive here identically.
            // An empty $_POST alongside a non-zero Content-Length is the tell.
            $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
            if (empty($_POST) && $contentLength > 0) {
                return $this->asJson([
                    'success' => false,
                    'error' => sprintf(
                        'That upload (%s) was dropped by the server before it arrived — it is over the '
                        . 'post_max_size limit of %s. Upload a smaller file, or raise post_max_size and '
                        . 'upload_max_filesize in php.ini.',
                        self::formatBytes($contentLength),
                        ini_get('post_max_size') ?: '?',
                    ),
                ]);
            }
            return $this->asJson(['success' => false, 'error' => 'No file uploaded.']);
        }
        if ($upload->hasError) {
            return $this->asJson(['success' => false, 'error' => self::uploadErrorMessage($upload->error, $limit)]);
        }
        $ext = strtolower($upload->getExtension());
        if (!DocumentText::isSupported($ext)) {
            return $this->asJson([
                'success' => false,
                'error' => sprintf(
                    '“%s” is a .%s file. Supported types: .%s',
                    $upload->name,
                    $ext !== '' ? $ext : '?',
                    implode(', .', DocumentText::SUPPORTED),
                ),
            ]);
        }
        if ($upload->size > $limit) {
            return $this->asJson([
                'success' => false,
                'error' => sprintf(
                    '“%s” is %s. The limit is %s per file.',
                    $upload->name,
                    self::formatBytes((int)$upload->size),
                    self::formatBytes($limit),
                ),
            ]);
        }
        $dir = Plugin::getInstance()->getUploadPath();
        FileHelper::createDirectory($dir);
        $filename = StringHelper::randomString(12) . '.' . $ext;
        $path = $dir . DIRECTORY_SEPARATOR . $filename;
        if (!$upload->saveAs($path)) {
            // Without this the record saves, the job runs, and the failure only
            // shows up later as a training error about a file that is not there.
            return $this->asJson([
                'success' => false,
                'error' => sprintf('Could not write “%s” to %s. Check the directory is writable.', $upload->name, $dir),
            ]);
        }

        $siteId = (int)Craft::$app->request->getBodyParam('siteId', 0);
        $rec = new TrainingFileRecord();
        $rec->siteId = $siteId > 0 ? $siteId : null;
        $rec->filename = $filename;
        $rec->originalName = $upload->name;
        $rec->size = $upload->size;
        $rec->status = 'pending';
        $rec->save(false);

        Craft::$app->queue->push(new IndexFileJob([
            'fileRecId' => (int)$rec->id,
            'absolutePath' => $path,
        ]));

        return $this->asJson(['success' => true, 'id' => (int)$rec->id]);
    }

    /**
     * Turn PHP's upload error constant into something an admin can act on.
     */
    private static function uploadErrorMessage(int $code, int $limit): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE => sprintf(
                'That file is larger than PHP allows (upload_max_filesize = %s). The limit here is %s.',
                ini_get('upload_max_filesize') ?: '?',
                self::formatBytes($limit),
            ),
            UPLOAD_ERR_FORM_SIZE => 'That file is larger than the form allows.',
            UPLOAD_ERR_PARTIAL => 'That file only uploaded partially. Try again.',
            UPLOAD_ERR_NO_FILE => 'No file uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'PHP has no temporary directory to receive uploads (upload_tmp_dir).',
            UPLOAD_ERR_CANT_WRITE => 'PHP could not write the upload to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension blocked this upload.',
            default => 'The upload failed (error code ' . $code . ').',
        };
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / 1024 / 1024, 1), '0'), '.') . ' MB';
        }
        return max(1, (int)round($bytes / 1024)) . ' KB';
    }

    public function actionReindexFile(): Response
    {
        $this->requirePostRequest();
        $id = (int)Craft::$app->request->getRequiredBodyParam('id');
        $rec = TrainingFileRecord::findOne($id);
        if (!$rec) {
            return $this->asJson(['success' => false, 'error' => 'Not found']);
        }
        $path = Plugin::getInstance()->getUploadPath() . DIRECTORY_SEPARATOR . $rec->filename;
        Craft::$app->queue->push(new IndexFileJob(['fileRecId' => (int)$rec->id, 'absolutePath' => $path]));
        return $this->asJson(['success' => true]);
    }

    public function actionDeleteFile(): Response
    {
        $this->requirePostRequest();
        $id = (int)Craft::$app->request->getRequiredBodyParam('id');
        Plugin::getInstance()->training->removeFile($id);
        return $this->asJson(['success' => true]);
    }

    // ---------- URLS ----------

    public function actionUrls(): Response
    {
        $rows = TrainingUrlRecord::find()->orderBy(['dateUpdated' => SORT_DESC])->all();
        return $this->renderTemplate('interactive-ai-assistant/training/urls', [
            'rows' => $rows,
            'sites' => Craft::$app->sites->getAllSites(),
        ]);
    }

    public function actionAddUrls(): Response
    {
        $this->requirePostRequest();
        $raw = (string)Craft::$app->request->getBodyParam('urls', '');
        $siteId = (int)Craft::$app->request->getBodyParam('siteId', 0);
        $urls = array_filter(array_map('trim', preg_split('/\r?\n/', $raw) ?: []));
        $added = 0;
        foreach ($urls as $u) {
            if (!filter_var($u, FILTER_VALIDATE_URL)) {
                continue;
            }
            $exists = TrainingUrlRecord::findOne(['url' => $u]);
            if ($exists) {
                continue;
            }
            $rec = new TrainingUrlRecord();
            $rec->url = $u;
            $rec->siteId = $siteId > 0 ? $siteId : null;
            $rec->source = 'manual';
            $rec->status = 'pending';
            $rec->save(false);
            // Queue the crawl immediately so URLs don't sit at "pending".
            Craft::$app->queue->push(new IndexUrlJob(['urlRecId' => (int)$rec->id]));
            $added++;
        }
        Craft::$app->session->setNotice("Added {$added} URLs; crawling in the background.");
        return $this->redirectToPostedUrl();
    }

    public function actionImportSitemap(): Response
    {
        $this->requirePostRequest();
        $url = (string)Craft::$app->request->getRequiredBodyParam('sitemap');
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            Craft::$app->session->setError('Invalid sitemap URL.');
            return $this->redirectToPostedUrl();
        }
        $siteId = (int)Craft::$app->request->getBodyParam('siteId', 0);
        Craft::$app->queue->push(new CrawlSitemapJob([
            'sitemapUrl' => $url,
            'autoIndex' => true,
            'siteId' => $siteId > 0 ? $siteId : null,
        ]));
        Craft::$app->session->setNotice('Sitemap import queued.');
        return $this->redirectToPostedUrl();
    }

    public function actionCrawlAll(): Response
    {
        $this->requirePostRequest();
        $pending = TrainingUrlRecord::find()->where(['status' => 'pending'])->all();
        foreach ($pending as $rec) {
            Craft::$app->queue->push(new IndexUrlJob(['urlRecId' => (int)$rec->id]));
        }
        Craft::$app->session->setNotice(count($pending) . ' URLs queued.');
        return $this->redirectToPostedUrl();
    }

    public function actionRecrawlUrl(): Response
    {
        $this->requirePostRequest();
        $id = (int)Craft::$app->request->getRequiredBodyParam('id');
        Craft::$app->queue->push(new IndexUrlJob(['urlRecId' => $id]));
        return $this->asJson(['success' => true]);
    }

    /**
     * Re-chunk and re-embed every trained source under current indexing settings.
     * Use after changing chunk size, contextual prefix, or embedding model/dimensions.
     */
    public function actionReindexAll(): Response
    {
        $this->requirePostRequest();
        $count = Plugin::getInstance()->training->reindexAll();
        return $this->asJson(['success' => true, 'queued' => $count]);
    }

    public function actionDeleteUrl(): Response
    {
        $this->requirePostRequest();
        $id = (int)Craft::$app->request->getRequiredBodyParam('id');
        Plugin::getInstance()->training->removeUrl($id);
        return $this->asJson(['success' => true]);
    }

    public function actionUrlChunks(int $id): Response
    {
        $rec = TrainingUrlRecord::findOne($id);
        if (!$rec) {
            throw new \yii\web\NotFoundHttpException();
        }
        $chunks = ChunkRecord::find()
            ->where(['sourceType' => 'url', 'sourceId' => $rec->id])
            ->orderBy(['position' => SORT_ASC])
            ->all();
        return $this->renderTemplate('interactive-ai-assistant/training/_chunks', [
            'title' => $rec->url,
            'subtitle' => 'URL',
            'chunks' => $chunks,
            'backUrl' => 'admin/interactive-ai-assistant/training/urls',
        ]);
    }

    // ---------- CUSTOM SOURCES (plugin-contributed) ----------

    public function actionSources(): Response
    {
        $sources = Plugin::getInstance()->sources->all();
        $blocks = [];
        foreach ($sources as $source) {
            $handle = $source->handle();
            $blocks[] = [
                'handle' => $handle,
                'label' => $source->label(),
                'rows' => TrainingSourceRecord::find()
                    ->where(['sourceKey' => $handle])
                    ->orderBy(['dateUpdated' => SORT_DESC])
                    ->all(),
            ];
        }
        return $this->renderTemplate('interactive-ai-assistant/training/sources', [
            'blocks' => $blocks,
        ]);
    }

    public function actionTrainSource(): Response
    {
        $this->requirePostRequest();
        $handle = (string)Craft::$app->request->getRequiredBodyParam('handle');
        $source = Plugin::getInstance()->sources->get($handle);
        if (!$source) {
            Craft::$app->session->setError('Unknown training source.');
            return $this->redirectToPostedUrl();
        }
        $queued = 0;
        foreach ($source->items() as $item) {
            $id = (int)($item['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $siteId = isset($item['siteId']) ? (int)$item['siteId'] : null;
            $title = (string)($item['title'] ?? '');
            Plugin::getInstance()->training->upsertSourceItem($handle, $id, $siteId, $title);
            Craft::$app->queue->push(new IndexSourceJob([
                'handle' => $handle,
                'itemId' => $id,
                'siteId' => $siteId,
            ]));
            $queued++;
        }
        Craft::$app->session->setNotice("Queued {$queued} items for training.");
        return $this->redirectToPostedUrl();
    }

    public function actionTrainSourceItem(): Response
    {
        $this->requirePostRequest();
        $id = (int)Craft::$app->request->getRequiredBodyParam('id');
        $rec = TrainingSourceRecord::findOne($id);
        if ($rec) {
            Craft::$app->queue->push(new IndexSourceJob([
                'handle' => (string)$rec->sourceKey,
                'itemId' => (int)$rec->itemId,
                'siteId' => (int)$rec->siteId,
            ]));
            return $this->asJson(['success' => true]);
        }
        return $this->asJson(['success' => false, 'error' => 'Not found']);
    }

    public function actionDeleteSource(): Response
    {
        $this->requirePostRequest();
        $id = (int)Craft::$app->request->getRequiredBodyParam('id');
        Plugin::getInstance()->training->removeSource($id);
        return $this->asJson(['success' => true]);
    }

    public function actionSourceChunks(int $id): Response
    {
        $rec = TrainingSourceRecord::findOne($id);
        if (!$rec) {
            throw new \yii\web\NotFoundHttpException();
        }
        $chunks = ChunkRecord::find()
            ->where(['sourceType' => $rec->sourceKey, 'sourceId' => $rec->id])
            ->orderBy(['position' => SORT_ASC])
            ->all();
        $source = Plugin::getInstance()->sources->get((string)$rec->sourceKey);
        return $this->renderTemplate('interactive-ai-assistant/training/_chunks', [
            'title' => $rec->title ?: ($rec->sourceKey . ' #' . $rec->itemId),
            'subtitle' => $source ? $source->label() : (string)$rec->sourceKey,
            'chunks' => $chunks,
            'backUrl' => 'admin/interactive-ai-assistant/training/sources',
        ]);
    }

    // ---------- Q&A ----------

    public function actionQa(): Response
    {
        $rows = TrainingQaRecord::find()->orderBy(['dateUpdated' => SORT_DESC])->all();
        return $this->renderTemplate('interactive-ai-assistant/training/qa', [
            'rows' => $rows,
            'sites' => Craft::$app->sites->getAllSites(),
        ]);
    }

    public function actionSaveQa(): Response
    {
        $this->requirePostRequest();
        $req = Craft::$app->request;
        $id = (int)$req->getBodyParam('id');
        $rec = $id ? TrainingQaRecord::findOne($id) : new TrainingQaRecord();
        if (!$rec) {
            $rec = new TrainingQaRecord();
        }
        $rec->question = trim((string)$req->getRequiredBodyParam('question'));
        $rec->answer = trim((string)$req->getRequiredBodyParam('answer'));
        if (!$id) {
            $rec->source = 'manual';
        }
        $rec->active = (bool)$req->getBodyParam('active', true);
        // 0 (or absent) means every site — the default, and the only meaningful
        // value on a single-site install.
        $siteId = (int)$req->getBodyParam('siteId', 0);
        $rec->siteId = $siteId > 0 ? $siteId : null;
        // Translating only makes sense for a pair shared across sites.
        $rec->translate = $rec->siteId === null && (bool)$req->getBodyParam('translate', false);
        if (!$rec->save()) {
            Craft::$app->session->setError('Could not save Q&A.');
            return $this->redirectToPostedUrl();
        }
        Plugin::getInstance()->training->trainQa((int)$rec->id);
        Craft::$app->session->setNotice('Q&A saved.');
        return $this->redirectToPostedUrl();
    }

    public function actionToggleQa(): Response
    {
        $this->requirePostRequest();
        $id = (int)Craft::$app->request->getRequiredBodyParam('id');
        $rec = TrainingQaRecord::findOne($id);
        if (!$rec) {
            return $this->asJson(['success' => false]);
        }
        $rec->active = !$rec->active;
        $rec->save(false);
        Plugin::getInstance()->training->trainQa((int)$rec->id);
        return $this->asJson(['success' => true, 'active' => (bool)$rec->active]);
    }

    public function actionDeleteQa(): Response
    {
        $this->requirePostRequest();
        $id = (int)Craft::$app->request->getRequiredBodyParam('id');
        Plugin::getInstance()->training->removeQa($id);
        return $this->asJson(['success' => true]);
    }

    // ---------- TRANSFER ----------

    public function actionTransfer(): Response
    {
        $settings = Plugin::getInstance()->getSettings();
        $counts = [
            'entries' => (int)TrainingEntryRecord::find()->count(),
            'categories' => (int)TrainingCategoryRecord::find()->count(),
            'globals' => (int)TrainingGlobalSetRecord::find()->count(),
            'files' => (int)TrainingFileRecord::find()->count(),
            'urls' => (int)TrainingUrlRecord::find()->count(),
            'qa' => (int)TrainingQaRecord::find()->count(),
            'sources' => (int)TrainingSourceRecord::find()->count(),
        ];
        return $this->renderTemplate('interactive-ai-assistant/training/transfer', [
            'counts' => $counts,
            'chunkCount' => (int)ChunkRecord::find()->count(),
            'embeddingModel' => (string)$settings->embeddingModel,
            'embeddingDimensions' => (int)$settings->embeddingDimensions,
            'sites' => Craft::$app->getSites()->getAllSites(),
        ]);
    }

    /**
     * Build a bundle and hand it straight back as a download, so moving a
     * trained index does not depend on shell access to either site.
     */
    public function actionExportBundle(): Response
    {
        $this->requirePostRequest();
        App::maxPowerCaptain();

        $kinds = $this->postedKinds();
        $path = sprintf(
            '%s/cs-chatbot/exports/training-%s.ndjson.gz',
            Craft::$app->getPath()->getStoragePath(),
            date('Ymd-His'),
        );

        try {
            $result = Plugin::getInstance()->transfer->export($path, [
                'only' => $kinds,
                'includeFiles' => (bool)Craft::$app->request->getBodyParam('includeFiles', true),
            ]);
        } catch (\Throwable $e) {
            Craft::$app->session->setError('Export failed: ' . $e->getMessage());
            return $this->redirectToPostedUrl();
        }

        // The bundle is written to storage first so it can be streamed without
        // holding it in memory; it is only ever this one download.
        $response = Craft::$app->getResponse()->sendFile($result['path'], basename($result['path']), [
            'mimeType' => 'application/gzip',
            'inline' => false,
        ]);
        $response->on(\yii\web\Response::EVENT_AFTER_SEND, function () use ($result) {
            @unlink($result['path']);
        });
        return $response;
    }

    public function actionImportBundle(): Response
    {
        $this->requirePostRequest();
        App::maxPowerCaptain();

        $upload = UploadedFile::getInstanceByName('bundle');
        if (!$upload) {
            Craft::$app->session->setError('Choose a bundle to import.');
            return $this->redirectToPostedUrl();
        }

        $temp = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR
            . 'cs-chatbot-import-' . StringHelper::randomString(8) . '.ndjson.gz';
        if (!$upload->saveAs($temp)) {
            Craft::$app->session->setError('The upload could not be saved — check the temp directory is writable.');
            return $this->redirectToPostedUrl();
        }

        $request = Craft::$app->request;
        try {
            $result = Plugin::getInstance()->transfer->import($temp, [
                'only' => $this->postedKinds(),
                'reembed' => (bool)$request->getBodyParam('reembed'),
                'dryRun' => (bool)$request->getBodyParam('dryRun'),
                'overwriteFiles' => (bool)$request->getBodyParam('overwriteFiles'),
                'siteMap' => $this->postedSiteMap(),
            ]);
        } catch (\Throwable $e) {
            @unlink($temp);
            Craft::$app->session->setError('Import failed: ' . $e->getMessage());
            return $this->redirectToPostedUrl();
        }
        @unlink($temp);

        $imported = array_sum($result['imported']);
        $skipped = array_sum($result['skipped']);
        if ($result['dryRun']) {
            $message = "Dry run: {$imported} source(s) would be imported ({$result['chunks']} chunks)";
        } elseif ($result['queued'] > 0) {
            $message = "{$imported} source(s) imported and queued for embedding — run the queue";
        } else {
            $message = "{$imported} source(s) imported, {$result['chunks']} chunk(s) indexed";
        }
        if ($skipped > 0) {
            $message .= ", {$skipped} skipped";
        }
        Craft::$app->session->setNotice($message . '.');
        if ($result['warnings']) {
            // One flash holds one message, and what was skipped is the whole
            // point of the report — so say it in a single line rather than
            // letting each note overwrite the last.
            $notes = array_slice($result['warnings'], 0, 3);
            $rest = count($result['warnings']) - count($notes);
            Craft::$app->session->setError(
                implode(' ', $notes) . ($rest > 0 ? " (+{$rest} more — run rag/import for the full report.)" : '')
            );
        }
        return $this->redirectToPostedUrl();
    }

    /**
     * @return string[]|null null when every kind is selected
     */
    private function postedKinds(): ?array
    {
        $kinds = Craft::$app->request->getBodyParam('kinds');
        if (!is_array($kinds)) {
            return null;
        }
        $kinds = array_values(array_intersect(Transfer::KINDS, $kinds));
        return count($kinds) === count(Transfer::KINDS) ? null : $kinds;
    }

    /**
     * `sk=en, hu=de` — bundle site handle to local site handle.
     *
     * @return array<string, string>
     */
    private function postedSiteMap(): array
    {
        $map = [];
        foreach (explode(',', (string)Craft::$app->request->getBodyParam('siteMap', '')) as $pair) {
            $parts = array_map('trim', explode('=', $pair, 2));
            if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                $map[$parts[0]] = $parts[1];
            }
        }
        return $map;
    }
}
