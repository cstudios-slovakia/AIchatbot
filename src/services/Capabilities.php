<?php

namespace cstudiossro\craftcschatbot\services;

use Craft;
use cstudiossro\craftcschatbot\capabilities\CapabilityInterface;
use cstudiossro\craftcschatbot\events\RegisterCapabilitiesEvent;
use yii\base\Component;

/**
 * Registry of assistant capabilities ("skills").
 *
 * On first use it fires {@see self::EVENT_REGISTER_CAPABILITIES} so plugins and
 * modules can contribute capabilities. The Chat service turns the registered
 * set into OpenAI tools and runs them during the tool-calling loop.
 */
class Capabilities extends Component
{
    /**
     * @event RegisterCapabilitiesEvent Register assistant capabilities.
     */
    public const EVENT_REGISTER_CAPABILITIES = 'registerCapabilities';

    /** @var array<string, CapabilityInterface> name => capability */
    private array $capabilities = [];

    public function init(): void
    {
        parent::init();
        $event = new RegisterCapabilitiesEvent();
        $this->trigger(self::EVENT_REGISTER_CAPABILITIES, $event);
        foreach ($event->capabilities as $capability) {
            if ($capability instanceof CapabilityInterface) {
                $this->register($capability);
            }
        }
    }

    /**
     * Register a capability. Later registrations override an earlier one with
     * the same name.
     */
    public function register(CapabilityInterface $capability): void
    {
        $name = $capability->name();
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $name)) {
            Craft::warning("Ignoring capability with invalid name: {$name}", __METHOD__);
            return;
        }
        $this->capabilities[$name] = $capability;
    }

    public function has(string $name): bool
    {
        return isset($this->capabilities[$name]);
    }

    public function get(string $name): ?CapabilityInterface
    {
        return $this->capabilities[$name] ?? null;
    }

    /**
     * @return CapabilityInterface[]
     */
    public function all(): array
    {
        return array_values($this->capabilities);
    }

    public function isEmpty(): bool
    {
        return $this->capabilities === [];
    }

    /**
     * Capabilities available in the current context, per their configured state
     * ('on' = everyone, 'admins' = only when $isAdmin, 'off' = never).
     *
     * @return CapabilityInterface[]
     */
    public function enabledFor(bool $isAdmin): array
    {
        $settings = \cstudiossro\craftcschatbot\Plugin::getInstance()->getSettings();
        $out = [];
        foreach ($this->capabilities as $capability) {
            $state = $settings->capabilityState($capability->name());
            if ($state === 'on' || ($state === 'admins' && $isAdmin)) {
                $out[] = $capability;
            }
        }
        return $out;
    }

    /**
     * Capabilities as an OpenAI `tools` array. Pass a subset (e.g. from
     * {@see self::enabledFor()}); defaults to all registered capabilities.
     *
     * @param CapabilityInterface[]|null $capabilities
     * @return array<int, array<string, mixed>>
     */
    public function toolSchemas(?array $capabilities = null): array
    {
        $capabilities ??= array_values($this->capabilities);
        $tools = [];
        foreach ($capabilities as $capability) {
            $params = $capability->parameters();
            if ($params === []) {
                $params = ['type' => 'object', 'properties' => new \stdClass()];
            }
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $capability->name(),
                    'description' => $capability->description(),
                    'parameters' => $params,
                ],
            ];
        }
        return $tools;
    }

    /**
     * Run a capability by name. Always returns a JSON-encodable array so the
     * result (or error) can be handed straight back to the model.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function run(string $name, array $args): array
    {
        $capability = $this->get($name);
        if (!$capability) {
            return ['ok' => false, 'error' => "Unknown capability: {$name}"];
        }
        try {
            return ['ok' => true, 'result' => $capability->handle($args)];
        } catch (\Throwable $e) {
            Craft::error("Capability {$name} failed: " . $e->getMessage(), __METHOD__);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
