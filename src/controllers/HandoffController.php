<?php

namespace cstudiossro\craftcschatbot\controllers;

use Craft;
use craft\db\Query;
use craft\web\Controller;
use cstudiossro\craftcschatbot\Plugin;
use cstudiossro\craftcschatbot\records\ChatSessionRecord;
use cstudiossro\craftcschatbot\services\Chat as ChatService;
use yii\web\Response;

class HandoffController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requirePermission('accessPlugin-interactive-ai-assistant');
        $selectedId = (int)Craft::$app->request->getQueryParam('id', 0);
        $templates = array_values(array_filter(Plugin::getInstance()->getSettings()->chatTemplates ?? []));
        return $this->renderTemplate('interactive-ai-assistant/live-chat/index', [
            'selectedSessionId' => $selectedId,
            'chatTemplates' => $templates,
        ]);
    }

    public function actionPoll(): Response
    {
        $this->requirePermission('accessPlugin-interactive-ai-assistant');
        $this->requireAcceptsJson();
        $lists = Plugin::getInstance()->handoff->listForAdmin();
        return $this->asJson([
            'success' => true,
            'waiting' => $lists['waiting'],
            'active' => $lists['active'],
        ]);
    }

    public function actionBadgeCount(): Response
    {
        $this->requirePermission('accessPlugin-interactive-ai-assistant');
        $this->requireAcceptsJson();
        $this->maybeSweep();
        $userId = (int)(Craft::$app->user->id ?? 0);
        $counts = Plugin::getInstance()->handoff->badgeCount($userId);
        $counts['sessions'] = Plugin::getInstance()->handoff->unreadBySessionForBadge();
        $counts['leads'] = Plugin::getInstance()->contacts->newCount();
        return $this->asJson(['success' => true] + $counts);
    }

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

    public function actionPollSession(): Response
    {
        $this->requirePermission('accessPlugin-interactive-ai-assistant');
        $this->requireAcceptsJson();
        $req = Craft::$app->request;
        $sessionId = (int)$req->getQueryParam('id', 0);
        $afterId = (int)$req->getQueryParam('afterId', 0);
        if ($sessionId <= 0) {
            return $this->asJson(['success' => false, 'error' => 'No session id']);
        }
        $session = ChatSessionRecord::findOne($sessionId);
        if (!$session) {
            return $this->asJson(['success' => false, 'error' => 'Unknown session']);
        }
        $handoff = Plugin::getInstance()->handoff;
        $msgs = $handoff->messagesAfter($sessionId, $afterId);
        $handoff->markAdminRead($sessionId, (int)Craft::$app->user->id);
        return $this->asJson([
            'success' => true,
            'session' => [
                'id' => (int)$session->id,
                'shortId' => sprintf('%05d-%s', (int)$session->id, strtoupper(substr((string)$session->sessionToken, 0, 4))),
                'sessionToken' => $session->sessionToken,
                'pageUrl' => $session->pageUrl,
                'ip' => $session->ip,
                'handoffStatus' => $session->handoffStatus,
                'handoffAdminId' => $session->handoffAdminId ? (int)$session->handoffAdminId : null,
                'chatEnded' => (bool)$session->chatEndedAt,
                'chatRating' => $session->chatRating !== null ? (int)$session->chatRating : null,
            ],
            'messages' => array_map(function ($m) {
                return [
                    'id' => (int)$m['id'],
                    'role' => $m['role'],
                    'content' => $m['content'],
                    'adminId' => $m['adminId'] ? (int)$m['adminId'] : null,
                    'dateCreated' => $m['dateCreated'],
                    'time' => ChatService::formatLocalTime($m['dateCreated']),
                    'dateLocal' => ChatService::formatLocalTime($m['dateCreated'], 'Y-m-d H:i:s'),
                ];
            }, $msgs),
        ]);
    }

    public function actionHistory(): Response
    {
        $this->requirePermission('accessPlugin-interactive-ai-assistant');
        $this->requireAcceptsJson();
        $sessionId = (int)Craft::$app->request->getQueryParam('id', 0);
        if ($sessionId <= 0) {
            return $this->asJson(['success' => false, 'error' => 'No session id']);
        }
        $rows = (new Query())
            ->select(['id', 'role', 'content', 'adminId', 'dateCreated'])
            ->from('{{%chatbot_messages}}')
            ->where(['sessionId' => $sessionId])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        return $this->asJson([
            'success' => true,
            'messages' => array_map(function ($m) {
                return [
                    'id' => (int)$m['id'],
                    'role' => $m['role'],
                    'content' => $m['content'],
                    'adminId' => $m['adminId'] ? (int)$m['adminId'] : null,
                    'dateCreated' => $m['dateCreated'],
                    'time' => ChatService::formatLocalTime($m['dateCreated']),
                    'dateLocal' => ChatService::formatLocalTime($m['dateCreated'], 'Y-m-d H:i:s'),
                ];
            }, $rows),
        ]);
    }

    public function actionClaim(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-interactive-ai-assistant');
        $sessionId = (int)Craft::$app->request->getRequiredBodyParam('id');
        $session = Plugin::getInstance()->handoff->claim($sessionId, (int)Craft::$app->user->id);
        if (!$session) {
            return $this->asJson(['success' => false, 'error' => 'Could not claim session']);
        }
        return $this->asJson(['success' => true, 'sessionId' => (int)$session->id]);
    }

    public function actionReply(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-interactive-ai-assistant');
        $req = Craft::$app->request;
        $sessionId = (int)$req->getRequiredBodyParam('id');
        $text = (string)$req->getRequiredBodyParam('message');
        $msg = Plugin::getInstance()->handoff->reply($sessionId, (int)Craft::$app->user->id, $text);
        if (!$msg) {
            return $this->asJson(['success' => false, 'error' => 'Could not send (not claimed or empty)']);
        }
        return $this->asJson([
            'success' => true,
            'message' => [
                'id' => (int)$msg->id,
                'role' => 'admin',
                'content' => $msg->content,
                'dateCreated' => $msg->dateCreated,
                'time' => ChatService::formatLocalTime($msg->dateCreated),
                'dateLocal' => ChatService::formatLocalTime($msg->dateCreated, 'Y-m-d H:i:s'),
            ],
        ]);
    }

    public function actionToggleStar(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-interactive-ai-assistant');
        $sessionId = (int)Craft::$app->request->getRequiredBodyParam('id');
        $starred = Plugin::getInstance()->handoff->toggleStar($sessionId);
        return $this->asJson(['success' => $starred !== null, 'starred' => (bool)$starred]);
    }

    public function actionEnd(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-interactive-ai-assistant');
        $req = Craft::$app->request;
        $sessionId = (int)$req->getRequiredBodyParam('id');
        $adminId = (int)Craft::$app->user->id;
        $plugin = Plugin::getInstance();

        $banToken = trim((string)$req->getBodyParam('banDuration', ''));
        $reason = (string)$req->getBodyParam('banReason', '');

        $banned = null;
        $banError = null;
        if ($banToken !== '' && $banToken !== '0') {
            $session = ChatSessionRecord::findOne($sessionId);
            $ip = $session?->ip;
            if (!$ip) {
                $banError = 'No IP recorded for this session — cannot ban.';
            } else {
                $ttl = \cstudiossro\craftcschatbot\services\Bans::parseDuration($banToken);
                if ($ttl === 0) {
                    $banError = 'Invalid ban duration.';
                } else {
                    $rec = $plugin->bans->ban($ip, $ttl, $reason ?: null, $adminId);
                    if ($rec) {
                        $banned = ['ip' => $rec->ip, 'expiresAt' => $rec->expiresAt];
                    } else {
                        $banError = 'Ban failed to save.';
                    }
                }
            }
        }

        $ok = $plugin->handoff->end($sessionId, $adminId);
        return $this->asJson(['success' => $ok, 'banned' => $banned, 'banError' => $banError]);
    }
}
