<?php

namespace cstudiossro\craftcschatbot\events;

use cstudiossro\craftcschatbot\capabilities\CapabilityInterface;
use yii\base\Event;

/**
 * Fired so plugins and modules can register their own assistant capabilities.
 *
 * Example (in a module's init()):
 *
 *   use cstudiossro\craftcschatbot\services\Capabilities;
 *   use cstudiossro\craftcschatbot\events\RegisterCapabilitiesEvent;
 *   use yii\base\Event;
 *
 *   Event::on(
 *       Capabilities::class,
 *       Capabilities::EVENT_REGISTER_CAPABILITIES,
 *       function (RegisterCapabilitiesEvent $e) {
 *           $e->capabilities[] = new \my\plugin\FindNearestShops();
 *       }
 *   );
 */
class RegisterCapabilitiesEvent extends Event
{
    /** @var CapabilityInterface[] */
    public array $capabilities = [];
}
