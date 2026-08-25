# Interactive AI Assistant — working notes for agents

Craft CMS plugin: a RAG chat widget trained on the site's own content, plus a
live-chat console, lead capture and conversational forms.

Read this before touching the code. For adding your own skills, training
sources, prompt context or reply post-processing **from another plugin or
module**, see [docs/EXTENDING.md](docs/EXTENDING.md) — that is the public API and
it should stay stable.

---

## Facts you need up front

| | |
|---|---|
| Composer package | `cstudios-s-r-o/interactive-ai-assistant` |
| Plugin handle | `interactive-ai-assistant` (used in routes, permissions, `Craft::t`) |
| Namespace | `cstudiossro\craftcschatbot\` → `src/` (PSR-4) |
| Craft | `^4.5 \|\| ^5` — **one branch serves both** |
| PHP | `>=8.0.2`, and `config.platform.php` is pinned to `8.0.2` |
| DB tables | `{{%chatbot_*}}` |
| Schema version | `Plugin::$schemaVersion` (currently `1.4.0`) |
| Tests | none |

### PHP 8.0 is the floor — this bites

No enums, no `readonly`, no first-class callable syntax (`$this->foo(...)`), no
`never` return type, no `array_is_list()`, no pure intersection types. All are
8.1. Constructor promotion, `match`, named arguments, nullsafe `?->` and trailing
commas in parameter lists are 8.0 and used throughout.

### Craft 4 *and* 5 — go through `CraftCompat`

`src/helpers/CraftCompat.php` bridges the APIs that moved. Sections live on
`Craft::$app->sections` in Craft 4 and on `Craft::$app->entries` in Craft 5, and
several `...ByUid()` lookups don't exist on Craft 4 at all.

```php
// wrong — breaks on one of the two majors
Craft::$app->sections->getAllSections();
Craft::$app->getGlobals()->getSetByUid($uid);

// right
CraftCompat::getAllSections();
CraftCompat::getGlobalSetByUid($uid);
```

Also there: `getSectionByUid()`, `getCategoryGroupByUid()`, `layoutFieldMap()`,
`scopeFieldMap()`. If you need another cross-version call, add it here rather
than branching at the call site.

---

## Commands

```bash
composer check-cs     # ecs check --ansi        (craft/ecs, CRAFT_CMS_4 set)
composer fix-cs       # ecs check --ansi --fix
composer phpstan      # phpstan level 4 over src/, --memory-limit=1G
```

Run `fix-cs` and `phpstan` before calling any change done. There is no test
suite, so static analysis is the only automated gate.

Console commands live in `src/console/controllers/RagController.php`:

```bash
php craft rag/doctor                 # index health: stale, failed, never-indexed, legacy JSON vectors
php craft rag/ask "question"         # full pipeline, prints the answer
php craft rag/retrieve "query" 8     # just the retrieved chunks + scores — use this to debug bad answers
php craft rag/extract <entryId>      # the exact text that would be embedded for an entry
php craft rag/gaps 25                # questions the assistant answered badly
php craft rag/retrain-all
php craft rag/export [path]          # gzipped bundle of every trained source + vectors
php craft rag/import <path>
```

`rag/retrieve` and `rag/extract` are the fastest way to answer "why did it say
that?" — check what was retrieved before touching prompts.

---

## Layout

```
src/
  Plugin.php              routes, CP nav, auto-train hooks, widget injection, GC
  models/Settings.php     every setting + per-site overrides + form definitions
  services/               business logic — registered as plugin components
  controllers/            CP + public web actions
  console/controllers/    RagController (diagnostics, export/import)
  records/                thin ActiveRecords, one per table
  jobs/                   queue jobs, all thin wrappers over Training
  migrations/             Install.php + dated migrations
  capabilities/           skills the model can call        ← extension point
  training/               custom training sources          ← extension point
  events/                 the four public events           ← extension point
  helpers/                CraftCompat, DocumentText, HtmlRegion, Vector
  templates/              CP Twig, all extend _layout.twig
  translations/{sk,hu}/   plugin strings
  web/assets/widget/      the front-end widget (vanilla JS + CSS, no build step)
```

### Services

Registered in `Plugin::config()['components']`, reached as
`Plugin::getInstance()->openAi` etc. When you add one, register it there **and**
add its `@property-read` line to the `Plugin` class docblock — phpstan relies on
it.

| Service | Does |
|---|---|
| `openAi` | the only place that talks to OpenAI: `embed()`, `chat()`, `chatStream()`, `chatRaw()` |
| `embeddings` | normalize → chunk → embed → store into `chatbot_chunks` |
| `vectorSearch` | `topK()`: cosine + BM25, fused with RRF, optional site filter |
| `chat` | the whole answer pipeline; fires both chat events |
| `training` | one `trainX()`/`removeX()` pair per source kind, plus `indexHealth()` |
| `sources` | registry of plugin-contributed training sources |
| `capabilities` | registry of skills; builds OpenAI tool schemas, runs the calls |
| `forms` | conversational forms: validate, store, deliver (webhook/email/Contact Form) |
| `transfer` | export/import a whole trained index between installs |
| `handoff` `contacts` `bans` `filter` `gaps` `stats` | live chat, leads, abuse, analytics |

---

## The answer pipeline

`Chat::generateReply()` (`src/services/Chat.php:166`) is the spine. In order:

1. **History** — last `historyMessages` turns, from a cache-backed window.
2. **Site resolution** — from `session.pageUrl`, so retrieval can filter to that
   site's chunks (a Slovak query must not pull English ones).
3. **Query rewrite** — `buildRetrievalQuery()` turns an elliptical follow-up into
   a standalone query and decides whether this turn needs retrieval at all.
   Smalltalk is *guarded*: it skips retrieval, and a guarded turn carries no
   confidence signal, so it neither builds nor resets the low-confidence streak.
4. **Retrieve** — embed the standalone query, `vectorSearch->topK()` over a
   candidate pool wider than the context size.
5. **Rerank** — `off` | `mmr` | `llm` (`rerankMode`). Rows come back in *fused*
   rank order, so the best cosine is not necessarily first; confidence is
   `max(score)`, and the usable set is filtered by `minSimilarityScore` raised by
   `relativeScoreFloor × confidence`.
6. **Build the system prompt** — site prompt, then `EVENT_BUILD_SYSTEM_PROMPT`
   additions, then core blocks appended in a fixed order (language, using the
   context, output format, starter prompts, handoff signal, tools, forms,
   context). Numbered `[1] (sourceType) URL: …` citation blocks come last.
7. **Complete** — `complete()` runs the tool-calling loop, bounded by
   `maxToolIterations`; on hitting the cap it makes one final tool-less call to
   force an answer.
8. **Post-process** — strip the `[[HANDOFF_OFFER]]` sentinel, unlink
   hallucinated on-site URLs (`stripHallucinatedLinks()` — anything not in the
   retrieved context), then fire `EVENT_TRANSFORM_REPLY`. Order matters: the
   strip runs *first* so token→URL resolutions done by listeners stay trusted.
9. **Log & signal** — persist the message with its retrieval query and chunk
   count, update the low-confidence streak, decide `offerHuman`.

Prompt text is assembled inline in that method. It is long and load-bearing —
each block has a comment saying which failure it exists to prevent. Read the
comment before editing the block.

## The training pipeline

Six built-in kinds — `entry`, `category`, `global`, `file`, `url`, `qa` — plus
any registered custom source. Each has a `chatbot_training_*` row (status,
`chunkCount`, `errorMessage`, `lastTrainedAt`) and N `chatbot_chunks` rows.

```
Training::trainX()  →  extract text  →  Embeddings::reindexSource()
                                          normalize → chunk → contextual prefix
                                          → openAi->embed()  → chunk rows
```

Two invariants in `Embeddings::reindexSourceVariants()` worth preserving:

- **Embed before deleting.** Old chunks are dropped only after the embedding
  call succeeds, inside a transaction. Deleting first meant a rate limit left the
  source with nothing and the assistant silently lost that content.
- **Variants are one batch.** A Q&A indexed per-site in each site's language is
  embedded in a single call and swapped in together, so a partial failure can't
  half-index a source.

Vectors are packed little-endian float32 in `chunks.embeddingBlob`
(`helpers/Vector::pack()` / `unpack()`). The old JSON `embedding` column is still
read so pre-`m260807_140000` chunks keep working; `rag/doctor` counts what's
left. ~30 KB as JSON vs ~6 KB packed, and every query decoded all of them — that
was the ceiling on how much a site could be trained on.

Auto-training on save is wired in `Plugin::registerAutoTrain()` (entries,
categories, global sets, gated on `autoTrainOnSave` and the configured
UIDs) and `Plugin::registerSourceAutoTrain()` (custom sources that return an
`elementType()`). Drafts, revisions, propagating and resaving elements are always
skipped.

---

## Conventions

**Controllers.** CP controllers extend `craft\web\Controller` and gate in
`beforeAction()`:

```php
$this->requirePermission('accessPlugin-interactive-ai-assistant');
```

`ChatController` is the exception — it is the visitor-facing endpoint, with an
`$allowAnonymous` action list and CSRF off. It also calls `releaseSessionLock()`
before the model call: PHP holds an exclusive lock on the session file for the
whole request, and a chat turn spends tens of seconds in OpenAI, so every other
request carrying that cookie would block. Nothing on that path writes to the
session. Don't add a session write there.

**Routes.** Every CP URL is declared in `Plugin::registerUrlRules()`. Craft
highlights the subnav by URL *prefix*, which is why merged screens are nested
under the group's path (`…/missed-chats/submissions`, `…/logs/bans`).

**Templates.** `src/templates/**`, rendered as
`interactive-ai-assistant/<path>`, extending `_layout.twig` (which sets crumbs
and the subnav). Partials are `_`-prefixed.

**Records.** Thin `craft\db\ActiveRecord`: a `@property` docblock listing every
column, and `tableName()`. No logic — it lives in services.

**Jobs.** `craft\queue\BaseJob` with public typed props, an `execute()` that
delegates to a service, and a `defaultDescription()` using
`Craft::t('interactive-ai-assistant', …)`.

**Migrations.** `m<YYMMDD>_<HHMMSS>_<snake_name>.php`, guarded with
`columnExists()`/`tableExists()` so re-runs are safe, with `safeDown()`. Adding
one means bumping `Plugin::$schemaVersion`. Add new columns to `Install.php` too
— a fresh install runs only that.

**Settings.** One flat `models/Settings.php`. Per-site overrides follow a pair
convention: a scalar default plus a `…s`/`…BySite` array keyed by site UID, read
through a `getXForSite(?string $siteUid)` accessor (`initialMessage` /
`initialMessages`, `systemPrompt` / `systemPrompts`, `suggestions` /
`suggestionsBySite`, …). Follow it for anything new that is per-site. The API key
goes through `App::parseEnv()` via `getOpenaiApiKey()` — never read
`$settings->openaiApiKey` directly.

**i18n.** Plugin strings use the `interactive-ai-assistant` category
(`translations/sk`, `translations/hu`; keys *are* the English source strings).
Widget strings are not translated client-side — `ChatController::widgetStrings()`
resolves them server-side per site language and ships them in the
`chat/config` payload. Admin-authored text (form labels, choice options) can't
live in the plugin's files, so it goes through Craft's `site` category:
`{{ form.label|t('site') }}`.

**Widget.** `src/web/assets/widget/widget.{js,css}`, vanilla, no build step —
edit the shipped files directly. It reads `window.csChatbot.urls` (injected in
`Plugin::registerWidgetInjection()`) and everything else from `chat/config`.

**Commits.** `type(scope): imperative summary` — `feat(training):`,
`fix(chat):`, `perf(rag):`, `docs:`. Summaries describe the user-visible effect,
not the diff.

**CHANGELOG.** Prose under `## Unreleased`, written for site owners: what
changed, where in the CP, and what it means for them. Not bullet fragments.

**`.gitattributes`.** Dev-only files are `export-ignore`d so the Composer
archive stays light. Add new ones there.

---

## Gotchas

- **`chunks.sourceType` is `varchar(20)`.** Training source handles are capped at
  20 chars for that reason, and validated `^[a-z0-9_-]{1,20}$`.
- **Capability names are an OpenAI constraint:** `^[a-zA-Z0-9_-]{1,64}$`.
  Invalid ones are warned and dropped, not thrown.
- **Reserved source handles:** `entry`, `file`, `url`, `qa`, `category`,
  `global`.
- **The registries fire their events in `init()`**, i.e. on first access. Attach
  listeners in your module's `init()`, before anything touches
  `Plugin::getInstance()->sources` / `->capabilities`. `registerSourceAutoTrain()`
  runs inside `Craft::$app->onInit`, which reads the source registry.
- **Skill availability is checked twice** — when tool schemas are built *and*
  inside `Capabilities::run()`. A model can name a tool it was never offered,
  via stale schemas in the message history or by inventing one; an `off` or
  admins-only skill must not run because something asked for it by name. Keep
  both checks.
- **`admins` state means logged-in CP users**, checked as
  `!getIsGuest() && checkPermission('accessCp')` — it's the "test it live before
  visitors see it" state, not a role.
- **Capability exceptions are not errors.** `Capabilities::run()` catches
  `Throwable` and hands `['ok' => false, 'error' => …]` back to the model.
- **Debug mode restricts the widget to CP users**, not to a debug bar —
  `widgetVisibleForCurrentUser()`. It defaults to `true` on a fresh install.
