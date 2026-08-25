<?php

namespace cstudiossro\craftcschatbot\capabilities;

use cstudiossro\craftcschatbot\Plugin;

/**
 * A capability synthesized from an admin-defined form (see Settings::$forms).
 * Its parameters are the form's fields, so the model collects them through the
 * normal tool-calling loop and calls this tool once they're gathered; the
 * actual validation, storage and delivery live in the Forms service.
 */
class ConfiguredFormCapability extends BaseCapability
{
    /** @param array<string, mixed> $form A single entry from Settings::formDefinitions() */
    public function __construct(private array $form)
    {
    }

    public function name(): string
    {
        return (string)($this->form['name'] ?? '');
    }

    public function isInline(): bool
    {
        return ($this->form['mode'] ?? 'conversational') === 'inline';
    }

    public function description(): string
    {
        $desc = trim((string)($this->form['description'] ?? ''));
        $label = (string)($this->form['label'] ?? $this->name());
        $base = $desc !== '' ? $desc : "The \"{$label}\" form.";
        if ($this->isInline()) {
            // Inline mode: the user fills the rendered form, not the model.
            return $base . ' Call this to display the form so the user can fill it in and submit it themselves. '
                . 'Do not ask for or collect the field values yourself — just call the tool when the form is relevant, then briefly tell the user to complete the form shown.';
        }
        // Conversational mode: model gathers the values and submits.
        return $base . ' Call this only once you have gathered the required fields from the user. '
            . 'Pass every value exactly as the user gave it — never rephrase, translate, correct, reformat or invent values.';
    }

    public function parameters(): array
    {
        // Inline forms take no arguments — the visitor enters the values.
        if ($this->isInline()) {
            return ['type' => 'object', 'properties' => new \stdClass()];
        }
        $properties = [];
        $required = [];
        foreach ($this->form['fields'] as $field) {
            $fname = (string)($field['name'] ?? '');
            if ($fname === '') {
                continue;
            }
            $type = (string)($field['type'] ?? 'text');
            // Predefined fields carry a constant value and are never asked of the
            // user, so they're not part of the model-facing schema.
            if ($type === 'hidden') {
                continue;
            }
            // Options may be a typed list or pulled from a section / Craft field —
            // the Forms service is the one place that resolves which.
            $options = array_column(Plugin::getInstance()->forms->fieldOptions($field), 'value');
            $schema = match ($type) {
                'number' => ['type' => 'number'],
                // Checkbox group: any number of the offered options.
                'checkboxes' => ['type' => 'array', 'items' => ['type' => 'string'] + ($options ? ['enum' => $options] : [])],
                // Consent: a yes/no the user has to actually give.
                'consent' => ['type' => 'boolean'],
                default => ['type' => 'string'],
            };

            $describe = trim((string)($field['description'] ?? ''));
            // A consent label carries its link as `[text](url)` for the widget
            // to render; the model only needs the words.
            $label = preg_replace('/\[([^\]]+)\]\([^)\s]*\)/', '$1', trim((string)($field['label'] ?? '')));
            $schema['description'] = ($describe !== '' ? $describe : $label)
                . match ($type) {
                    'checkboxes' => ' Pass only the options the user actually chose; leave the list empty if they chose none.',
                    'consent' => ' Only true if the user explicitly agreed after you told them what they are agreeing to — never assume it.',
                    default => ' Use the user\'s exact wording.',
                };

            if ($type === 'select' && $options) {
                $schema['enum'] = $options;
            }
            $properties[$fname] = $schema;
            if (!empty($field['required'])) {
                $required[] = $fname;
            }
        }

        $params = ['type' => 'object', 'properties' => $properties ?: new \stdClass()];
        if ($required) {
            $params['required'] = $required;
        }
        return $params;
    }

    public function handle(array $args): mixed
    {
        $forms = Plugin::getInstance()->forms;
        return $this->isInline()
            ? $forms->requestShowForm($this->name())
            : $forms->submit($this->name(), $args);
    }
}
