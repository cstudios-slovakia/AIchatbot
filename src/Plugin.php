<?php

namespace cstudiossro\craftcschatbot;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\events\ModelEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\FileHelper;
use craft\helpers\UrlHelper;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\View;
use cstudiossro\craftcschatbot\jobs\IndexCategoryJob;
use cstudiossro\craftcschatbot\jobs\IndexEntryJob;
use cstudiossro\craftcschatbot\jobs\IndexGlobalSetJob;
use cstudiossro\craftcschatbot\models\Settings;
use cstudiossro\craftcschatbot\services\Bans;
use cstudiossro\craftcschatbot\services\Chat as ChatService;
use cstudiossro\craftcschatbot\services\Embeddings;
use cstudiossro\craftcschatbot\services\Filter;
use cstudiossro\craftcschatbot\services\Handoff;
use cstudiossro\craftcschatbot\services\OpenAi;
use cstudiossro\craftcschatbot\services\Stats;
use cstudiossro\craftcschatbot\services\Training;
use cstudiossro\craftcschatbot\services\VectorSearch;
use cstudiossro\craftcschatbot\web\assets\cpnav\CpNavAsset;
use cstudiossro\craftcschatbot\web\assets\WidgetAsset;
use yii\base\Event;

/**
 * cs-chatbot plugin
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read OpenAi $openAi
 * @property-read Embeddings $embeddings
 * @property-read VectorSearch $vectorSearch
 * @property-read Training $training
 * @property-read ChatService $chat
 * @property-read Stats $stats
 * @property-read Handoff $handoff
 * @property-read Bans $bans
 * @property-read Filter $filter
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.7.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'openAi' => OpenAi::class,
                'embeddings' => Embeddings::class,
                'vectorSearch' => VectorSearch::class,
                'training' => Training::class,
                'chat' => ChatService::class,
                'stats' => Stats::class,
                'handoff' => Handoff::class,
                'bans' => Bans::class,
                'filter' => Filter::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();
        $this->attachEventHandlers();

        Craft::$app->onInit(function () {
            $this->registerWidgetInjection();
            $this->registerCpNavPolling();
        });
    }

    private function registerCpNavPolling(): void
    {
        $req = Craft::$app->getRequest();
        if (!$req->getIsCpRequest() || $req->getIsConsoleRequest()) {
            return;
        }
        Event::on(View::class, View::EVENT_END_BODY, function () {
            $view = Craft::$app->getView();
            $view->registerAssetBundle(CpNavAsset::class);
            $view->registerJs(
                "window.csChatbotCpNav = { badgeUrl: " . json_encode(UrlHelper::actionUrl('_cs-chatbot/handoff/badge-count')) . " };",
                View::POS_HEAD,
            );
        });
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'AI Assistant';
        $item['url'] = '_cs-chatbot';

        // Badges are rendered client-side by cpnav.js to avoid a flash of the
        // native single badge before the 3-color custom badges replace it.
        $item['subnav'] = [
            'dashboard' => ['label' => 'Dashboard', 'url' => '_cs-chatbot'],
            'live-chat' => ['label' => 'Live Chat', 'url' => '_cs-chatbot/live-chat'],
            'training' => ['label' => 'Training', 'url' => '_cs-chatbot/training/entries'],
            'logs' => ['label' => 'Chat Logs', 'url' => '_cs-chatbot/logs'],
            'bans' => ['label' => 'Bans', 'url' => '_cs-chatbot/bans'],
            'settings' => ['label' => 'Settings', 'url' => '_cs-chatbot/settings'],
        ];
        return $item;
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->controller->redirect(UrlHelper::cpUrl('_cs-chatbot/settings'));
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return null;
    }

    public function getUploadPath(): string
    {
        $path = Craft::$app->path->getStoragePath() . '/cs-chatbot/uploads';
        FileHelper::createDirectory($path);
        return $path;
    }

    private function attachEventHandlers(): void
    {
        $this->registerUrlRules();
        $this->registerPermissions();
        $this->registerAutoTrain();
        $this->registerGc();
    }

    private function registerGc(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function () {
            $days = (int)$this->getSettings()->logRetentionDays;
            if ($days > 0) {
                $this->chat->purgeOldLogs($days);
            }
            $this->bans->purgeExpired();
            $idle = (int)$this->getSettings()->autoCloseInactiveMinutes;
            if ($idle > 0) {
                $this->handoff->sweepInactive($idle);
            }
        });
    }

    private function registerUrlRules(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function (RegisterUrlRulesEvent $event) {
            $rules = [
                '_cs-chatbot' => '_cs-chatbot/dashboard/index',
                '_cs-chatbot/dashboard' => '_cs-chatbot/dashboard/index',

                '_cs-chatbot/training' => '_cs-chatbot/training/entries',
                '_cs-chatbot/training/entries' => '_cs-chatbot/training/entries',
                '_cs-chatbot/training/categories' => '_cs-chatbot/training/categories',
                '_cs-chatbot/training/globals' => '_cs-chatbot/training/globals',
                '_cs-chatbot/training/files' => '_cs-chatbot/training/files',
                '_cs-chatbot/training/urls' => '_cs-chatbot/training/urls',
                '_cs-chatbot/training/qa' => '_cs-chatbot/training/qa',
                '_cs-chatbot/training/entry-chunks/<id:\d+>' => '_cs-chatbot/training/entry-chunks',
                '_cs-chatbot/training/url-chunks/<id:\d+>' => '_cs-chatbot/training/url-chunks',
                '_cs-chatbot/training/category-chunks/<id:\d+>' => '_cs-chatbot/training/category-chunks',
                '_cs-chatbot/training/global-chunks/<id:\d+>' => '_cs-chatbot/training/global-chunks',

                '_cs-chatbot/logs' => '_cs-chatbot/logs/index',
                '_cs-chatbot/logs/toggle-star' => '_cs-chatbot/logs/toggle-star',
                '_cs-chatbot/logs/save-note' => '_cs-chatbot/logs/save-note',
                '_cs-chatbot/logs/session/<id:\d+>' => '_cs-chatbot/logs/session',

                '_cs-chatbot/live-chat' => '_cs-chatbot/handoff/index',
                '_cs-chatbot/live-chat/toggle-star' => '_cs-chatbot/handoff/toggle-star',

                '_cs-chatbot/bans' => '_cs-chatbot/bans/index',
                '_cs-chatbot/bans/create' => '_cs-chatbot/bans/create',
                '_cs-chatbot/bans/delete' => '_cs-chatbot/bans/delete',

                '_cs-chatbot/settings' => '_cs-chatbot/settings/edit',
            ];
            $event->rules = array_merge($event->rules, $rules);
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function (RegisterUserPermissionsEvent $event) {
            // accessPlugin-_cs-chatbot is auto-registered. Add granular perms here if needed.
        });
    }

    private function registerAutoTrain(): void
    {
        Event::on(Entry::class, Entry::EVENT_AFTER_SAVE, function (ModelEvent $event) {
            $settings = $this->getSettings();
            if (!$settings->autoTrainOnSave) {
                return;
            }
            /** @var Entry $entry */
            $entry = $event->sender;
            if ($entry->getIsDraft() || $entry->getIsRevision() || $entry->propagating || $entry->resaving) {
                return;
            }
            $section = $entry->getSection();
            if (!$section || !in_array($section->uid, $settings->trainingSections, true)) {
                return;
            }
            Craft::$app->queue->push(new IndexEntryJob([
                'entryId' => (int)$entry->id,
                'siteId' => (int)$entry->siteId,
            ]));
        });

        Event::on(Category::class, Category::EVENT_AFTER_SAVE, function (ModelEvent $event) {
            $settings = $this->getSettings();
            if (!$settings->autoTrainOnSave) {
                return;
            }
            /** @var Category $cat */
            $cat = $event->sender;
            if ($cat->getIsDraft() || $cat->getIsRevision() || $cat->propagating || $cat->resaving) {
                return;
            }
            $group = $cat->getGroup();
            if (!$group || !in_array($group->uid, $settings->trainingCategoryGroups, true)) {
                return;
            }
            Craft::$app->queue->push(new IndexCategoryJob([
                'categoryId' => (int)$cat->id,
                'siteId' => (int)$cat->siteId,
            ]));
        });

        Event::on(GlobalSet::class, GlobalSet::EVENT_AFTER_SAVE, function (ModelEvent $event) {
            $settings = $this->getSettings();
            if (!$settings->autoTrainOnSave) {
                return;
            }
            /** @var GlobalSet $set */
            $set = $event->sender;
            if ($set->propagating || $set->resaving) {
                return;
            }
            if (!in_array($set->uid, $settings->trainingGlobalSets, true)) {
                return;
            }
            Craft::$app->queue->push(new IndexGlobalSetJob([
                'globalSetId' => (int)$set->id,
                'siteId' => (int)$set->siteId,
            ]));
        });
    }

    private function registerWidgetInjection(): void
    {
        $req = Craft::$app->getRequest();
        if ($req->getIsCpRequest() || $req->getIsConsoleRequest() || !$req->getIsSiteRequest()) {
            return;
        }
        if (!$this->getSettings()->enabled) {
            return;
        }

        Event::on(View::class, View::EVENT_END_BODY, function () {
            $view = Craft::$app->getView();
            $view->registerAssetBundle(WidgetAsset::class);
            $urls = [
                'config' => UrlHelper::actionUrl('_cs-chatbot/chat/config'),
                'send' => UrlHelper::actionUrl('_cs-chatbot/chat/send'),
                'rate' => UrlHelper::actionUrl('_cs-chatbot/chat/rate'),
                'suggestionClick' => UrlHelper::actionUrl('_cs-chatbot/chat/suggestion-click'),
                'poll' => UrlHelper::actionUrl('_cs-chatbot/chat/poll'),
                'requestHuman' => UrlHelper::actionUrl('_cs-chatbot/chat/request-human'),
                'end' => UrlHelper::actionUrl('_cs-chatbot/chat/end'),
                'rateChat' => UrlHelper::actionUrl('_cs-chatbot/chat/rate-chat'),
                'og' => UrlHelper::actionUrl('_cs-chatbot/og/fetch'),
            ];
            $view->registerJs(
                "window.csChatbot = window.csChatbot || {};" .
                "window.csChatbot.urls = " . json_encode($urls) . ";",
                View::POS_HEAD,
            );
        });
    }
}
