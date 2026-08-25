<?php

namespace cstudiossro\craftcschatbot\services;

use Craft;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\Db;
use craft\elements\Entry;
use craft\fields\BaseOptionsField;
use craft\models\Site;
use cstudiossro\craftcschatbot\helpers\CraftCompat;
use cstudiossro\craftcschatbot\jobs\SendFormJob;
use cstudiossro\craftcschatbot\models\Settings;
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
     * Hard cap on how many choices a dynamic source may contribute. Every option
     * ends up in the tool schema the model is sent, so an unbounded section would
     * quietly eat the context window.
     */
    private const MAX_DYNAMIC_OPTIONS = 200;

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
        $language = Settings::resolveSiteFromUrl($this->currentSession?->pageUrl)?->language;
        $fields = [];
        foreach ($form['fields'] as $field) {
            // Predefined fields are sent automatically, not shown to the visitor.
            if ((string)($field['type'] ?? '') === 'hidden') {
                continue;
            }
            $choices = $this->fieldOptions($field);
            $options = array_column($choices, 'value');
            $fields[] = [
                'name' => (string)($field['name'] ?? ''),
                'label' => $this->tSite((string)($field['label'] ?? ''), $language),
                'type' => (string)($field['type'] ?? 'text'),
                'required' => !empty($field['required']),
                // Values stay as configured — they are what gets stored and
                // delivered. Only the text beside them is translated.
                'options' => $options,
                'optionLabels' => array_map(fn(array $c) => $this->tSite($c['label'], $language), $choices),
            ];
        }
        return [
            'name' => (string)($form['name'] ?? ''),
            'label' => $this->tSite((string)($form['label'] ?? ''), $language),
            'fields' => $fields,
        ];
    }

    /**
     * Translate an admin-entered label for a visitor. These strings are typed in
     * the CP, so they can't live in the plugin's own translation files; they go
     * through Craft's `site` category, the same place Craft puts field and
     * section labels. A project translates them in `translations/<lang>/site.php`,
     * and an untranslated string passes through unchanged.
     */
    private function tSite(string $text, ?string $language): string
    {
        $text = trim($text);
        return $text === '' ? '' : Craft::t('site', $text, [], $language);
    }

    /**
     * The choices a select/checkboxes field offers, resolved from whatever it
     * draws them from: the typed list, the entries of a section, or a Craft
     * dropdown-style field's own options.
     *
     * Everything downstream — the tool schema the model sees, the inline form the
     * widget renders and the validation of what comes back — goes through here,
     * so the three can never disagree about what a valid answer is.
     *
     * Dynamic sources are read per site (entry titles are site-specific: a Slovak
     * visitor must not be offered English titles) and cached briefly, because a
     * form is resolved several times per chat turn.
     *
     * @param array<string, mixed> $field
     * @return array<int, array{value: string, label: string}>
     */
    public function fieldOptions(array $field, ?Site $site = null): array
    {
        switch ((string)($field['optionsSource'] ?? 'manual')) {
            case 'section':
                return $this->sectionOptions(trim((string)($field['optionsSection'] ?? '')), $site ?? $this->optionsSite());
            case 'field':
                return $this->craftFieldOptions(trim((string)($field['optionsField'] ?? '')));
            default:
                $typed = is_array($field['options'] ?? null) ? $field['options'] : [];
                $out = [];
                foreach ($typed as $one) {
                    $one = trim(is_scalar($one) ? (string)$one : '');
                    if ($one !== '') {
                        $out[] = ['value' => $one, 'label' => $one];
                    }
                }
                return $out;
        }
    }

    /**
     * Live entries of a section, as value+label pairs. The title is both: it is
     * what gets stored and delivered, so a submission stays readable in an email
     * long after the entry it named was renamed or deleted.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function sectionOptions(string $sectionUid, ?Site $site): array
    {
        if ($sectionUid === '') {
            return [];
        }
        $section = CraftCompat::getSectionByUid($sectionUid);
        if (!$section) {
            return [];
        }
        $siteId = $site?->id;
        $key = "interactive-ai-assistant:formOptions:section:{$sectionUid}:" . ($siteId ?? 0);
        $titles = Craft::$app->cache->getOrSet($key, function () use ($section, $siteId) {
            $query = Entry::find()
                ->sectionId($section->id)
                ->limit(self::MAX_DYNAMIC_OPTIONS);
            // A structure is ordered by the editor on purpose; anything else has
            // no meaningful order of its own, so offer it alphabetically.
            if ($section->type !== 'structure') {
                $query->orderBy(['title' => SORT_ASC]);
            }
            if ($siteId !== null) {
                $query->siteId($siteId);
            }
            $titles = [];
            foreach ($query->all() as $entry) {
                $title = trim((string)$entry->title);
                if ($title !== '' && !in_array($title, $titles, true)) {
                    $titles[] = $title;
                }
            }
            return $titles;
        }, 300);

        $out = [];
        foreach ((array)$titles as $title) {
            if (is_string($title) && $title !== '') {
                $out[] = ['value' => $title, 'label' => $title];
            }
        }
        return $out;
    }

    /**
     * The options configured on a Craft dropdown / radio / checkboxes / multi-select
     * field. Values stay canonical, labels are what the visitor reads — the same
     * split the CP uses, so a form and an entry record the same value.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function craftFieldOptions(string $handle): array
    {
        if ($handle === '') {
            return [];
        }
        $field = Craft::$app->fields->getFieldByHandle($handle);
        if (!$field instanceof BaseOptionsField) {
            return [];
        }
        $out = [];
        foreach ($field->options as $option) {
            // Optgroup rows carry no value and are layout, not a choice.
            if (!is_array($option) || !isset($option['value'])) {
                continue;
            }
            $value = trim((string)$option['value']);
            if ($value === '') {
                continue;
            }
            $label = trim((string)($option['label'] ?? ''));
            $out[] = ['value' => $value, 'label' => $label !== '' ? $label : $value];
            if (count($out) >= self::MAX_DYNAMIC_OPTIONS) {
                break;
            }
        }
        return $out;
    }

    /**
     * Which site's content the options should come from: the site the visitor is
     * on when there is a session, else whatever site this request resolved to.
     */
    private function optionsSite(): ?Site
    {
        $site = Settings::resolveSiteFromUrl($this->currentSession?->pageUrl);
        if ($site) {
            return $site;
        }
        try {
            return Craft::$app->sites->getCurrentSite();
        } catch (\Throwable $e) {
            return null;
        }
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
            $type = (string)($field['type'] ?? 'text');

            // Only a checkbox group takes a list; a list anywhere else is a
            // malformed post, not an answer.
            if (is_array($raw) && $type !== 'checkboxes') {
                $invalid[] = $name;
                continue;
            }

            // Checkbox group: a list of chosen options. The widget posts an
            // array, the model may hand over one comma-separated string.
            if ($type === 'checkboxes') {
                $options = array_column($this->fieldOptions($field), 'value');
                $chosen = [];
                $unknown = false;
                foreach (is_array($raw) ? $raw : explode(',', (string)$raw) as $one) {
                    $one = trim(is_scalar($one) ? (string)$one : '');
                    if ($one === '' || in_array($one, $chosen, true)) {
                        continue;
                    }
                    if ($options && !in_array($one, $options, true)) {
                        $unknown = true;
                        continue;
                    }
                    $chosen[] = $one;
                }
                if ($unknown) {
                    $invalid[] = $name;
                } elseif (!$chosen) {
                    if ($required) {
                        $missing[] = $name;
                    }
                } else {
                    $values[$name] = $chosen;
                }
                continue;
            }

            // Consent: a single box the visitor ticks (or an explicit yes from
            // the model). Required means it has to actually be given.
            if ($type === 'consent') {
                $granted = is_bool($raw)
                    ? $raw
                    : in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
                if ($required && !$granted) {
                    $missing[] = $name;
                    continue;
                }
                $values[$name] = $granted;
                continue;
            }

            $present = $raw !== null && $raw !== '';
            if (!$present) {
                if ($required) {
                    $missing[] = $name;
                }
                continue;
            }

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
                $options = array_column($this->fieldOptions($field), 'value');
                if ($options && !in_array((string)$raw, $options, true)) {
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
        if (!empty($delivery['contactform']['enabled'])) {
            try {
                $this->deliverContactForm($delivery['contactform'], $form, $rec, $payload);
                $log[] = 'contact-form: ok';
            } catch (\Throwable $e) {
                $failed = true;
                $log[] = 'contact-form: ' . $e->getMessage();
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
            $lines[] = ($labels[$key] ?? $key) . ': ' . $this->formatValue($value);
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

    /**
     * Hand the submission to the Contact Form plugin's mailer in-process. This
     * fires its EVENT_BEFORE_SEND / EVENT_AFTER_SEND, so any CRM integration
     * hooked onto contact-form runs — the same path as its `contact-form/send`
     * action, minus the HTTP layer (no CSRF needed since it's server-side).
     *
     * @param array<string, mixed> $cfg
     * @param array<string, mixed> $form
     * @param array<string, mixed> $payload
     */
    private function deliverContactForm(array $cfg, array $form, FormSubmissionRecord $rec, array $payload): void
    {
        if (
            !Craft::$app->plugins->isPluginEnabled('contact-form')
            || !class_exists(\craft\contactform\models\Submission::class)
        ) {
            throw new \RuntimeException('the contact-form plugin is not installed/enabled');
        }

        $emailField = (string)($cfg['emailField'] ?? '');
        $nameField = (string)($cfg['nameField'] ?? '');

        $fromEmail = $emailField !== '' ? (string)($payload[$emailField] ?? '') : '';
        if ($fromEmail === '') {
            // Fall back to the first value that looks like an email.
            foreach ($payload as $v) {
                if (is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL)) {
                    $fromEmail = $v;
                    break;
                }
            }
        }
        $fromName = $nameField !== '' ? (string)($payload[$nameField] ?? '') : null;

        // Remaining fields become the message body (label => value), which is
        // what contact-form / the CRM integration reads.
        $labels = [];
        foreach ($form['fields'] as $field) {
            $labels[(string)($field['name'] ?? '')] = (string)($field['label'] ?? $field['name'] ?? '');
        }
        $message = [];
        // Identify the originating form for the CRM via message[formName].
        $message['formName'] = trim((string)($cfg['formName'] ?? '')) ?: (string)($form['label'] ?? $rec->formName);
        foreach ($payload as $key => $value) {
            if ($key === $emailField || $key === $nameField) {
                continue;
            }
            $message[$labels[$key] ?? $key] = $this->formatValue($value);
        }

        $submission = new \craft\contactform\models\Submission();
        $submission->fromEmail = $fromEmail;
        $submission->fromName = $fromName;
        $submission->subject = trim((string)($cfg['subject'] ?? '')) ?: (string)($form['label'] ?? $rec->formName);
        $submission->message = $message;

        if (!\craft\contactform\Plugin::getInstance()->getMailer()->send($submission)) {
            $errs = $submission->getFirstErrors();
            throw new \RuntimeException('contact-form rejected the submission' . ($errs ? ': ' . implode('; ', $errs) : ''));
        }
    }

    /**
     * Render one stored answer for the human-readable channels (email, Contact
     * Form): checkbox groups come back as a list, consent as a boolean. The
     * webhook keeps the raw JSON types instead.
     */
    private function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            return implode(', ', array_map(fn($v) => $this->formatValue($v), $value));
        }
        return is_scalar($value) ? (string)$value : (string)json_encode($value);
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
     * Submissions no admin has looked at yet. Delivery status says nothing about
     * this: a webhook can succeed and the lead still never be followed up.
     */
    public function unreadCount(): int
    {
        return (int)(new Query())
            ->from('{{%chatbot_form_submissions}}')
            ->where(['readAt' => null])
            ->count();
    }

    /**
     * Mark every submission seen. Called when an admin opens the list — the same
     * bargain the live-chat screen makes: opening it is what clears the badge.
     */
    public function markAllRead(): int
    {
        return (int)Craft::$app->db->createCommand()
            ->update('{{%chatbot_form_submissions}}', ['readAt' => Db::prepareDateForDb(new \DateTime())], ['readAt' => null])
            ->execute();
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
            ->select(['f.id', 'f.sessionId', 'f.formName', 'f.payload', 'f.status', 'f.deliveryLog', 'f.readAt', 'f.dateCreated'])
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
