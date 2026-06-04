<?php

namespace cstudiossro\craftcschatbot\events;

use cstudiossro\craftcschatbot\records\ChatSessionRecord;
use yii\base\Event;

/**
 * Fired while assembling the assistant's system prompt, so plugins and modules
 * can append their own context. Core stays generic — site-specific additions
 * (e.g. the current date for an events site) live in the listener.
 *
 * Each string pushed onto {@see self::$additions} is appended to the system
 * prompt as its own block. Use a leading "# Heading" to keep sections clear.
 *
 * Example (in a module's init()):
 *
 *   use cstudiossro\craftcschatbot\services\Chat;
 *   use cstudiossro\craftcschatbot\events\BuildSystemPromptEvent;
 *   use yii\base\Event;
 *
 *   Event::on(
 *       Chat::class,
 *       Chat::EVENT_BUILD_SYSTEM_PROMPT,
 *       function (BuildSystemPromptEvent $e) {
 *           $e->additions[] = "# Current date\nToday is " . date('Y-m-d') . '.';
 *       }
 *   );
 */
class BuildSystemPromptEvent extends Event
{
    /**
     * Site the conversation belongs to (resolved from the page URL), if known.
     */
    public ?string $siteUid = null;

    /**
     * The user's current message — lets listeners add context conditionally.
     */
    public string $question = '';

    /**
     * The active chat session.
     */
    public ?ChatSessionRecord $session = null;

    /**
     * Prompt blocks to append, in order. Each becomes its own paragraph.
     *
     * @var string[]
     */
    public array $additions = [];
}
