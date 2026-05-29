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
    public bool $debugMode = false;
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
    public float $minSimilarityScore = 0.65;

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

    // Filter
    public bool $filterEnabled = true;
    public int $filterMinLength = 2;
    public int $filterMaxLength = 2000;
    public int $filterRateWindowSeconds = 60;
    public int $filterRateMaxMessages = 12;
    /** @var string[] */
    public array $filterBlockedWords = [];

    // Logging
    public bool $ratingsEnabled = true;
    public bool $loggingEnabled = true;
    public int $logRetentionDays = 0; // 0 = forever

    public function rules(): array
    {
        return [
            [['primaryColor', 'logoBgColor', 'bubbleBotColor', 'bubbleAdminColor', 'bubbleUserColor'], 'filter', 'filter' => [self::class, 'normalizeHexColor']],
            [['companyName', 'logoText', 'primaryColor', 'logoBgColor', 'bubbleBotColor', 'bubbleAdminColor', 'bubbleUserColor', 'defaultTheme', 'operationMode', 'chatModel', 'embeddingModel', 'initialMessage', 'systemPrompt'], 'string'],
            [['enabled', 'debugMode', 'autoTrainOnSave', 'suggestionsEnabled', 'ratingsEnabled', 'loggingEnabled', 'showAdminName', 'humanHandoffEnabled', 'filterEnabled'], 'boolean'],
            [['maxContextChunks', 'logRetentionDays', 'logoAssetId', 'filterMinLength', 'filterMaxLength', 'filterRateWindowSeconds', 'filterRateMaxMessages', 'autoCloseInactiveMinutes'], 'integer'],
            [['autoCloseInactiveMinutes'], 'integer', 'min' => 0],
            [['filterMinLength'], 'integer', 'min' => 1],
            [['filterMaxLength'], 'integer', 'min' => 10],
            [['filterRateWindowSeconds', 'filterRateMaxMessages'], 'integer', 'min' => 0],
            [['filterBlockedWords'], 'safe'],
            [['maxContextChunks'], 'integer', 'min' => 1, 'max' => 20],
            [['logRetentionDays'], 'integer', 'min' => 0],
            [['minSimilarityScore'], 'number', 'min' => 0, 'max' => 1],
            [['defaultTheme'], 'in', 'range' => ['light', 'dark']],
            [['operationMode'], 'in', 'range' => ['chat', 'agent']],
            [['agentPanelWidth'], 'integer', 'min' => 280, 'max' => 900],
            [['humanHandoffMode'], 'in', 'range' => ['always', 'ai']],
            [['humanHandoffMode'], 'string'],
            [['logoText'], 'string', 'max' => 3],
            [['trainingSections', 'trainingCategoryGroups', 'trainingGlobalSets', 'suggestions', 'chatTemplates', 'initialMessages', 'companyNames', 'systemPrompts', 'suggestionsBySite'], 'safe'],
            [['companyName'], 'required'],
        ];
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
