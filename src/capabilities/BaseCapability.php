<?php

namespace cstudiossro\craftcschatbot\capabilities;

/**
 * Convenience base for capabilities. Subclasses must implement name(),
 * description() and handle(); parameters() defaults to "no arguments".
 */
abstract class BaseCapability implements CapabilityInterface
{
    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }
}
