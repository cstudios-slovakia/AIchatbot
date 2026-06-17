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

    public function description(): string
    {
        $desc = trim((string)($this->form['description'] ?? ''));
        $label = (string)($this->form['label'] ?? $this->name());
        $base = $desc !== '' ? $desc : "Collect and submit the \"{$label}\" form.";
        // Reinforce the no-tampering rule right where the model reads the tool.
        return $base . ' Call this only once you have gathered the required fields from the user. '
            . 'Pass every value exactly as the user gave it — never rephrase, translate, correct, reformat or invent values.';
    }

    public function parameters(): array
    {
        $properties = [];
        $required = [];
        foreach ($this->form['fields'] as $field) {
            $fname = (string)($field['name'] ?? '');
            if ($fname === '') {
                continue;
            }
            $type = (string)($field['type'] ?? 'text');
            $schema = ['type' => $type === 'number' ? 'number' : 'string'];

            $describe = trim((string)($field['description'] ?? ''));
            $label = trim((string)($field['label'] ?? ''));
            $schema['description'] = ($describe !== '' ? $describe : $label)
                . ' Use the user\'s exact wording.';

            if ($type === 'select') {
                $options = is_array($field['options'] ?? null) ? array_values(array_filter(array_map('strval', $field['options']))) : [];
                if ($options) {
                    $schema['enum'] = $options;
                }
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
        return Plugin::getInstance()->forms->submit($this->name(), $args);
    }
}
