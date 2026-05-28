<?php

namespace cstudiossro\craftcschatbot\controllers;

use Craft;
use craft\web\Controller;
use cstudiossro\craftcschatbot\Plugin;
use yii\web\Response;

class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('accessPlugin-interactive-ai-assistant');
        return true;
    }

    public function actionEdit(?string $tab = null): Response
    {
        $tab ??= 'general';
        $plugin = Plugin::getInstance();
        return $this->renderTemplate('interactive-ai-assistant/settings/_index', [
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
            'sections' => Craft::$app->entries->getAllSections(),
            'categoryGroups' => Craft::$app->categories->getAllGroups(),
            'globalSets' => Craft::$app->globals->getAllSets(),
            'tab' => $tab,
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $req = Craft::$app->request;
        $data = (array)$req->getBodyParam('settings', []);

        // Normalize boolean & list fields that arrive as strings
        if (isset($data['suggestions']) && is_string($data['suggestions'])) {
            $data['suggestions'] = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $data['suggestions']) ?: [])));
        }
        if (isset($data['filterBlockedWords']) && is_string($data['filterBlockedWords'])) {
            $data['filterBlockedWords'] = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $data['filterBlockedWords']) ?: [])));
        }
        // Per-site suggestions arrive as textarea strings keyed by site UID. Normalize each to array.
        if (isset($data['suggestionsBySite']) && is_array($data['suggestionsBySite'])) {
            foreach ($data['suggestionsBySite'] as $uid => $value) {
                if (is_string($value)) {
                    $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $value) ?: [])));
                    if (empty($lines)) {
                        unset($data['suggestionsBySite'][$uid]);
                    } else {
                        $data['suggestionsBySite'][$uid] = $lines;
                    }
                } elseif (!is_array($value)) {
                    unset($data['suggestionsBySite'][$uid]);
                }
            }
        }
        // Drop empty per-site overrides so the fallback to defaults kicks in cleanly.
        foreach (['initialMessages', 'companyNames', 'systemPrompts'] as $bag) {
            if (isset($data[$bag]) && is_array($data[$bag])) {
                $data[$bag] = array_filter(array_map(fn($v) => is_string($v) ? trim($v) : $v, $data[$bag]), fn($v) => $v !== '' && $v !== null);
            }
        }
        if (isset($data['chatTemplates']) && is_string($data['chatTemplates'])) {
            // Templates separated by a line containing only --- (3+ dashes). Each block may be multi-line.
            // Match separator at start, middle, or end of input (with or without trailing newline).
            $parts = preg_split('/(?:\A|\r?\n)---+[ \t]*(?:\r?\n|\z)/', $data['chatTemplates']) ?: [];
            $data['chatTemplates'] = array_values(array_filter(array_map('trim', $parts), fn($v) => $v !== ''));
        }
        if (isset($data['trainingSections']) && !is_array($data['trainingSections'])) {
            $data['trainingSections'] = [$data['trainingSections']];
        }
        if (isset($data['logoAssetId']) && is_array($data['logoAssetId'])) {
            $data['logoAssetId'] = (int)($data['logoAssetId'][0] ?? 0) ?: null;
        }

        $settings->setAttributes($data, false);
        if (!$settings->validate()) {
            Craft::$app->session->setError('Could not save settings.');
            Craft::$app->urlManager->setRouteParams(['settings' => $settings]);
            return $this->renderTemplate('interactive-ai-assistant/settings/_index', [
                'plugin' => $plugin,
                'settings' => $settings,
                'sections' => Craft::$app->entries->getAllSections(),
                'categoryGroups' => Craft::$app->categories->getAllGroups(),
                'globalSets' => Craft::$app->globals->getAllSets(),
                'tab' => $req->getBodyParam('tab', 'general'),
            ]);
        }
        Craft::$app->plugins->savePluginSettings($plugin, $settings->getAttributes());
        Craft::$app->session->setNotice('Settings saved.');
        return $this->redirectToPostedUrl();
    }
}
