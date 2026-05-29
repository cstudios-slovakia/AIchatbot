<?php

namespace cstudiossro\craftcschatbot\controllers;

use Craft;
use craft\web\Controller;
use cstudiossro\craftcschatbot\Plugin;
use cstudiossro\craftcschatbot\records\ChatSessionRecord;
use cstudiossro\craftcschatbot\services\Chat as ChatService;
use Throwable;
use yii\web\Response;

class ChatController extends Controller
{
    protected array|bool|int $allowAnonymous = ['send', 'rate', 'suggestion-click', 'config', 'poll', 'request-human', 'end', 'rate-chat', 'logo'];
    public $enableCsrfValidation = false;

    private function maybeSweep(): void
    {
        $minutes = (int)Plugin::getInstance()->getSettings()->autoCloseInactiveMinutes;
        if ($minutes <= 0) return;
        $cache = Craft::$app->cache;
        if (!$cache->add('cs-chatbot:sweep-lock', 1, 60)) return; // throttle to 60s
        try {
            Plugin::getInstance()->handoff->sweepInactive($minutes);
        } catch (\Throwable $e) {
            Craft::error($e->getMessage(), __METHOD__);
        }
    }

    private function blockIfBanned(): ?Response
    {
        $ip = Craft::$app->request->getUserIP();
        if (Plugin::getInstance()->bans->isBanned($ip)) {
            $b = Plugin::getInstance()->bans->findFor($ip);
            return $this->asJson([
                'success' => false,
                'banned' => true,
                'expiresAt' => $b['expiresAt'] ?? null,
                'error' => 'Your access has been temporarily restricted.',
            ]);
        }
        return null;
    }

    public function actionConfig(): Response
    {
        if ($blocked = $this->blockIfBanned()) return $blocked;
        $s = Plugin::getInstance()->getSettings();
        $pageUrl = (string)Craft::$app->request->getQueryParam('pageUrl', '');
        $site = \cstudiossro\craftcschatbot\models\Settings::resolveSiteFromUrl($pageUrl);
        $siteUid = $site->uid ?? null;
        $siteHosts = [];
        foreach (Craft::$app->sites->getAllSites() as $st) {
            $base = (string)$st->getBaseUrl();
            $host = parse_url($base, PHP_URL_HOST);
            if ($host) {
                $siteHosts[] = strtolower($host);
            }
        }
        $siteHosts = array_values(array_unique($siteHosts));
        return $this->asJson([
            'enabled' => Plugin::getInstance()->widgetVisibleForCurrentUser(),
            'companyName' => $s->getCompanyNameForSite($siteUid),
            'logoText' => $s->logoText,
            'logoUrl' => $this->logoUrl($s->logoAssetId),
            'primaryColor' => \cstudiossro\craftcschatbot\models\Settings::normalizeHexColor($s->primaryColor) ?: '#2563eb',
            'logoBgColor' => \cstudiossro\craftcschatbot\models\Settings::normalizeHexColor($s->logoBgColor) ?: '#1f2937',
            'bubbleBotColor' => \cstudiossro\craftcschatbot\models\Settings::normalizeHexColor($s->bubbleBotColor) ?: '',
            'bubbleAdminColor' => \cstudiossro\craftcschatbot\models\Settings::normalizeHexColor($s->bubbleAdminColor) ?: '',
            'bubbleUserColor' => \cstudiossro\craftcschatbot\models\Settings::normalizeHexColor($s->bubbleUserColor) ?: '',
            'defaultTheme' => $s->defaultTheme,
            'operationMode' => in_array($s->operationMode, ['chat', 'agent'], true) ? $s->operationMode : 'chat',
            'agentPanelWidth' => (int)$s->agentPanelWidth ?: 420,
            'initialMessage' => $s->getInitialMessageForSite($siteUid),
            'suggestionsEnabled' => $s->suggestionsEnabled,
            'suggestions' => $s->getSuggestionsForSite($siteUid),
            'ratingsEnabled' => $s->ratingsEnabled,
            'humanHandoffEnabled' => (bool)$s->humanHandoffEnabled,
            'humanHandoffMode' => in_array($s->humanHandoffMode, ['always', 'ai'], true) ? $s->humanHandoffMode : 'always',
            'siteHosts' => $siteHosts,
            'filter' => [
                'enabled' => (bool)$s->filterEnabled,
                'minLength' => (int)$s->filterMinLength,
                'maxLength' => (int)$s->filterMaxLength,
            ],
        ]);
    }

    public function actionSend(): Response
    {
        $this->requirePostRequest();
        if ($blocked = $this->blockIfBanned()) return $blocked;
        if (!Plugin::getInstance()->widgetVisibleForCurrentUser()) {
            return $this->asJson(['success' => false, 'error' => 'Chatbot disabled']);
        }
        $req = Craft::$app->request;
        $question = trim((string)$req->getBodyParam('message', ''));
        if ($question === '') {
            return $this->asJson(['success' => false, 'error' => 'Empty message']);
        }
        $token = $req->getBodyParam('sessionToken');
        $pageUrl = $req->getBodyParam('pageUrl');

        $check = Plugin::getInstance()->filter->check(
            $question,
            is_string($token) ? $token : null,
            $req->getUserIP()
        );
        if (!$check['ok']) {
            return $this->asJson([
                'success' => false,
                'filtered' => true,
                'reason' => $check['reason'] ?? 'invalid',
                'error' => $check['message'] ?? 'Message rejected.',
            ]);
        }

        try {
            $result = Plugin::getInstance()->chat->ask($question, is_string($token) ? $token : null, is_string($pageUrl) ? $pageUrl : null);
        } catch (Throwable $e) {
            Craft::error($e->getMessage(), __METHOD__);
            return $this->asJson(['success' => false, 'error' => 'Chat error: ' . $e->getMessage()]);
        }
        return $this->asJson(['success' => true] + $result);
    }

    public function actionRate(): Response
    {
        $this->requirePostRequest();
        $messageId = (int)Craft::$app->request->getRequiredBodyParam('messageId');
        $rating = (int)Craft::$app->request->getRequiredBodyParam('rating'); // 1, -1, or 0
        $ok = Plugin::getInstance()->chat->rateMessage($messageId, $rating);
        return $this->asJson(['success' => $ok]);
    }

    public function actionSuggestionClick(): Response
    {
        $this->requirePostRequest();
        $s = (string)Craft::$app->request->getRequiredBodyParam('suggestion');
        Plugin::getInstance()->chat->recordSuggestionClick($s);
        return $this->asJson(['success' => true]);
    }

    public function actionPoll(): Response
    {
        if ($blocked = $this->blockIfBanned()) return $blocked;
        $this->maybeSweep();
        $req = Craft::$app->request;
        $token = (string)$req->getQueryParam('sessionToken', $req->getBodyParam('sessionToken', ''));
        $afterId = (int)$req->getQueryParam('afterId', $req->getBodyParam('afterId', 0));
        if ($token === '') {
            return $this->asJson(['success' => false, 'error' => 'No session']);
        }
        $session = ChatSessionRecord::findOne(['sessionToken' => $token]);
        if (!$session) {
            return $this->asJson(['success' => false, 'error' => 'Unknown session']);
        }
        $handoff = Plugin::getInstance()->handoff;
        $messages = $handoff->messagesAfter((int)$session->id, $afterId);
        // mark read on user side
        $handoff->markUserRead($session);

        $adminName = null;
        if ($session->handoffAdminId && Plugin::getInstance()->getSettings()->showAdminName) {
            $u = Craft::$app->users->getUserById((int)$session->handoffAdminId);
            if ($u) {
                $adminName = $u->fullName ?: $u->username;
            }
        }
        return $this->asJson([
            'success' => true,
            'sessionId' => (int)$session->id,
            'shortId' => $this->shortId($session->id, $session->sessionToken),
            'handoffStatus' => $session->handoffStatus,
            'adminName' => $adminName,
            'chatEnded' => (bool)$session->chatEndedAt,
            'chatRating' => $session->chatRating !== null ? (int)$session->chatRating : null,
            'messages' => array_map(function ($m) {
                return [
                    'id' => (int)$m['id'],
                    'role' => $m['role'],
                    'content' => $m['content'],
                    'rating' => isset($m['rating']) && $m['rating'] !== null ? (int)$m['rating'] : null,
                    'offerHuman' => !empty($m['offerHuman']),
                    'dateCreated' => $m['dateCreated'],
                    'time' => ChatService::formatLocalTime($m['dateCreated']),
                    'dateLocal' => ChatService::formatLocalTime($m['dateCreated'], 'Y-m-d H:i:s'),
                ];
            }, $messages),
        ]);
    }

    public function actionEnd(): Response
    {
        $this->requirePostRequest();
        $token = (string)Craft::$app->request->getBodyParam('sessionToken', '');
        if ($token === '') {
            return $this->asJson(['success' => false, 'error' => 'No session']);
        }
        $ok = Plugin::getInstance()->chat->endChat($token);
        return $this->asJson(['success' => $ok]);
    }

    public function actionRateChat(): Response
    {
        $this->requirePostRequest();
        $token = (string)Craft::$app->request->getBodyParam('sessionToken', '');
        $rating = (int)Craft::$app->request->getBodyParam('rating', 0);
        if ($token === '') {
            return $this->asJson(['success' => false, 'error' => 'No session']);
        }
        $ok = Plugin::getInstance()->chat->rateChat($token, $rating);
        return $this->asJson(['success' => $ok]);
    }

    public function actionRequestHuman(): Response
    {
        $this->requirePostRequest();
        if ($blocked = $this->blockIfBanned()) return $blocked;
        if (!Plugin::getInstance()->getSettings()->humanHandoffEnabled) {
            return $this->asJson(['success' => false, 'error' => 'Human assistance is not available.']);
        }
        $req = Craft::$app->request;
        $token = (string)$req->getBodyParam('sessionToken', '');
        $pageUrl = $req->getBodyParam('pageUrl');
        $session = null;
        if ($token !== '') {
            $session = ChatSessionRecord::findOne(['sessionToken' => $token]);
        }
        if (!$session) {
            // create an empty session so we have somewhere to anchor the request
            $session = Plugin::getInstance()->chat->getOrCreateSession(null, is_string($pageUrl) ? $pageUrl : null);
        }
        Plugin::getInstance()->handoff->request($session);
        return $this->asJson([
            'success' => true,
            'sessionToken' => $session->sessionToken,
            'sessionId' => (int)$session->id,
            'shortId' => $this->shortId((int)$session->id, (string)$session->sessionToken),
            'handoffStatus' => $session->handoffStatus,
        ]);
    }

    public function actionLogo(): Response
    {
        $s = Plugin::getInstance()->getSettings();
        $assetId = (int)($s->logoAssetId ?? 0);
        if ($assetId <= 0) {
            throw new \yii\web\NotFoundHttpException();
        }
        $asset = Craft::$app->assets->getAssetById($assetId);
        if (!$asset) {
            throw new \yii\web\NotFoundHttpException();
        }
        $mime = $asset->getMimeType() ?: 'application/octet-stream';
        $stream = $asset->getStream();
        if (!$stream) {
            throw new \yii\web\NotFoundHttpException();
        }
        Craft::$app->response->headers->set('Content-Type', $mime);
        Craft::$app->response->headers->set('Cache-Control', 'public, max-age=3600');
        Craft::$app->response->headers->set('Content-Disposition', 'inline; filename="' . addslashes($asset->filename) . '"');
        Craft::$app->response->format = \yii\web\Response::FORMAT_RAW;
        Craft::$app->response->stream = $stream;
        return Craft::$app->response;
    }

    private function shortId(int $id, string $token): string
    {
        // human-friendly: zero-padded id + first 4 chars of token (uppercased)
        return sprintf('%05d-%s', $id, strtoupper(substr($token, 0, 4)));
    }

    private function logoUrl(?int $assetId): ?string
    {
        if (!$assetId) {
            return null;
        }
        $asset = Craft::$app->assets->getAssetById($assetId);
        if (!$asset) {
            return null;
        }
        $url = $asset->getUrl();
        if ($url) {
            return $url;
        }
        // Asset has no public URL (no public volume) → serve via proxy with cache buster
        return \craft\helpers\UrlHelper::actionUrl('interactive-ai-assistant/chat/logo', ['v' => $asset->dateUpdated ? $asset->dateUpdated->getTimestamp() : $asset->id]);
    }
}
