<?php

namespace cstudiossro\craftcschatbot\controllers;

use Craft;
use craft\web\Controller;
use cstudiossro\craftcschatbot\helpers\CraftCompat;
use cstudiossro\craftcschatbot\models\Settings;
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

    /**
     * Everything the settings screens need to describe the site's content
     * structure, including the field map each scope offers for exclusion.
     *
     * @return array<string, mixed>
     */
    private function structureVariables(): array
    {
        $sections = CraftCompat::getAllSections();
        $categoryGroups = Craft::$app->categories->getAllGroups();
        $globalSets = Craft::$app->globals->getAllSets();

        // Field-exclusion scopes, most specific last so the everywhere list
        // reads as the heading it is. Scopes with no custom fields are dropped
        // — an empty checkbox list is only clutter.
        $scopes = [];
        $allFields = [];
        foreach ([['Section', $sections], ['Category group', $categoryGroups], ['Global set', $globalSets]] as [$kind, $group]) {
            foreach ($group as $scope) {
                $map = CraftCompat::scopeFieldMap($scope);
                $allFields += $map;
                if ($map) {
                    $scopes[] = [
                        'label' => $kind . ': ' . $scope->name,
                        'uid' => $scope->uid,
                        'options' => self::fieldOptions($map),
                    ];
                }
            }
        }
        asort($allFields, SORT_NATURAL | SORT_FLAG_CASE);
        if ($allFields) {
            array_unshift($scopes, [
                'label' => 'Every section, group and set',
                'uid' => Settings::EXCLUDE_ALL_SCOPES,
                'options' => self::fieldOptions($allFields),
            ]);
        }

        return [
            'sections' => $sections,
            'categoryGroups' => $categoryGroups,
            'globalSets' => $globalSets,
            'excludeScopes' => $scopes,
        ];
    }

    /**
     * @param array<string, string> $map handle => label
     * @return array<int, array{label:string, value:string}>
     */
    private static function fieldOptions(array $map): array
    {
        $options = [];
        foreach ($map as $handle => $label) {
            $options[] = [
                'label' => $label === $handle ? $handle : "{$label} ({$handle})",
                'value' => $handle,
            ];
        }
        return $options;
    }

    public function actionEdit(?string $tab = null): Response
    {
        $tab ??= 'general';
        $plugin = Plugin::getInstance();
        return $this->renderTemplate('interactive-ai-assistant/settings/_index', [
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
            'capabilities' => Plugin::getInstance()->capabilities->all(),
            'tab' => $tab,
        ] + $this->structureVariables());
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
        foreach (['trainingSections', 'pageRenderSections'] as $list) {
            if (isset($data[$list]) && !is_array($data[$list])) {
                $data[$list] = [$data[$list]];
            }
            if (isset($data[$list])) {
                $data[$list] = array_values(array_filter(array_map('trim', (array)$data[$list]), fn($v) => $v !== ''));
            }
        }
        // checkboxSelect posts '' for a scope with nothing ticked; keep only the
        // scopes that actually exclude something so the setting stays readable.
        if (isset($data['excludedFields'])) {
            $clean = [];
            foreach ((array)$data['excludedFields'] as $uid => $handles) {
                $handles = array_values(array_filter(array_map('trim', (array)$handles), fn($h) => $h !== ''));
                if ($handles) {
                    $clean[(string)$uid] = $handles;
                }
            }
            $data['excludedFields'] = $clean;
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
                'capabilities' => Plugin::getInstance()->capabilities->all(),
                'tab' => $req->getBodyParam('tab', 'general'),
            ] + $this->structureVariables());
        }
        Craft::$app->plugins->savePluginSettings($plugin, $settings->getAttributes());
        Craft::$app->session->setNotice('Settings saved.');
        return $this->redirectToPostedUrl();
    }
}
