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
- **AI Configuration** — API key, chat & embedding models, system prompt, per-site overrides,
  AI disclaimer (a small note under the input saying answers can be wrong; off by
  default, wording translated per site unless overridden).
- **Training** — choose which sections, category groups and global sets to index; optional auto-train on save.
- **Suggestions** — starter prompts (global and per-site).
- **Live Chat** — human-handoff master switch, canned responses, admin name display.
- **Filter** / **Logging** — moderation and retention controls.

## What gets indexed

By default an entry is indexed from its **fields**: a metadata header, then one
`Field label: value` block per non-empty field. The labels matter — a bare `649`
embeds as noise, `Cena: 649` is a retrievable fact.

Two settings under **Settings → Training** change what that includes.

**Fields to keep out of the index.** Per section, category group and global set,
plus a `*` list that applies everywhere. The field stays untouched on the site;
it just stops reaching the assistant. Use it for values that are real but
incomplete — a price only some entries carry makes the assistant quote a figure
for one product and nothing for the next, which reads as inconsistency rather
than as a gap.

**Index from the rendered page.** On an older site much of what a visitor reads
never reaches a field:

```twig
{{ 'RC2'|t }}
{{ '36dB'|t }}
```

Field indexing cannot see any of that. Sections listed here are indexed by
fetching each entry's own URL instead, which returns exactly what the visitor
gets, in the right language per site. Field values are left out in this mode
because the template already printed them — indexing both stores the same
sentence twice and skews the lexical scores.

It fetches over HTTP rather than rendering in-process on purpose: templates
reach for request state (segments, query params, CSRF, the current user) and a
console-side render breaks on them differently in every project.

Two things it needs:

- **A site `baseUrl` that is a real URL.** The Craft default is the `@web` alias,
  which resolves from the current request and so resolves to nothing in the queue
  worker that does the indexing. Point it at `$PRIMARY_SITE_URL` from `.env`.
  Without this every entry quietly falls back to field indexing; `rag/doctor`
  says so.
- **A content selector**, e.g. `main` or `#content, article`. Nav, cookie bar and
  footer repeat on every page, so left in they become the most common text in the
  whole index and match every question a little. With no selector the plugin
  tries `<main>`, `[role=main]`, `<article>`, `#content`, `#main`, then falls back
  to stripping chrome structurally — and undoes that automatically if it would
  empty the page.

A URL training record pointing at a page that a page-rendered entry already
covers is skipped rather than crawled twice.

### Uploaded documents

`txt`, `md`, `pdf` and `docx`. Two things happen to a PDF that do not happen to
the others.

**Encrypted files are read where the file itself permits it.** Supplier
catalogues, price lists and standards are routinely saved with an owner
password — they open without one, and their own permission flags allow text
extraction, but the PHP parser refuses every encrypted file alike. Those are
retried through `pdftotext` (poppler-utils) or `qpdf` when either is installed,
which is what the rest of the world already uses to read them. Neither tool is
required and neither is bundled; without them such a file reports what to
install, and says so specifically rather than blaming a password the file does
not have. A PDF that needs a password to *open* is never guessed at — re-save it
unlocked and upload that.

The tool has to be where PHP runs, which in a container is not your machine. On
DDEV that means `webimage_extra_packages: ['poppler-utils']` in
`.ddev/config.yaml` followed by `ddev restart`; on a Debian/Ubuntu server,
`apt install poppler-utils`.

**Running headers, footers and watermarks are dropped.** A line repeated on
every page is the most common text in the document, so left in it embeds into
every chunk and matches every question a little — the same failure as indexing
site chrome. Only lines that repeat at least five times *and* are at least
fifteen characters go, which is conservative on purpose: a short repeated value
like a class name or a standard's number is content, not furniture.

**Links that would 404 are never handed out.** A section can carry a URI format
with no template behind it; Craft builds addresses from it happily and they all
404. Those URLs are dropped both from indexed text and from answer-time link
resolution — filtering only one leaves the other free to produce the link.
`rag/doctor` names the section so it can be fixed at the source.

## Keeping the assistant right

An assistant answers confidently from whatever it was trained on, so the failures
that matter are the quiet ones — a page edited after it was indexed, a section
nobody ever trained, a question nothing in the index could answer.

- **Dashboard** warns when indexed content has changed since it was indexed, when
  a source failed or indexed to nothing, and when a section selected for training
  still has entries that were never indexed. One button re-indexes the changed ones.
- **New leads announce themselves.** A missed chat awaiting follow-up or a form
  submission nobody has opened yet shows as a purple badge on the AI Assistant
  nav item on every CP page, in the browser tab title, and as a banner on the
  dashboard; the poll that finds one plays a short chime and a CP notice. Missed
  chats clear when resolved, submissions when the list is opened.
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

## Moving a trained index to another site

Train on a local or staging copy, where a bad crawl costs nothing and the queue
can run flat out, then carry the finished index to the live site instead of
paying for every embedding twice.

**Training → Transfer** exports every trained source, its vectors and the
documents behind them as one gzipped bundle, and imports one back. The same
thing from the shell, which is the better route for a large index:

```bash
./craft interactive-ai-assistant/rag/export                       # storage/cs-chatbot/exports/
./craft interactive-ai-assistant/rag/export --only=files,urls,qa --no-files
./craft interactive-ai-assistant/rag/import bundle.ndjson.gz --dry-run
./craft interactive-ai-assistant/rag/import bundle.ndjson.gz
```

This is not a table dump, and the difference matters. Every trained row points
at Craft content by *local* id, so copied verbatim into another database those
ids address different content and the assistant answers confidently from the
wrong page. The bundle stores element UIDs and site handles instead, resolves
them against the target on the way in, and **reports what it could not place**
rather than guessing — an entry authored separately on the live site has a
different UID, and is listed as skipped so you can train it there.

- **Uploaded documents, crawled URLs and Q&A pairs are portable anywhere.** They
  carry their own content, so they land on any install.
- **Entries, categories and globals need the same content on both sides** — a
  live database the local copy came from, or content deployed by project config.
- **Both installs must use the same embedding model.** Vectors from different
  models are not comparable, so a mismatch is refused rather than silently
  wrecking retrieval. Import with `--reembed` (or tick *Re-embed here*) to bring
  the sources over and embed them on the target instead.
- `--site-map=sk=en` maps a bundle's site handles onto differently-named ones.
- Plugin-contributed sources are matched by the id their own plugin gave them;
  re-train those on the target unless that content came from the same database.

Plugin settings — the system prompt, the model, the thresholds — travel with
Craft's project config already, so they are not part of the bundle. Chat logs,
leads and bans are deliberately left behind too.

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

Form labels and choice options are typed in the CP, so they can't ship in the
plugin's translation files. They are translated through Craft's `site` category:
add them to `translations/<language>/site.php` and each site's visitors see their
own language, exactly as Craft handles field and section labels. Untranslated
strings are shown as typed. Choice *values* are never translated — they are what
gets stored and delivered, so only the text beside them changes.

Each skill's availability is set per skill in the control panel: **Off**,
**Enabled (everyone)**, or **Admins only (testing)** — the last exposes it only to
logged-in CP users so you can test it on the live site first. A newly created
conversational form starts at **Admins only**, so it can be tried on the live
site before visitors can reach it.

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
