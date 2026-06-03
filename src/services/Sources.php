<?php

namespace cstudiossro\craftcschatbot\services;

use Craft;
use cstudiossro\craftcschatbot\events\RegisterTrainingSourcesEvent;
use cstudiossro\craftcschatbot\training\TrainingSourceInterface;
use yii\base\Component;

/**
 * Registry of custom training sources.
 *
 * On first use it fires {@see self::EVENT_REGISTER_SOURCES} so plugins and
 * modules can contribute sources that expose their own content (e.g. a custom
 * element type) to the assistant's training pipeline.
 */
class Sources extends Component
{
    /**
     * @event RegisterTrainingSourcesEvent Register custom training sources.
     */
    public const EVENT_REGISTER_SOURCES = 'registerSources';

    /** Source handles reserved by the built-in training kinds. */
    private const RESERVED = ['entry', 'file', 'url', 'qa', 'category', 'global'];

    /** @var array<string, TrainingSourceInterface> handle => source */
    private array $sources = [];

    public function init(): void
    {
        parent::init();
        $event = new RegisterTrainingSourcesEvent();
        $this->trigger(self::EVENT_REGISTER_SOURCES, $event);
        foreach ($event->sources as $source) {
            if ($source instanceof TrainingSourceInterface) {
                $this->register($source);
            }
        }
    }

    /**
     * Register a source. Later registrations override an earlier one with the
     * same handle.
     */
    public function register(TrainingSourceInterface $source): void
    {
        $handle = $source->handle();
        // Handle is stored in chunks.sourceType (a 20-char column), so cap it there.
        if (!preg_match('/^[a-z0-9_-]{1,20}$/', $handle)) {
            Craft::warning("Ignoring training source with invalid handle: {$handle}", __METHOD__);
            return;
        }
        if (in_array($handle, self::RESERVED, true)) {
            Craft::warning("Ignoring training source with reserved handle: {$handle}", __METHOD__);
            return;
        }
        $this->sources[$handle] = $source;
    }

    public function has(string $handle): bool
    {
        return isset($this->sources[$handle]);
    }

    public function get(string $handle): ?TrainingSourceInterface
    {
        return $this->sources[$handle] ?? null;
    }

    /**
     * @return TrainingSourceInterface[]
     */
    public function all(): array
    {
        return array_values($this->sources);
    }

    public function isEmpty(): bool
    {
        return $this->sources === [];
    }
}
