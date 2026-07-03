<?php

namespace cstudiossro\craftcschatbot\controllers;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\web\Controller;
use craft\web\UploadedFile;
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
        $sections = Craft::$app->entries->getAllSections();
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
            $s = Craft::$app->entries->getSectionByUid($uid);
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
            $g = Craft::$app->categories->getGroupByUid($uid);
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
            $s = Craft::$app->globals->getSetByUid($uid);
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

    public function actionFiles(): Response
    {
        $rows = TrainingFileRecord::find()->orderBy(['dateUpdated' => SORT_DESC])->all();
        return $this->renderTemplate('interactive-ai-assistant/training/files', [
            'rows' => $rows,
        ]);
    }

    public function actionUploadFile(): Response
    {
        $this->requirePostRequest();
        $upload = UploadedFile::getInstanceByName('file');
        if (!$upload) {
            return $this->asJson(['success' => false, 'error' => 'No file uploaded']);
        }
        $allowed = ['txt', 'md'];
        $ext = strtolower($upload->getExtension());
        if (!in_array($ext, $allowed, true)) {
            return $this->asJson(['success' => false, 'error' => 'Only .txt and .md files allowed']);
        }
        if ($upload->size > 5 * 1024 * 1024) {
            return $this->asJson(['success' => false, 'error' => 'File exceeds 5 MB']);
        }
        $dir = Plugin::getInstance()->getUploadPath();
        FileHelper::createDirectory($dir);
        $filename = StringHelper::randomString(12) . '.' . $ext;
        $path = $dir . DIRECTORY_SEPARATOR . $filename;
        $upload->saveAs($path);

        $rec = new TrainingFileRecord();
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
        ]);
    }

    public function actionAddUrls(): Response
    {
        $this->requirePostRequest();
        $raw = (string)Craft::$app->request->getBodyParam('urls', '');
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
        Craft::$app->queue->push(new CrawlSitemapJob(['sitemapUrl' => $url, 'autoIndex' => true]));
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
}
