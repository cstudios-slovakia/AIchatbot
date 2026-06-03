<?php

namespace cstudiossro\craftcschatbot\capabilities;

/**
 * A capability ("skill") the assistant can invoke during a conversation.
 *
 * Capabilities are the extension point of the agent: register one (see
 * {@see \cstudiossro\craftcschatbot\services\Capabilities}) and, with agent
 * mode enabled, the model can call it as an OpenAI tool. Plugins and Craft
 * modules add their own by listening to
 * {@see \cstudiossro\craftcschatbot\services\Capabilities::EVENT_REGISTER_CAPABILITIES}.
 */
interface CapabilityInterface
{
    /**
     * Unique tool name. Must match ^[a-zA-Z0-9_-]{1,64}$ (OpenAI constraint).
     */
    public function name(): string;

    /**
     * Plain-language description of what the capability does and when to use it.
     * Shown to the model, so be specific.
     */
    public function description(): string;

    /**
     * JSON-Schema object describing the arguments, e.g.
     * ['type' => 'object', 'properties' => [...], 'required' => [...]].
     * Return an empty array for a no-argument capability.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * Execute the capability and return a JSON-encodable result that will be
     * fed back to the model. Throwing is allowed — the registry converts
     * exceptions into a structured error for the model.
     *
     * @param array<string, mixed> $args
     * @return mixed
     */
    public function handle(array $args): mixed;
}
