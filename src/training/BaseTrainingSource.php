<?php

namespace cstudiossro\craftcschatbot\training;

/**
 * Convenience base for training sources. Subclasses must implement handle(),
 * label(), items() and extractText(); elementType() defaults to null (no
 * auto-training).
 */
abstract class BaseTrainingSource implements TrainingSourceInterface
{
    public function elementType(): ?string
    {
        return null;
    }
}
