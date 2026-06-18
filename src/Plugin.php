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
use cstudiossro\craftcschatbot\jobs\IndexSourceJob;
use cstudiossro\craftcschatbot\capabilities\ConfiguredFormCapability;
use cstudiossro\craftcschatbot\events\RegisterCapabilitiesEvent;
use cstudiossro\craftcschatbot\models\Settings;
use cstudiossro\craftcschatbot\services\Bans;
use cstudiossro\craftcschatbot\services\Capabilities;
use cstudiossro\craftcschatbot\services\Chat as ChatService;
use cstudiossro\craftcschatbot\services\Contacts;
use cstudiossro\craftcschatbot\services\Embeddings;
use cstudiossro\craftcschatbot\services\Filter;
use cstudiossro\craftcschatbot\services\Forms;
use cstudiossro\craftcschatbot\services\Handoff;
use cstudiossro\craftcschatbot\services\OpenAi;
use cstudiossro\craftcschatbot\services\Sources;
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
 * @property-read Contacts $contacts
 * @property-read Capabilities $capabilities
 * @property-read Forms $forms
 * @property-read Sources $sources
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.3.0';
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
                'contacts' => Contacts::class,
                'capabilities' => Capabilities::class,
                'forms' => Forms::class,
                'sources' => Sources::class,
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
            $this->registerSourceAutoTrain();
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
                "window.csChatbotCpNav = { badgeUrl: " . json_encode(UrlHelper::actionUrl('interactive-ai-assistant/handoff/badge-count')) . " };",
                View::POS_HEAD,
            );
        });
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'AI Assistant';
        $item['url'] = 'interactive-ai-assistant';

        // Badges are rendered client-side by cpnav.js to avoid a flash of the
        // native single badge before the 3-color custom badges replace it.
        $settings = $this->getSettings();
        $item['subnav'] = [
            'dashboard' => ['label' => 'Dashboard', 'url' => 'interactive-ai-assistant'],
        ];
        if ($settings->humanHandoffEnabled) {
            $item['subnav']['live-chat'] = ['label' => 'Live Chat', 'url' => 'interactive-ai-assistant/live-chat'];
        }
        // Captured leads: missed chats + (optionally) form submissions, paired
        // via in-page tabs under one nav entry.
        $item['subnav']['leads'] = ['label' => 'Leads', 'url' => 'interactive-ai-assistant/missed-chats'];
        if ($settings->formsEnabled) {
            $item['subnav']['forms'] = ['label' => 'Forms', 'url' => 'interactive-ai-assistant/forms'];
        }
        $item['subnav'] += [
            'training' => ['label' => 'Training', 'url' => 'interactive-ai-assistant/training/entries'],
            // Chat logs + bans grouped under one entry (in-page tabs).
            'logs-bans' => ['label' => 'Logs & Bans', 'url' => 'interactive-ai-assistant/logs'],
            'settings' => ['label' => 'Settings', 'url' => 'interactive-ai-assistant/settings'],
        ];
        return $item;
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->controller->redirect(UrlHelper::cpUrl('interactive-ai-assistant/settings'));
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
        $this->registerFormCapabilities();
    }

    /**
     * Turn each admin-defined form into an assistant capability so it flows
     * through the existing tool-calling loop and the per-skill availability UI.
     */
    private function registerFormCapabilities(): void
    {
        Event::on(Capabilities::class, Capabilities::EVENT_REGISTER_CAPABILITIES, function (RegisterCapabilitiesEvent $event) {
            $settings = $this->getSettings();
            if (!$settings->formsEnabled) {
                return;
            }
            foreach ($settings->formDefinitions() as $form) {
                $event->capabilities[] = new ConfiguredFormCapability($form);
            }
        });
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
                'interactive-ai-assistant' => 'interactive-ai-assistant/dashboard/index',
                'interactive-ai-assistant/dashboard' => 'interactive-ai-assistant/dashboard/index',

                'interactive-ai-assistant/training' => 'interactive-ai-assistant/training/entries',
                'interactive-ai-assistant/training/entries' => 'interactive-ai-assistant/training/entries',
                'interactive-ai-assistant/training/categories' => 'interactive-ai-assistant/training/categories',
                'interactive-ai-assistant/training/globals' => 'interactive-ai-assistant/training/globals',
                'interactive-ai-assistant/training/files' => 'interactive-ai-assistant/training/files',
                'interactive-ai-assistant/training/urls' => 'interactive-ai-assistant/training/urls',
                'interactive-ai-assistant/training/qa' => 'interactive-ai-assistant/training/qa',
                'interactive-ai-assistant/training/sources' => 'interactive-ai-assistant/training/sources',
                'interactive-ai-assistant/training/entry-chunks/<id:\d+>' => 'interactive-ai-assistant/training/entry-chunks',
                'interactive-ai-assistant/training/source-chunks/<id:\d+>' => 'interactive-ai-assistant/training/source-chunks',
                'interactive-ai-assistant/training/url-chunks/<id:\d+>' => 'interactive-ai-assistant/training/url-chunks',
                'interactive-ai-assistant/training/category-chunks/<id:\d+>' => 'interactive-ai-assistant/training/category-chunks',
                'interactive-ai-assistant/training/global-chunks/<id:\d+>' => 'interactive-ai-assistant/training/global-chunks',

                'interactive-ai-assistant/logs' => 'interactive-ai-assistant/logs/index',
                'interactive-ai-assistant/logs/toggle-star' => 'interactive-ai-assistant/logs/toggle-star',
                'interactive-ai-assistant/logs/save-note' => 'interactive-ai-assistant/logs/save-note',
                'interactive-ai-assistant/logs/session/<id:\d+>' => 'interactive-ai-assistant/logs/session',

                'interactive-ai-assistant/live-chat' => 'interactive-ai-assistant/handoff/index',
                'interactive-ai-assistant/live-chat/toggle-star' => 'interactive-ai-assistant/handoff/toggle-star',

                'interactive-ai-assistant/missed-chats' => 'interactive-ai-assistant/contacts/index',
                'interactive-ai-assistant/missed-chats/resolve' => 'interactive-ai-assistant/contacts/resolve',
                'interactive-ai-assistant/missed-chats/delete' => 'interactive-ai-assistant/contacts/delete',
                'interactive-ai-assistant/missed-chats/restore' => 'interactive-ai-assistant/contacts/restore',
                'interactive-ai-assistant/missed-chats/destroy' => 'interactive-ai-assistant/contacts/destroy',

                'interactive-ai-assistant/forms' => 'interactive-ai-assistant/forms/index',
                'interactive-ai-assistant/forms/new' => 'interactive-ai-assistant/forms/edit',
                'interactive-ai-assistant/forms/edit/<name:[a-zA-Z0-9_-]+>' => 'interactive-ai-assistant/forms/edit',
                'interactive-ai-assistant/forms/save' => 'interactive-ai-assistant/forms/save',
                'interactive-ai-assistant/forms/delete' => 'interactive-ai-assistant/forms/delete',

                'interactive-ai-assistant/submissions' => 'interactive-ai-assistant/form-submissions/index',
                'interactive-ai-assistant/submissions/retry' => 'interactive-ai-assistant/form-submissions/retry',
                'interactive-ai-assistant/submissions/delete' => 'interactive-ai-assistant/form-submissions/delete',
                'interactive-ai-assistant/submissions/view/<id:\d+>' => 'interactive-ai-assistant/form-submissions/view',

                'interactive-ai-assistant/bans' => 'interactive-ai-assistant/bans/index',
                'interactive-ai-assistant/bans/create' => 'interactive-ai-assistant/bans/create',
                'interactive-ai-assistant/bans/delete' => 'interactive-ai-assistant/bans/delete',

                'interactive-ai-assistant/settings' => 'interactive-ai-assistant/settings/edit',
            ];
            $event->rules = array_merge($event->rules, $rules);
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function (RegisterUserPermissionsEvent $event) {
            // accessPlugin-interactive-ai-assistant is auto-registered. Add granular perms here if needed.
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

    /**
     * Attach auto-train handlers for any registered custom training source that
     * opts in via elementType(). Deferred to onInit so plugins/modules have had
     * a chance to register their sources first.
     */
    private function registerSourceAutoTrain(): void
    {
        foreach ($this->sources->all() as $source) {
            $elementType = $source->elementType();
            if (!$elementType || !class_exists($elementType)) {
                continue;
            }
            $handle = $source->handle();
            Event::on($elementType, \craft\base\Element::EVENT_AFTER_SAVE, function (ModelEvent $event) use ($handle) {
                if (!$this->getSettings()->autoTrainOnSave) {
                    return;
                }
                /** @var \craft\base\Element $el */
                $el = $event->sender;
                if ($el->getIsDraft() || $el->getIsRevision() || $el->propagating || $el->resaving) {
                    return;
                }
                Craft::$app->queue->push(new IndexSourceJob([
                    'handle' => $handle,
                    'itemId' => (int)$el->id,
                    'siteId' => (int)$el->siteId,
                ]));
            });
        }
    }

    /**
     * Whether the chat widget should be served to the current request.
     * In debug mode the widget is restricted to logged-in control-panel users.
     */
    public function widgetVisibleForCurrentUser(): bool
    {
        $settings = $this->getSettings();
        if (!$settings->enabled) {
            return false;
        }
        if (!$settings->debugMode) {
            return true;
        }
        $user = Craft::$app->getUser();
        return !$user->getIsGuest() && $user->checkPermission('accessCp');
    }

    private function registerWidgetInjection(): void
    {
        $req = Craft::$app->getRequest();
        if ($req->getIsCpRequest() || $req->getIsConsoleRequest() || !$req->getIsSiteRequest()) {
            return;
        }
        if (!$this->widgetVisibleForCurrentUser()) {
            return;
        }

        Event::on(View::class, View::EVENT_END_BODY, function () {
            $view = Craft::$app->getView();
            $view->registerAssetBundle(WidgetAsset::class);
            $urls = [
                'config' => UrlHelper::actionUrl('interactive-ai-assistant/chat/config'),
                'send' => UrlHelper::actionUrl('interactive-ai-assistant/chat/send'),
                'rate' => UrlHelper::actionUrl('interactive-ai-assistant/chat/rate'),
                'suggestionClick' => UrlHelper::actionUrl('interactive-ai-assistant/chat/suggestion-click'),
                'poll' => UrlHelper::actionUrl('interactive-ai-assistant/chat/poll'),
                'requestHuman' => UrlHelper::actionUrl('interactive-ai-assistant/chat/request-human'),
                'submitContact' => UrlHelper::actionUrl('interactive-ai-assistant/chat/submit-contact'),
                'submitForm' => UrlHelper::actionUrl('interactive-ai-assistant/chat/submit-form'),
                'end' => UrlHelper::actionUrl('interactive-ai-assistant/chat/end'),
                'rateChat' => UrlHelper::actionUrl('interactive-ai-assistant/chat/rate-chat'),
                'og' => UrlHelper::actionUrl('interactive-ai-assistant/og/fetch'),
            ];
            $view->registerJs(
                "window.csChatbot = window.csChatbot || {};" .
                "window.csChatbot.urls = " . json_encode($urls) . ";",
                View::POS_HEAD,
            );
        });
    }
}
