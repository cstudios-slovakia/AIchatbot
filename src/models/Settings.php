<?php

namespace cstudiossro\craftcschatbot\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;

class Settings extends Model
{
    // General
    public bool $enabled = true;
    // When true, the widget is only served to logged-in control-panel users (admins/staff), not the public — lets you test before going live.
    public bool $debugMode = true;
    public string $companyName = 'Chatbot';
    /** @var array<string, string> Craft site UID => override company name. */
    public array $companyNames = [];
    public string $logoText = 'CB';
    public ?int $logoAssetId = null;
    public string $primaryColor = '#2563eb';
    public string $logoBgColor = '#1f2937';
    public string $bubbleBotColor = '';   // empty = use theme default (#f3f4f6 light, #1f2937 dark)
    public string $bubbleAdminColor = ''; // empty = use default #10b981
    public string $bubbleUserColor = '';  // empty = follow primaryColor
    public string $defaultTheme = 'light'; // light|dark
    // Operation mode: 'chat' = floating bubble (current behavior). 'agent' = docked full-height side panel that squeezes page content.
    public string $operationMode = 'chat'; // chat|agent
    public int $agentPanelWidth = 420; // px width of the docked agent panel
    // Corner the widget bubble/panel sits in (agent mode uses only the left/right part to pick the dock side).
    public string $widgetPosition = 'bottom-right'; // bottom-right|bottom-left|top-right|top-left

    // AI configuration
    public string $openaiApiKey = '';
    public string $chatModel = 'gpt-4o-mini';
    public string $embeddingModel = 'text-embedding-3-small';
    public string $initialMessage = 'Hi! How can I help you today?';
    /** @var array<string, string> Craft site UID => override initial message. Empty/missing => use $initialMessage. */
    public array $initialMessages = [];
    public string $systemPrompt = 'You are a helpful assistant for this website. Answer using only the provided context. If the context does not contain the answer, say you do not know and suggest contacting the team. Keep answers concise and accurate.';
    /** @var array<string, string> Craft site UID => override system prompt. */
    public array $systemPrompts = [];
    public int $maxContextChunks = 5;
    // text-embedding-3-small puts genuinely relevant matches around 0.3–0.5,
    // so 0.65 filtered out almost everything. Keep this low to avoid starving
    // the model of context.
    public float $minSimilarityScore = 0.35;

    // Retrieval (RAG) tuning. Each flag gates a retrieval improvement and can be
    // switched off to fall back to the original cosine-only behaviour.
    // Rewrite each follow-up into a standalone query (resolve pronouns/ellipsis)
    // before embedding, so retrieval understands "and the price?".
    public bool $queryRewriteEnabled = true;
    // Skip retrieval entirely for greetings/thanks/smalltalk that need no context.
    public bool $retrievalGuardEnabled = true;
    // Fuse lexical (BM25) and vector (cosine) rankings via Reciprocal Rank Fusion,
    // so exact terms/names/numbers aren't lost by embeddings alone.
    public bool $hybridEnabled = true;
    // RRF constant k in Σ 1/(k+rank). Higher = flatter fusion.
    public int $rrfK = 60;
    // How many candidates to retrieve before reranking down to maxContextChunks.
    public int $retrievalCandidatePool = 30;
    // How the candidate pool is reduced to the final context: 'off' keeps fused
    // order, 'mmr' diversifies in PHP (free), 'llm' scores with a cheap model call.
    public string $rerankMode = 'llm'; // off|mmr|llm
    // MMR trade-off between relevance (1.0) and diversity (0.0). Used when rerankMode=mmr.
    public float $mmrLambda = 0.7;
    // Restrict retrieval to the current site's chunks (plus site-agnostic
    // url/file/qa chunks). Stops cross-site/cross-language bleed. Requires a
    // retrain to populate chunk siteId; a no-op until then.
    public bool $siteFilterEnabled = true;

    // Indexing / chunking (used at training time). Changing any of these needs a
    // full retrain to take effect on existing content.
    // Target chunk size in characters (~4 chars/token). Smaller = more precise.
    public int $chunkSize = 1200;
    // Character overlap carried between adjacent chunks for context continuity.
    public int $chunkOverlap = 150;
    // Prepend a "Document title > Section" breadcrumb to each chunk before
    // embedding (and keep it in stored content) so retrieval and generation know
    // where each chunk came from. Cheap contextual-retrieval win.
    public bool $contextualPrefixEnabled = true;
    // Output dimensions for text-embedding-3-* (0 = model default: 1536 for
    // 3-small, 3072 for 3-large). Cap 3-large to 1536 for near-large quality at
    // small-model storage. Changing this needs a full retrain.
    public int $embeddingDimensions = 0;

    // Agent / skills
    // Master switch for tool-calling ("skills"). When off, the assistant is pure retrieval QA.
    public bool $agentModeEnabled = false;
    // Safety cap on how many tool-call rounds one answer may take.
    public int $maxToolIterations = 3;
    /**
     * Per-skill availability: capability name => 'off' | 'on' | 'admins'.
     * 'admins' exposes the skill only to logged-in CP users (for testing on the live widget).
     * Missing entry = 'off'.
     * @var array<string, string>
     */
    public array $capabilityStates = [];

    /**
     * Conversational form definitions. Each form becomes an LLM tool (capability)
     * the assistant can call to collect fields from the visitor and submit them to
     * a configured destination. Availability (off/on/admins) is governed by
     * {@see $capabilityStates} keyed by the form's `name`, like any other skill.
     *
     * Each entry:
     *   name        string  tool name, ^[a-zA-Z0-9_-]{1,64}$
     *   label       string  CP display label
     *   description string  shown to the model — when to use this form
     *   fields      array   [{ name, label, type:text|email|tel|number|textarea|select,
     *                           required:bool, description, options:string[] (select only) }]
     *   delivery    array   { webhook: {enabled,url,method,headers:[{key,value}]},
     *                          email:   {enabled,to,subject} }   (submissions are always stored)
     * @var array<int, array<string, mixed>>
     */
    public array $forms = [];

    /**
     * Master switch for the conversational-forms feature (form definitions, the
     * Forms/Submissions CP screens, and exposing forms as skills). Off by default.
     */
    public bool $formsEnabled = false;

    // Training
    /** @var string[] section UIDs */
    public array $trainingSections = [];
    /** @var string[] category group UIDs */
    public array $trainingCategoryGroups = [];
    /** @var string[] global set UIDs */
    public array $trainingGlobalSets = [];
    public bool $autoTrainOnSave = false;

    // Suggestions
    public bool $suggestionsEnabled = true;
    /** @var string[] */
    public array $suggestions = [];
    /** @var array<string, string[]> Craft site UID => override suggestions list. */
    public array $suggestionsBySite = [];

    // Live chat (canned responses for admin)
    /** @var string[] */
    public array $chatTemplates = [];
    public bool $showAdminName = true;
    // Master switch: whether users can request a human at all. When false, no handoff button/offer is shown anywhere.
    public bool $humanHandoffEnabled = true;
    // 'always' = persistent "Talk to a human" button. 'ai' = only inline offer when bot suggests it.
    public string $humanHandoffMode = 'always';
    // Auto-close inactive handoff sessions after N minutes. 0 = disabled.
    public int $autoCloseInactiveMinutes = 15;
    // Email a notification when a visitor requests a human (waiting for live chat).
    public bool $handoffNotifyEnabled = false;
    // Recipient(s) for that notification (comma-separated; supports $ENV_VAR).
    public string $handoffNotifyEmail = '';
    // Editable notification template. Placeholders: {shortId}, {pageUrl}, {cpUrl}, {cpUrlDirect}.
    public string $handoffNotifySubject = 'New live chat request — {shortId}';
    public string $handoffNotifyBody = "A visitor is waiting to chat with a human.\n\nConversation: {shortId}\nPage: {pageUrl}\n\nOpen this conversation: {cpUrlDirect}";
    // Contact capture: offer to collect email/phone when the bot can't help or no agent shows up.
    public bool $contactCaptureEnabled = true;
    // Minutes a handoff can sit unclaimed before the widget auto-asks for contact details. 0 = never.
    public int $contactPromptTimeoutMinutes = 5;

    // Filter
    public bool $filterEnabled = true;
    public int $filterMinLength = 2;
    public int $filterMaxLength = 2000;
    public int $filterRateWindowSeconds = 60;
    public int $filterRateMaxMessages = 12;
    /** @var string[] */
    public array $filterBlockedWords = [];

    // Logging
    public bool $ratingsEnabled = false;
    public bool $loggingEnabled = true;
    public int $logRetentionDays = 0; // 0 = forever

    public function rules(): array
    {
        return [
            [['primaryColor', 'logoBgColor', 'bubbleBotColor', 'bubbleAdminColor', 'bubbleUserColor'], 'filter', 'filter' => [self::class, 'normalizeHexColor']],
            [['companyName', 'logoText', 'primaryColor', 'logoBgColor', 'bubbleBotColor', 'bubbleAdminColor', 'bubbleUserColor', 'defaultTheme', 'operationMode', 'chatModel', 'embeddingModel', 'initialMessage', 'systemPrompt'], 'string'],
            [['enabled', 'debugMode', 'autoTrainOnSave', 'suggestionsEnabled', 'ratingsEnabled', 'loggingEnabled', 'showAdminName', 'humanHandoffEnabled', 'filterEnabled', 'contactCaptureEnabled', 'agentModeEnabled', 'formsEnabled', 'handoffNotifyEnabled', 'queryRewriteEnabled', 'retrievalGuardEnabled', 'hybridEnabled', 'siteFilterEnabled', 'contextualPrefixEnabled'], 'boolean'],
            [['handoffNotifyEmail', 'handoffNotifySubject', 'handoffNotifyBody'], 'string'],
            [['maxContextChunks', 'logRetentionDays', 'logoAssetId', 'filterMinLength', 'filterMaxLength', 'filterRateWindowSeconds', 'filterRateMaxMessages', 'autoCloseInactiveMinutes', 'contactPromptTimeoutMinutes', 'maxToolIterations'], 'integer'],
            [['maxToolIterations'], 'integer', 'min' => 1, 'max' => 10],
            [['capabilityStates'], 'safe'],
            [['forms'], 'safe'],
            [['forms'], 'validateForms'],
            [['autoCloseInactiveMinutes', 'contactPromptTimeoutMinutes'], 'integer', 'min' => 0],
            [['filterMinLength'], 'integer', 'min' => 1],
            [['filterMaxLength'], 'integer', 'min' => 10],
            [['filterRateWindowSeconds', 'filterRateMaxMessages'], 'integer', 'min' => 0],
            [['filterBlockedWords'], 'safe'],
            [['maxContextChunks'], 'integer', 'min' => 1, 'max' => 20],
            [['logRetentionDays'], 'integer', 'min' => 0],
            [['minSimilarityScore'], 'number', 'min' => 0, 'max' => 1],
            [['rrfK'], 'integer', 'min' => 1],
            [['retrievalCandidatePool'], 'integer', 'min' => 5, 'max' => 100],
            [['rerankMode'], 'in', 'range' => ['off', 'mmr', 'llm']],
            [['rerankMode'], 'string'],
            [['mmrLambda'], 'number', 'min' => 0, 'max' => 1],
            [['chunkSize', 'chunkOverlap', 'embeddingDimensions'], 'integer'],
            [['chunkSize'], 'integer', 'min' => 300, 'max' => 4000],
            [['chunkOverlap'], 'integer', 'min' => 0, 'max' => 1000],
            [['embeddingDimensions'], 'integer', 'min' => 0, 'max' => 3072],
            [['defaultTheme'], 'in', 'range' => ['light', 'dark']],
            [['operationMode'], 'in', 'range' => ['chat', 'agent']],
            [['widgetPosition'], 'string'],
            [['widgetPosition'], 'in', 'range' => ['bottom-right', 'bottom-left', 'top-right', 'top-left']],
            [['agentPanelWidth'], 'integer', 'min' => 280, 'max' => 900],
            [['humanHandoffMode'], 'in', 'range' => ['always', 'ai']],
            [['humanHandoffMode'], 'string'],
            [['logoText'], 'string', 'max' => 3],
            [['trainingSections', 'trainingCategoryGroups', 'trainingGlobalSets', 'suggestions', 'chatTemplates', 'initialMessages', 'companyNames', 'systemPrompts', 'suggestionsBySite'], 'safe'],
            [['companyName'], 'required'],
        ];
    }

    /**
     * Availability of a skill: 'off' | 'on' | 'admins'. Unknown skills are off.
     */
    public function capabilityState(string $name): string
    {
        $state = $this->capabilityStates[$name] ?? 'off';
        return in_array($state, ['off', 'on', 'admins'], true) ? $state : 'off';
    }

    /**
     * Valid, registerable form definitions (have a syntactically valid unique
     * name and at least one named field). Invalid/partial rows are skipped so a
     * half-built form never reaches the model.
     *
     * @return array<int, array<string, mixed>>
     */
    public function formDefinitions(): array
    {
        $out = [];
        $seen = [];
        foreach ($this->forms as $form) {
            if (!is_array($form)) {
                continue;
            }
            $name = (string)($form['name'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $name) || isset($seen[$name])) {
                continue;
            }
            $fields = array_values(array_filter(
                is_array($form['fields'] ?? null) ? $form['fields'] : [],
                fn($f) => is_array($f) && ($f['name'] ?? '') !== ''
            ));
            if (!$fields) {
                continue;
            }
            $form['fields'] = $fields;
            $seen[$name] = true;
            $out[] = $form;
        }
        return $out;
    }

    public function getForm(string $name): ?array
    {
        foreach ($this->formDefinitions() as $form) {
            if (($form['name'] ?? null) === $name) {
                return $form;
            }
        }
        return null;
    }

    /**
     * Validate form definitions: each needs a valid unique tool name, at least
     * one named field, and a reachable destination when webhook/email is on.
     */
    public function validateForms(string $attribute): void
    {
        if (!is_array($this->$attribute)) {
            $this->addError($attribute, 'Forms must be a list.');
            return;
        }
        $seen = [];
        foreach ($this->$attribute as $i => $form) {
            if (!is_array($form)) {
                continue;
            }
            $name = (string)($form['name'] ?? '');
            $label = $form['label'] ?? $name;
            if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $name)) {
                $this->addError($attribute, "Form \"{$label}\": name must be 1–64 chars of letters, numbers, _ or -.");
            } elseif (isset($seen[$name])) {
                $this->addError($attribute, "Duplicate form name \"{$name}\".");
            }
            $seen[$name] = true;

            $fields = is_array($form['fields'] ?? null) ? $form['fields'] : [];
            $hasField = false;
            foreach ($fields as $f) {
                if (is_array($f) && ($f['name'] ?? '') !== '') {
                    $hasField = true;
                    break;
                }
            }
            if (!$hasField) {
                $this->addError($attribute, "Form \"{$label}\": add at least one field.");
            }

            $delivery = is_array($form['delivery'] ?? null) ? $form['delivery'] : [];
            if (!empty($delivery['webhook']['enabled']) && trim((string)($delivery['webhook']['url'] ?? '')) === '') {
                $this->addError($attribute, "Form \"{$label}\": webhook is on but the URL is empty.");
            }
            if (!empty($delivery['email']['enabled']) && trim((string)($delivery['email']['to'] ?? '')) === '') {
                $this->addError($attribute, "Form \"{$label}\": email delivery is on but the recipient is empty.");
            }
            if (!empty($delivery['contactform']['enabled']) && trim((string)($delivery['contactform']['emailField'] ?? '')) === '') {
                $this->addError($attribute, "Form \"{$label}\": Contact Form delivery is on but no email field is selected.");
            }
        }
    }

    public static function normalizeHexColor(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $v = trim($value);
        if ($v === '') {
            return '';
        }
        if ($v[0] !== '#') {
            $v = '#' . $v;
        }
        // accept #rgb, #rrggbb, #rrggbbaa
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v)) {
            return strtolower($v);
        }
        return '';
    }

    public function getOpenaiApiKey(): string
    {
        return App::parseEnv($this->openaiApiKey) ?: '';
    }

    private function resolveSiteUid(?string $siteUid): ?string
    {
        if ($siteUid !== null) {
            return $siteUid;
        }
        try {
            return Craft::$app->sites->getCurrentSite()->uid ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function getInitialMessageForSite(?string $siteUid = null): string
    {
        $uid = $this->resolveSiteUid($siteUid);
        if ($uid !== null && isset($this->initialMessages[$uid])) {
            $override = trim((string)$this->initialMessages[$uid]);
            if ($override !== '') {
                return $override;
            }
        }
        return $this->initialMessage;
    }

    public function getCompanyNameForSite(?string $siteUid = null): string
    {
        $uid = $this->resolveSiteUid($siteUid);
        if ($uid !== null && isset($this->companyNames[$uid])) {
            $override = trim((string)$this->companyNames[$uid]);
            if ($override !== '') {
                return $override;
            }
        }
        return $this->companyName;
    }

    public function getSystemPromptForSite(?string $siteUid = null): string
    {
        $uid = $this->resolveSiteUid($siteUid);
        if ($uid !== null && isset($this->systemPrompts[$uid])) {
            $override = trim((string)$this->systemPrompts[$uid]);
            if ($override !== '') {
                return $override;
            }
        }
        return $this->systemPrompt;
    }

    /**
     * @return string[]
     */
    public function getSuggestionsForSite(?string $siteUid = null): array
    {
        $uid = $this->resolveSiteUid($siteUid);
        if ($uid !== null && isset($this->suggestionsBySite[$uid])) {
            $override = $this->suggestionsBySite[$uid];
            if (is_array($override)) {
                $clean = array_values(array_filter(array_map(fn($v) => trim((string)$v), $override), fn($v) => $v !== ''));
                if (!empty($clean)) {
                    return $clean;
                }
            }
        }
        return array_values(array_filter($this->suggestions, fn($v) => trim((string)$v) !== ''));
    }

    /**
     * Resolve a Craft site by best-matching baseUrl prefix against $url.
     * Falls back to null if no match.
     */
    public static function resolveSiteFromUrl(?string $url): ?\craft\models\Site
    {
        if (!$url) {
            return null;
        }
        $url = rtrim($url, '/');
        $best = null;
        $bestLen = -1;
        foreach (Craft::$app->sites->getAllSites() as $site) {
            $base = rtrim((string)$site->getBaseUrl(), '/');
            if ($base === '') {
                continue;
            }
            if (str_starts_with($url, $base) && strlen($base) > $bestLen) {
                $best = $site;
                $bestLen = strlen($base);
            }
        }
        return $best;
    }
}
