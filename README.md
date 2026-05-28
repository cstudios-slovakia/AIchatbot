# Interactive AI Assistant

An AI-powered chat widget and live-chat console for Craft CMS, trained on your own content.

## Features

- **AI chat widget** — embeds a chat bubble (floating) or a docked side panel (Agent mode) on your site.
- **Trained on your content** — index entries, categories, globals, files, URLs and Q&A pairs; answers are grounded in your own data via embeddings + vector search.
- **Live chat / human handoff** — admins can claim conversations and reply in real time; per-conversation notification tones, mute, favourites and admin notes.
- **Multi-site / translatable** — per-site opening message, company name, suggestions and system prompt.
- **Rich messages** — Markdown rendering, link hover tooltips and on-site link preview cards (sourced from Craft, no outbound HTTP).
- **Moderation** — message filtering, rate limiting and IP bans.
- **Configurable models** — OpenAI GPT-4o and GPT-5 family for chat, selectable embedding model.

## Requirements

Craft CMS 5 or later, and PHP 8.2 or later. An OpenAI API key is required for embeddings and chat completions.

## Installation

Install with Composer and enable the plugin:

```bash
composer require cstudios-s-r-o/craft-cs-chatbot
./craft plugin/install _cs-chatbot
```

Then open **AI Assistant → Settings** in the control panel and add your OpenAI API key under **AI Configuration**.

## Configuration

- **General** — enable/disable, branding, theme, operation mode (Chat bubble vs Agent side panel).
- **AI Configuration** — API key, chat & embedding models, system prompt, per-site overrides.
- **Training** — choose which sections, category groups and global sets to index; optional auto-train on save.
- **Suggestions** — starter prompts (global and per-site).
- **Live Chat** — human-handoff master switch, canned responses, admin name display.
- **Filter** / **Logging** — moderation and retention controls.
