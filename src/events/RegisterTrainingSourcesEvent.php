<?php

namespace cstudiossro\craftcschatbot\events;

use cstudiossro\craftcschatbot\training\TrainingSourceInterface;
use yii\base\Event;

/**
 * Fired so plugins and modules can register their own training sources — for
 * example to train the assistant on a custom element type from another plugin.
 *
 * Example (in a module's init()):
 *
 *   use cstudiossro\craftcschatbot\services\Sources;
 *   use cstudiossro\craftcschatbot\events\RegisterTrainingSourcesEvent;
 *   use yii\base\Event;
 *
 *   Event::on(
 *       Sources::class,
 *       Sources::EVENT_REGISTER_SOURCES,
 *       function (RegisterTrainingSourcesEvent $e) {
 *           $e->sources[] = new \my\plugin\CalendarEventSource();
 *       }
 *   );
 */
class RegisterTrainingSourcesEvent extends Event
{
    /** @var TrainingSourceInterface[] */
    public array $sources = [];
}
