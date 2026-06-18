<?php

namespace cstudiossro\craftcschatbot\controllers;

use Craft;
use craft\web\Controller;
use cstudiossro\craftcschatbot\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CRUD for admin-defined conversational forms. Definitions live in the plugin
 * settings ({@see \cstudiossro\craftcschatbot\models\Settings::$forms}); each is
 * surfaced to the model as a tool via ConfiguredFormCapability.
 */
class FormsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('accessPlugin-interactive-ai-assistant');
        if (!Plugin::getInstance()->getSettings()->formsEnabled) {
            throw new NotFoundHttpException('Forms are disabled.');
        }
        return true;
    }

    public function actionIndex(): Response
    {
        $settings = Plugin::getInstance()->getSettings();
        return $this->renderTemplate('interactive-ai-assistant/forms/index', [
            'forms' => $settings->formDefinitions(),
            'agentModeEnabled' => $settings->agentModeEnabled,
        ]);
    }

    public function actionEdit(?string $name = null): Response
    {
        $settings = Plugin::getInstance()->getSettings();
        $form = null;
        if ($name !== null) {
            $form = $settings->getForm($name);
            if (!$form) {
                throw new NotFoundHttpException('Form not found.');
            }
        }
        return $this->renderTemplate('interactive-ai-assistant/forms/edit', [
            'form' => $form,
            'isNew' => $form === null,
            'agentModeEnabled' => $settings->agentModeEnabled,
            'availability' => $form ? $settings->capabilityState((string)$form['name']) : 'on',
            'contactFormInstalled' => Craft::$app->plugins->isPluginEnabled('contact-form'),
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $req = Craft::$app->request;

        $posted = (array)$req->getBodyParam('form', []);
        $originalName = (string)$req->getBodyParam('originalName', '');
        $form = $this->normalizeForm($posted);

        // Replace the original (handles renames) and any clash on the new name,
        // then append the cleaned definition.
        $forms = [];
        foreach ($settings->forms as $existing) {
            $existingName = is_array($existing) ? (string)($existing['name'] ?? '') : '';
            if ($existingName === $originalName || $existingName === $form['name']) {
                continue;
            }
            $forms[] = $existing;
        }
        $forms[] = $form;
        $settings->forms = $forms;

        // Availability (off/on/admins) is shared with the skills UI via capabilityStates.
        $availability = (string)$req->getBodyParam('availability', 'on');
        if (in_array($availability, ['off', 'on', 'admins'], true) && $form['name'] !== '') {
            $states = $settings->capabilityStates;
            $states[$form['name']] = $availability;
            $settings->capabilityStates = $states;
        }

        if (!$settings->validate()) {
            Craft::$app->session->setError(implode(' ', $settings->getErrors('forms') ?: ['Could not save form.']));
            Craft::$app->urlManager->setRouteParams([
                'form' => $form,
                'isNew' => false,
                'agentModeEnabled' => $settings->agentModeEnabled,
                'availability' => $availability,
            ]);
            return $this->renderTemplate('interactive-ai-assistant/forms/edit', [
                'form' => $form,
                'isNew' => false,
                'agentModeEnabled' => $settings->agentModeEnabled,
                'availability' => $availability,
                'contactFormInstalled' => Craft::$app->plugins->isPluginEnabled('contact-form'),
            ]);
        }

        Craft::$app->plugins->savePluginSettings($plugin, $settings->getAttributes());
        Craft::$app->session->setNotice('Form saved.');
        return $this->redirect('interactive-ai-assistant/forms');
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $name = (string)Craft::$app->request->getRequiredBodyParam('name');

        $settings->forms = array_values(array_filter(
            $settings->forms,
            fn($f) => !is_array($f) || (string)($f['name'] ?? '') !== $name
        ));
        $states = $settings->capabilityStates;
        unset($states[$name]);
        $settings->capabilityStates = $states;

        Craft::$app->plugins->savePluginSettings($plugin, $settings->getAttributes());
        return $this->asJson(['success' => true]);
    }

    /**
     * Turn the posted editable-table shape into a clean form definition.
     *
     * @param array<string, mixed> $posted
     * @return array<string, mixed>
     */
    private function normalizeForm(array $posted): array
    {
        $fields = [];
        foreach ((array)($posted['fields'] ?? []) as $row) {
            if (!is_array($row) || trim((string)($row['name'] ?? '')) === '') {
                continue;
            }
            $type = (string)($row['type'] ?? 'text');
            $field = [
                'name' => trim((string)$row['name']),
                'label' => trim((string)($row['label'] ?? '')),
                'type' => in_array($type, ['text', 'email', 'tel', 'number', 'textarea', 'select', 'hidden'], true) ? $type : 'text',
                'required' => !empty($row['required']),
                'description' => trim((string)($row['description'] ?? '')),
            ];
            if ($field['type'] === 'select') {
                $field['options'] = array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string)($row['options'] ?? ''))
                ), fn($v) => $v !== ''));
            }
            if ($field['type'] === 'hidden') {
                // Predefined value, never asked from the user.
                $field['value'] = (string)($row['value'] ?? '');
            }
            $fields[] = $field;
        }

        $headers = [];
        foreach ((array)($posted['delivery']['webhook']['headers'] ?? []) as $row) {
            if (!is_array($row) || trim((string)($row['key'] ?? '')) === '') {
                continue;
            }
            $headers[] = ['key' => trim((string)$row['key']), 'value' => (string)($row['value'] ?? '')];
        }

        $mode = (string)($posted['mode'] ?? 'conversational');
        return [
            'name' => trim((string)($posted['name'] ?? '')),
            'label' => trim((string)($posted['label'] ?? '')),
            'description' => trim((string)($posted['description'] ?? '')),
            'mode' => in_array($mode, ['conversational', 'inline'], true) ? $mode : 'conversational',
            'fields' => $fields,
            'delivery' => [
                'webhook' => [
                    'enabled' => !empty($posted['delivery']['webhook']['enabled']),
                    'url' => trim((string)($posted['delivery']['webhook']['url'] ?? '')),
                    'method' => strtoupper((string)($posted['delivery']['webhook']['method'] ?? 'POST')) ?: 'POST',
                    'headers' => $headers,
                ],
                'email' => [
                    'enabled' => !empty($posted['delivery']['email']['enabled']),
                    'to' => trim((string)($posted['delivery']['email']['to'] ?? '')),
                    'subject' => trim((string)($posted['delivery']['email']['subject'] ?? '')),
                ],
                'contactform' => [
                    'enabled' => !empty($posted['delivery']['contactform']['enabled']),
                    'emailField' => trim((string)($posted['delivery']['contactform']['emailField'] ?? '')),
                    'nameField' => trim((string)($posted['delivery']['contactform']['nameField'] ?? '')),
                    'subject' => trim((string)($posted['delivery']['contactform']['subject'] ?? '')),
                    'formName' => trim((string)($posted['delivery']['contactform']['formName'] ?? '')),
                ],
            ],
        ];
    }
}
