<?php

namespace cstudiossro\craftcschatbot\events;

use cstudiossro\craftcschatbot\records\ChatSessionRecord;
use yii\base\Event;

/**
 * Fired after the model produces a reply but before it is logged and returned,
 * so plugins and modules can rewrite the text. Core stays generic — any
 * site-specific post-processing (e.g. resolving event-link tokens to real
 * URLs) lives in the listener.
 *
 * Listeners mutate {@see self::$reply} in place.
 *
 * Example (in a module's init()):
 *
 *   use cstudiossro\craftcschatbot\services\Chat;
 *   use cstudiossro\craftcschatbot\events\TransformReplyEvent;
 *   use yii\base\Event;
 *
 *   Event::on(
 *       Chat::class,
 *       Chat::EVENT_TRANSFORM_REPLY,
 *       function (TransformReplyEvent $e) {
 *           $e->reply = str_replace('foo', 'bar', $e->reply);
 *       }
 *   );
 */
class TransformReplyEvent extends Event
{
    /**
     * The reply text, mutable. Listeners read and overwrite this.
     */
    public string $reply = '';

    /**
     * The user's message that produced this reply.
     */
    public string $question = '';

    /**
     * Site the conversation belongs to, if known.
     */
    public ?string $siteUid = null;

    /**
     * The active chat session.
     */
    public ?ChatSessionRecord $session = null;
}
