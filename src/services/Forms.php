<?php

namespace cstudiossro\craftcschatbot\services;

use Craft;
use craft\db\Query;
use craft\helpers\App;
use cstudiossro\craftcschatbot\jobs\SendFormJob;
use cstudiossro\craftcschatbot\Plugin;
use cstudiossro\craftcschatbot\records\ChatSessionRecord;
use cstudiossro\craftcschatbot\records\FormSubmissionRecord;
use yii\base\Component;

/**
 * Conversational forms: validate a tool-collected form, persist it, and deliver
 * it to the configured destinations (webhook / email). The assistant fills the
 * form through the normal tool-calling loop — see
 * {@see \cstudiossro\craftcschatbot\capabilities\ConfiguredFormCapability}.
 */
class Forms extends Component
{
    /**
     * The session the current chat turn belongs to, so submissions made during
     * the tool-calling loop can be linked back. Set by the Chat service before
     * it runs the loop; capabilities have no session of their own.
     */
    private ?ChatSessionRecord $currentSession = null;

    /** Name of a form the model asked to display this turn (inline mode). */
    private ?string $formToShow = null;

    public function setCurrentSession(?ChatSessionRecord $session): void
    {
        $this->currentSession = $session;
        $this->formToShow = null; // reset per turn
    }

    /**
     * Inline-mode handler: the model calls the tool to *display* the form rather
     * than fill it. We flag which form so the Chat service can attach its schema
     * to the reply for the widget to render.
     *
     * @return array<string, mixed>
     */
    public function requestShowForm(string $formName): array
    {
        if (!Plugin::getInstance()->getSettings()->getForm($formName)) {
            return ['ok' => false, 'error' => "Unknown form: {$formName}"];
        }
        $this->formToShow = $formName;
        return ['ok' => true, 'displayed' => true, 'message' => 'The form is now shown to the user to fill in and submit themselves.'];
    }

    /**
     * The public schema (no delivery config) of the form the model asked to
     * display this turn, if any. Clears the flag.
     *
     * @return array<string, mixed>|null
     */
    public function consumeFormToShow(): ?array
    {
        if ($this->formToShow === null) {
            return null;
        }
        $form = Plugin::getInstance()->getSettings()->getForm($this->formToShow);
        $this->formToShow = null;
        return $form ? $this->publicSchema($form) : null;
    }

    /**
     * Strip a form definition down to what the widget needs to render it — never
     * expose the delivery endpoint, headers or auth to the browser.
     *
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    private function publicSchema(array $form): array
    {
        $fields = [];
        foreach ($form['fields'] as $field) {
            // Predefined fields are sent automatically, not shown to the visitor.
            if ((string)($field['type'] ?? '') === 'hidden') {
                continue;
            }
            $fields[] = [
                'name' => (string)($field['name'] ?? ''),
                'label' => (string)($field['label'] ?? ''),
                'type' => (string)($field['type'] ?? 'text'),
                'required' => !empty($field['required']),
                'options' => is_array($field['options'] ?? null) ? array_values(array_map('strval', $field['options'])) : [],
            ];
        }
        return [
            'name' => (string)($form['name'] ?? ''),
            'label' => (string)($form['label'] ?? ''),
            'fields' => $fields,
        ];
    }

    /**
     * Validate + store a form the model just completed, then enqueue delivery.
     * Returns a JSON-encodable result for the model: success so it can confirm
     * to the user, or the list of still-missing/invalid fields so it re-asks.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function submit(string $formName, array $args): array
    {
        $form = Plugin::getInstance()->getSettings()->getForm($formName);
        if (!$form) {
            return ['ok' => false, 'error' => "Unknown form: {$formName}"];
        }

        [$values, $missing, $invalid] = $this->collect($form, $args);
        if ($missing || $invalid) {
            return [
                'ok' => false,
                'error' => 'Some required fields are missing or invalid; ask the user for them before submitting again.',
                'missing' => array_values($missing),
                'invalid' => array_values($invalid),
            ];
        }

        $rec = $this->persist($formName, $values);
        return ['ok' => true, 'message' => 'Form submitted.', 'submissionId' => (int)$rec->id];
    }

    /**
     * Submit values the visitor entered into a rendered (inline-mode) form.
     * Validates against the form definition and returns field-level errors for
     * the widget to display, or stores + enqueues delivery on success.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function submitFromWidget(string $formName, array $values, ?ChatSessionRecord $session): array
    {
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->formsEnabled) {
            return ['ok' => false, 'error' => 'Forms are disabled.'];
        }
        $form = $settings->getForm($formName);
        if (!$form) {
            return ['ok' => false, 'error' => 'Unknown form.'];
        }
        if ($settings->capabilityState($formName) === 'off') {
            return ['ok' => false, 'error' => 'This form is not available.'];
        }

        [$clean, $missing, $invalid] = $this->collect($form, $values);
        if ($missing || $invalid) {
            $errors = [];
            foreach ($missing as $f) {
                $errors[$f] = 'required';
            }
            foreach ($invalid as $f) {
                $errors[$f] = 'invalid';
            }
            return ['ok' => false, 'errors' => $errors];
        }

        $this->setCurrentSession($session);
        $rec = $this->persist($formName, $clean);
        return ['ok' => true, 'submissionId' => (int)$rec->id];
    }

    /**
     * Store a validated submission and queue its delivery.
     *
     * @param array<string, mixed> $values
     */
    private function persist(string $formName, array $values): FormSubmissionRecord
    {
        $rec = new FormSubmissionRecord();
        $rec->sessionId = $this->currentSession?->id ? (int)$this->currentSession->id : null;
        $rec->formName = $formName;
        $rec->payload = json_encode($values);
        $rec->status = FormSubmissionRecord::STATUS_PENDING;
        $rec->save(false);

        Craft::$app->queue->push(new SendFormJob(['submissionId' => (int)$rec->id]));
        return $rec;
    }

    /**
     * Map raw model arguments onto the form's declared fields, casting by type
     * and collecting any required values that are absent or malformed.
     *
     * @return array{0: array<string,mixed>, 1: string[], 2: string[]} [values, missing, invalid]
     */
    private function collect(array $form, array $args): array
    {
        $values = [];
        $missing = [];
        $invalid = [];
        foreach ($form['fields'] as $field) {
            $name = (string)($field['name'] ?? '');
            if ($name === '') {
                continue;
            }
            // Predefined field: always include its constant value, never read
            // from the (model- or user-supplied) args.
            if ((string)($field['type'] ?? '') === 'hidden') {
                $values[$name] = (string)($field['value'] ?? '');
                continue;
            }
            $required = !empty($field['required']);
            $raw = $args[$name] ?? null;
            $present = $raw !== null && $raw !== '';

            if (!$present) {
                if ($required) {
                    $missing[] = $name;
                }
                continue;
            }

            $type = (string)($field['type'] ?? 'text');
            if ($type === 'email' && !filter_var((string)$raw, FILTER_VALIDATE_EMAIL)) {
                $invalid[] = $name;
                continue;
            }
            if ($type === 'number') {
                if (!is_numeric($raw)) {
                    $invalid[] = $name;
                    continue;
                }
                $raw = $raw + 0;
            }
            if ($type === 'select') {
                $options = is_array($field['options'] ?? null) ? $field['options'] : [];
                if ($options && !in_array((string)$raw, array_map('strval', $options), true)) {
                    $invalid[] = $name;
                    continue;
                }
            }
            $values[$name] = is_string($raw) ? trim($raw) : $raw;
        }
        return [$values, $missing, $invalid];
    }

    /**
     * Deliver a stored submission to its form's configured channels. Throws on
     * any channel failure so the queue retries; records the outcome either way.
     */
    public function deliver(FormSubmissionRecord $rec): void
    {
        $form = Plugin::getInstance()->getSettings()->getForm($rec->formName);
        if (!$form) {
            $this->mark($rec, FormSubmissionRecord::STATUS_FAILED, "Form \"{$rec->formName}\" no longer exists.");
            return; // nothing to retry against
        }
        $payload = json_decode((string)$rec->payload, true) ?: [];
        $delivery = is_array($form['delivery'] ?? null) ? $form['delivery'] : [];
        $log = [];
        $failed = false;

        if (!empty($delivery['webhook']['enabled'])) {
            try {
                $this->deliverWebhook($delivery['webhook'], $rec, $payload);
                $log[] = 'webhook: ok';
            } catch (\Throwable $e) {
                $failed = true;
                $log[] = 'webhook: ' . $e->getMessage();
            }
        }
        if (!empty($delivery['email']['enabled'])) {
            try {
                $this->deliverEmail($delivery['email'], $form, $rec, $payload);
                $log[] = 'email: ok';
            } catch (\Throwable $e) {
                $failed = true;
                $log[] = 'email: ' . $e->getMessage();
            }
        }

        $this->mark(
            $rec,
            $failed ? FormSubmissionRecord::STATUS_FAILED : FormSubmissionRecord::STATUS_SENT,
            implode("\n", $log)
        );
        if ($failed) {
            throw new \RuntimeException("Form delivery failed: " . implode('; ', $log));
        }
    }

    /**
     * @param array<string, mixed> $cfg
     * @param array<string, mixed> $payload
     */
    private function deliverWebhook(array $cfg, FormSubmissionRecord $rec, array $payload): void
    {
        $url = trim((string)($cfg['url'] ?? ''));
        if ($url === '') {
            throw new \RuntimeException('webhook URL is empty');
        }
        $method = strtoupper((string)($cfg['method'] ?? 'POST')) ?: 'POST';
        $headers = ['Content-Type' => 'application/json'];
        foreach (($cfg['headers'] ?? []) as $h) {
            $key = trim((string)($h['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            // Allow $ENV_VAR references in header values (e.g. an API token).
            $headers[$key] = App::parseEnv((string)($h['value'] ?? ''));
        }
        $body = json_encode([
            'form' => $rec->formName,
            'submissionId' => (int)$rec->id,
            'sessionId' => $rec->sessionId !== null ? (int)$rec->sessionId : null,
            'submittedAt' => (string)$rec->dateCreated,
            'data' => $payload,
        ]);

        $client = Craft::createGuzzleClient(['timeout' => 15]);
        $response = $client->request($method, $url, ['headers' => $headers, 'body' => $body]);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("endpoint returned HTTP {$status}");
        }
    }

    /**
     * @param array<string, mixed> $cfg
     * @param array<string, mixed> $form
     * @param array<string, mixed> $payload
     */
    private function deliverEmail(array $cfg, array $form, FormSubmissionRecord $rec, array $payload): void
    {
        $to = trim((string)($cfg['to'] ?? ''));
        if ($to === '') {
            throw new \RuntimeException('recipient is empty');
        }
        $label = (string)($form['label'] ?? $rec->formName);
        $subject = trim((string)($cfg['subject'] ?? '')) ?: "New {$label} submission";

        $labels = [];
        foreach ($form['fields'] as $field) {
            $labels[(string)($field['name'] ?? '')] = (string)($field['label'] ?? $field['name'] ?? '');
        }
        $lines = ["New \"{$label}\" submission from the chatbot:", ''];
        foreach ($payload as $key => $value) {
            $lines[] = ($labels[$key] ?? $key) . ': ' . (is_scalar($value) ? (string)$value : json_encode($value));
        }
        $lines[] = '';
        $lines[] = 'Submission #' . (int)$rec->id . ($rec->sessionId ? ' · session #' . (int)$rec->sessionId : '');

        $message = Craft::$app->mailer->compose()
            ->setTo(array_map('trim', explode(',', $to)))
            ->setSubject($subject)
            ->setTextBody(implode("\n", $lines));
        if (!$message->send()) {
            throw new \RuntimeException('mailer refused the message');
        }
    }

    private function mark(FormSubmissionRecord $rec, string $status, string $log): void
    {
        $rec->status = $status;
        $rec->deliveryLog = $log !== '' ? $log : null;
        $rec->save(false);
    }

    public function pendingOrFailedCount(): int
    {
        return (int)(new Query())
            ->from('{{%chatbot_form_submissions}}')
            ->where(['status' => [FormSubmissionRecord::STATUS_PENDING, FormSubmissionRecord::STATUS_FAILED]])
            ->count();
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function listForAdmin(string $formName = '', string $status = '', int $page = 1, int $perPage = 25): array
    {
        $query = (new Query())->from(['f' => '{{%chatbot_form_submissions}}']);
        if ($formName !== '') {
            $query->andWhere(['f.formName' => $formName]);
        }
        if (in_array($status, [
            FormSubmissionRecord::STATUS_PENDING,
            FormSubmissionRecord::STATUS_SENT,
            FormSubmissionRecord::STATUS_FAILED,
        ], true)) {
            $query->andWhere(['f.status' => $status]);
        }
        $total = (int)(clone $query)->count();

        $rows = $query
            ->select(['f.id', 'f.sessionId', 'f.formName', 'f.payload', 'f.status', 'f.deliveryLog', 'f.dateCreated'])
            ->orderBy(['f.id' => SORT_DESC])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        return ['rows' => $rows, 'total' => $total];
    }

    public function retry(int $id): bool
    {
        $rec = FormSubmissionRecord::findOne($id);
        if (!$rec) {
            return false;
        }
        $rec->status = FormSubmissionRecord::STATUS_PENDING;
        $rec->save(false);
        Craft::$app->queue->push(new SendFormJob(['submissionId' => (int)$rec->id]));
        return true;
    }

    public function delete(int $id): bool
    {
        $rec = FormSubmissionRecord::findOne($id);
        if (!$rec) {
            return false;
        }
        $rec->delete();
        return true;
    }
}
