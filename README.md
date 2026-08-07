# Interactive AI Assistant

An AI-powered chat widget and live-chat console for Craft CMS, trained on your own content.

## Features

- **AI chat widget** — embeds a chat bubble (floating) or a docked side panel (Agent mode) on your site.
- **Trained on your content** — index entries, categories, globals, uploaded documents (txt, md, PDF, DOCX), URLs and Q&A pairs; answers are grounded in your own data via embeddings + vector search.
- **Live chat / human handoff** — admins can claim conversations and reply in real time; per-conversation notification tones, mute, favourites and admin notes.
- **Multi-site / translatable** — per-site opening message, company name, suggestions and system prompt.
- **Rich messages** — Markdown rendering, link hover tooltips and on-site link preview cards (sourced from Craft, no outbound HTTP).
- **Moderation** — message filtering, rate limiting and IP bans.
- **Configurable models** — OpenAI GPT-4o and GPT-5 family for chat, selectable embedding model.

## Requirements

Craft CMS 4.5 or later, including Craft 5, and PHP 8.0.2 or later — the same package runs on both, resolving the APIs that moved between them at runtime. An OpenAI API key is required for embeddings and chat completions.

## Installation

Install with Composer and enable the plugin:

```bash
composer require cstudios-s-r-o/interactive-ai-assistant
./craft plugin/install interactive-ai-assistant
```

Then open **AI Assistant → Settings** in the control panel and add your OpenAI API key under **AI Configuration**.

## Configuration

- **General** — enable/disable, branding, theme, operation mode (Chat bubble vs Agent side panel).
- **AI Configuration** — API key, chat & embedding models, system prompt, per-site overrides.
- **Training** — choose which sections, category groups and global sets to index; optional auto-train on save.
- **Suggestions** — starter prompts (global and per-site).
- **Live Chat** — human-handoff master switch, canned responses, admin name display.
- **Filter** / **Logging** — moderation and retention controls.

## Keeping the assistant right

An assistant answers confidently from whatever it was trained on, so the failures
that matter are the quiet ones — a page edited after it was indexed, a section
nobody ever trained, a question nothing in the index could answer.

- **Dashboard** warns when indexed content has changed since it was indexed, when
  a source failed or indexed to nothing, and when a section selected for training
  still has entries that were never indexed. One button re-indexes the changed ones.
- **Training → Gaps** lists the questions the assistant handled badly: retrieval
  found nothing, the match was weak, the visitor rated it down, or it offered a
  human. Answer one there and it becomes a Q&A pair, indexed immediately.
- From the shell:

```bash
./craft interactive-ai-assistant/rag/doctor [--fix]   # what is wrong with the index
./craft interactive-ai-assistant/rag/gaps             # questions worth answering
./craft interactive-ai-assistant/rag/ask "…"          # ask, with confidence and timing
./craft interactive-ai-assistant/rag/retrieve "…"     # what a query actually retrieves
./craft interactive-ai-assistant/rag/extract 1234     # the text an entry contributes
./craft interactive-ai-assistant/rag/retrain-all [--only=entries]
```

## Multiple sites

Entries, categories and globals are indexed per site already. Q&A pairs, crawled
URLs and uploaded files default to **all sites**, and can be pinned to one when
the answer is only true there. A Q&A pair can also be written once and indexed
per site in that site's language: retrieval matches the visitor's own words, so a
pair in another language matches poorly however good the answer is.

Replies are always written in the language the visitor used, whatever language
the content is stored in.

## Skills (agent mode)

With **agent mode** enabled (Settings → AI Configuration), the assistant can call
registered *skills* — server-side tools that fetch live data or perform actions —
during a conversation, in addition to answering from trained content.

Each skill's availability is set per skill in the control panel: **Off**,
**Enabled (everyone)**, or **Admins only (testing)** — the last exposes it only to
logged-in CP users so you can test it on the live site first.

Plugins and Craft modules add their own skills by implementing
`CapabilityInterface` and registering on the `Capabilities` event:

```php
use cstudiossro\craftcschatbot\services\Capabilities;
use cstudiossro\craftcschatbot\events\RegisterCapabilitiesEvent;
use cstudiossro\craftcschatbot\capabilities\BaseCapability;
use yii\base\Event;

class FindNearestShops extends BaseCapability
{
    public function name(): string { return 'find_nearest_shops'; }

    public function description(): string
    {
        return 'Find the shops closest to a city or address the user names.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'location' => ['type' => 'string', 'description' => 'City or address'],
                'limit' => ['type' => 'integer', 'description' => 'How many to return'],
            ],
            'required' => ['location'],
        ];
    }

    public function handle(array $args): mixed
    {
        // … geocode $args['location'], compute distances, return structured data …
        return ['shops' => []];
    }
}

Event::on(
    Capabilities::class,
    Capabilities::EVENT_REGISTER_CAPABILITIES,
    fn(RegisterCapabilitiesEvent $e) => $e->capabilities[] = new FindNearestShops(),
);
```

The model decides when to call a skill, the result is fed back, and it may chain
several calls (bounded by **Max tool iterations**) before answering.
