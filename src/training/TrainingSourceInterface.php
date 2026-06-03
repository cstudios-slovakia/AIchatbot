<?php

namespace cstudiossro\craftcschatbot\training;

/**
 * A custom training source: a pluggable provider that exposes some body of
 * content — typically a non-core element type from another plugin (e.g.
 * Solspace Calendar events, Commerce products) — to the assistant's training
 * pipeline.
 *
 * Register one by listening to
 * {@see \cstudiossro\craftcschatbot\services\Sources::EVENT_REGISTER_SOURCES}.
 * The plugin then lists it under Training → Plugins, enumerates its items when
 * "Train all" is pressed, and embeds the text returned by {@see extractText()}
 * so the chatbot can answer from it like any other trained content.
 */
interface TrainingSourceInterface
{
    /**
     * Unique, stable key for this source. Must match ^[a-z0-9_-]{1,20}$ and not
     * clash with the built-in source types (entry, file, url, qa, category,
     * global). Used as the chunk sourceType, so keep it short and permanent.
     */
    public function handle(): string;

    /**
     * Human-readable label shown in the control panel, e.g. "Calendar Events".
     */
    public function label(): string;

    /**
     * Every trainable item this source can provide. Each item is an associative
     * array: ['id' => int, 'siteId' => ?int, 'title' => string]. `id` is your
     * own element/record id; it is passed back to {@see extractText()}.
     *
     * @return iterable<int, array{id:int, siteId?:?int, title?:string}>
     */
    public function items(): iterable;

    /**
     * Build the plain, searchable text for a single item. Return '' to skip it.
     * You may reuse the core field extractor via
     * Plugin::getInstance()->training->extractElementText($element).
     */
    public function extractText(int $itemId, ?int $siteId): string;

    /**
     * Optional: fully-qualified Craft element class to auto-train on save/delete
     * when "Auto-train on save" is enabled (e.g. Solspace\Calendar\Elements\Event).
     * Return null to opt out of auto-training.
     */
    public function elementType(): ?string;
}
