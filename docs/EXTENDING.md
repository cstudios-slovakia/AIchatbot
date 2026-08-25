# Extending the Interactive AI Assistant

Everything here is done from **another plugin or a Craft module** — you never
edit this plugin to add site-specific behaviour. The plugin core stays generic;
what your site needs lives in your listener.

Four extension points:

| Want to | Use |
|---|---|
| Let the assistant fetch live data or perform an action | [Capabilities (skills)](#1-capabilities-skills) |
| Train the assistant on content this plugin doesn't know about | [Training sources](#2-training-sources) |
| Add context to the system prompt | [`EVENT_BUILD_SYSTEM_PROMPT`](#3-adding-to-the-system-prompt) |
| Rewrite the reply before the visitor sees it | [`EVENT_TRANSFORM_REPLY`](#4-rewriting-the-reply) |

> **Register in your module's `init()`.** The `Capabilities` and `Sources`
> registries fire their events from their own `init()`, i.e. the first time
> anything touches them — and this plugin touches `sources` during
> `Craft::$app->onInit`. A listener attached later is simply never called.

Skeleton for everything below:

```php
namespace modules\sitemodule;

use yii\base\Module as BaseModule;
use yii\base\Event;

class Module extends BaseModule
{
    public function init(): void
    {
        parent::init();
        // Event::on(...) — see below
    }
}
```

---

## 1. Capabilities (skills)

A capability is a tool the model can call mid-conversation, with agent mode on
(**Settings → AI Configuration → Agent mode**). The model decides when to call
it, gets the result back, and may chain several calls before answering — bounded
by **Max tool iterations**.

Implement `CapabilityInterface`, or extend `BaseCapability` when the tool takes
no arguments.

```php
use cstudiossro\craftcschatbot\capabilities\BaseCapability;

class FindNearestShops extends BaseCapability
{
    public function name(): string
    {
        return 'find_nearest_shops';
    }

    public function description(): string
    {
        return 'Find the shops closest to a city or address the visitor names. '
            . 'Use when they ask where to buy, which branch is nearest, or for opening hours near them.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'location' => ['type' => 'string', 'description' => 'City or address, as the visitor wrote it'],
                'limit' => ['type' => 'integer', 'description' => 'How many to return; default 3'],
            ],
            'required' => ['location'],
        ];
    }

    public function handle(array $args): mixed
    {
        $shops = MyShopService::nearest((string)$args['location'], (int)($args['limit'] ?? 3));

        return ['shops' => array_map(fn($s) => [
            'name' => $s->name,
            'address' => $s->address,
            'openingHours' => $s->hours,
            'url' => $s->getUrl(),
        ], $shops)];
    }
}
```

Register it:

```php
use cstudiossro\craftcschatbot\services\Capabilities;
use cstudiossro\craftcschatbot\events\RegisterCapabilitiesEvent;
use yii\base\Event;

Event::on(
    Capabilities::class,
    Capabilities::EVENT_REGISTER_CAPABILITIES,
    function (RegisterCapabilitiesEvent $e) {
        $e->capabilities[] = new FindNearestShops();
    },
);
```

Then switch it on in **Settings → AI Configuration → Skills**. Every skill has
three states:

- **Off** — never callable. This is the default for a newly registered skill;
  registering it is not enough to make it live.
- **Enabled (everyone)**
- **Admins only (testing)** — offered only to logged-in CP users, so you can try
  it on the production site before visitors can reach it.

### Rules

- **`name()` must match `^[a-zA-Z0-9_-]{1,64}$`** (OpenAI's constraint). An
  invalid name is logged as a warning and the capability is silently dropped.
- **`description()` is a prompt.** It is the only thing telling the model when to
  call this. Say what it does *and* when to use it. Vague descriptions produce
  tools that are never called, or called constantly.
- **`parameters()` is JSON Schema.** Describe every property — those
  descriptions are read by the model too. Return `[]` (or inherit
  `BaseCapability`) for no arguments.
- **`handle()` returns anything JSON-encodable.** It is `json_encode`d into a
  `tool` message and handed back to the model verbatim.
- **Throwing is fine.** `Capabilities::run()` catches `Throwable` and returns
  `['ok' => false, 'error' => $e->getMessage()]` to the model, which usually
  recovers by telling the visitor it couldn't look that up. The message reaches
  the model — don't put anything sensitive in it.
- **Availability is re-checked at call time**, not just when the schemas are
  built. A model can name a tool it was never offered.
- **Registering the same name twice**: last registration wins.

### Return small, flat, labelled data

The result goes into the context window and the model has to read it. Return the
handful of fields an answer needs, with keys that read like English. Don't return
an ORM object, a full element, or a hundred rows.

### Conversational forms are capabilities too

An admin-defined form (**Forms** in the CP) is turned into a capability at
runtime by `ConfiguredFormCapability` — its `parameters()` are the form's fields.
So forms flow through the same tool loop and the same per-skill availability UI.
If you just need "collect these fields and email/webhook them somewhere", build a
form in the CP instead of writing a capability.

---

## 2. Training sources

A training source exposes a body of content this plugin doesn't know about —
typically a custom element type from another plugin (Solspace Calendar events,
Commerce products) — to the training pipeline. It then appears under
**Training → Plugins**, gets a **Train all** button, and its text is embedded and
retrieved like any other trained content.

Implement `TrainingSourceInterface`, or extend `BaseTrainingSource` when you
don't want auto-training.

```php
use cstudiossro\craftcschatbot\training\BaseTrainingSource;
use cstudiossro\craftcschatbot\Plugin;
use Solspace\Calendar\Elements\Event as CalendarEvent;

class CalendarEventSource extends BaseTrainingSource
{
    public function handle(): string
    {
        return 'calevent';           // ^[a-z0-9_-]{1,20}$, permanent
    }

    public function label(): string
    {
        return 'Calendar Events';
    }

    public function items(): iterable
    {
        foreach (CalendarEvent::find()->all() as $event) {
            yield [
                'id' => (int)$event->id,
                'siteId' => (int)$event->siteId,
                'title' => (string)$event->title,
            ];
        }
    }

    public function extractText(int $itemId, ?int $siteId): string
    {
        $event = CalendarEvent::find()->id($itemId)->siteId($siteId)->one();
        if (!$event) {
            return '';           // '' = skip this item
        }

        // Reuse the core field extractor — it walks the field layout, respects
        // the configured field exclusions, and flattens Matrix/nested content.
        $text = Plugin::getInstance()->training->extractElementText($event);

        return "When: {$event->startDate->format('Y-m-d H:i')}\n\n" . $text;
    }

    public function elementType(): ?string
    {
        return CalendarEvent::class;   // opt in to auto-train on save
    }
}
```

Register it:

```php
use cstudiossro\craftcschatbot\services\Sources;
use cstudiossro\craftcschatbot\events\RegisterTrainingSourcesEvent;
use yii\base\Event;

Event::on(
    Sources::class,
    Sources::EVENT_REGISTER_SOURCES,
    function (RegisterTrainingSourcesEvent $e) {
        $e->sources[] = new CalendarEventSource();
    },
);
```

### Rules

- **`handle()` must match `^[a-z0-9_-]{1,20}$`** and must not be one of the
  reserved built-ins: `entry`, `file`, `url`, `qa`, `category`, `global`. An
  invalid or reserved handle is warned and dropped.
- **`handle()` is permanent.** It is stored as `chunks.sourceType` (a 20-char
  column). Changing it orphans every chunk that source has indexed — they stop
  being found and stop being cleaned up. Pick it once.
- **`items()` may be a generator.** Prefer `yield` for large sets — "Train all"
  iterates it and queues one `IndexSourceJob` per item, so nothing needs to be in
  memory at once.
- **`extractText()` returns plain text.** Chunking, the contextual
  `Title > Section` prefix and embedding are handled for you. Return `''` to skip
  an item — the source is then marked `empty`, not failed.
- **Throwing from `extractText()`** marks that item `error` with the message
  shown in the CP, and rethrows so the queue job records the failure.
- **`elementType()`** opts into auto-training on save when **Auto-train on save**
  is enabled. Drafts, revisions, propagating and resaving elements are skipped
  for you. Return `null` if your content isn't a Craft element.

### What you get

`Training::extractElementText($element, $prefix = '')` walks a field layout and
flattens it to text, honouring the per-scope field exclusions configured in the
CP. Use it rather than hand-rolling field traversal — nested Matrix and
field-exclusion handling is where custom extraction usually goes wrong.

---

## 3. Adding to the system prompt

`Chat::EVENT_BUILD_SYSTEM_PROMPT` fires while the prompt is assembled, before
the core blocks and the retrieved context are appended.

```php
use cstudiossro\craftcschatbot\services\Chat;
use cstudiossro\craftcschatbot\events\BuildSystemPromptEvent;
use yii\base\Event;

Event::on(
    Chat::class,
    Chat::EVENT_BUILD_SYSTEM_PROMPT,
    function (BuildSystemPromptEvent $e) {
        // Without this, "what's on this weekend?" has no idea when now is.
        $e->additions[] = "# Current date\nToday is " . date('l, j F Y') . '.';
    },
);
```

The event carries:

| Property | |
|---|---|
| `$siteUid` | site resolved from the page URL, or `null` |
| `$question` | the visitor's current message — lets you add context conditionally |
| `$session` | the `ChatSessionRecord` |
| `$additions` | `string[]`, mutable — what you push |

Each addition is appended as its own block, in order. Lead with a `# Heading` so
it doesn't run into the surrounding blocks. Keep them short: every token here is
spent on every turn.

Conditional context, when the block is expensive or rarely relevant:

```php
if (str_contains(mb_strtolower($e->question), 'delivery')) {
    $e->additions[] = "# Delivery\n" . MyShipping::currentLeadTimes();
}
```

---

## 4. Rewriting the reply

`Chat::EVENT_TRANSFORM_REPLY` fires after the model answers, after hallucinated
links are stripped, and before the reply is logged and returned.

```php
use cstudiossro\craftcschatbot\services\Chat;
use cstudiossro\craftcschatbot\events\TransformReplyEvent;
use yii\base\Event;

Event::on(
    Chat::class,
    Chat::EVENT_TRANSFORM_REPLY,
    function (TransformReplyEvent $e) {
        // The model emits [[event:123]]; we resolve it to a real URL here.
        $e->reply = preg_replace_callback(
            '/\[\[event:(\d+)\]\]/',
            fn($m) => MyEvents::urlFor((int)$m[1]) ?? '',
            $e->reply,
        );
    },
);
```

The event carries `$reply` (mutable), `$question`, `$siteUid` and `$session`.

### The token pattern

Models mistype long URLs. If you need the assistant to link to something it
learned from a tool call, have it emit a short token — tell it to in a
`BUILD_SYSTEM_PROMPT` addition or in the capability's `description()` — and
resolve the token to a URL here. That is also why this event runs *after*
`stripHallucinatedLinks()`: URLs you produce are trusted, URLs the model invented
are not.

---

## Debugging

```bash
php craft rag/retrieve "the failing question"   # what was actually retrieved, with scores
php craft rag/extract <entryId>                 # exactly what text got embedded
php craft rag/ask "the failing question"        # the whole pipeline end to end
php craft rag/doctor                            # stale / failed / never-indexed content
php craft rag/gaps                              # questions answered badly, from real traffic
```

Most "the bot is wrong" reports are retrieval problems, not prompt problems.
Check `rag/retrieve` first: if the right chunk isn't in the list, no amount of
prompt editing will fix the answer.

A skill that never fires is almost always its `description()` — the model reads
it as the instruction for when to call the tool. Check the skill is not left at
**Off**, then make the description say when to use it, not just what it is.
