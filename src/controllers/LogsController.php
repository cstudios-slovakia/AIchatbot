<?php

namespace cstudiossro\craftcschatbot\controllers;

use Craft;
use craft\db\Query;
use craft\web\Controller;
use cstudiossro\craftcschatbot\Plugin;
use cstudiossro\craftcschatbot\records\ChatMessageRecord;
use cstudiossro\craftcschatbot\records\ChatSessionRecord;
use cstudiossro\craftcschatbot\records\TrainingQaRecord;
use yii\web\Response;

class LogsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('accessPlugin-interactive-ai-assistant');
        return true;
    }

    public function actionIndex(): Response
    {
        $req = Craft::$app->request;
        $search = trim((string)$req->getQueryParam('q', ''));
        $rating = (string)$req->getQueryParam('rating', '');
        $confidence = (string)$req->getQueryParam('confidence', '');
        $starredOnly = (bool)$req->getQueryParam('starred', false);
        $activeOnly = (bool)$req->getQueryParam('active', false);
        $page = max(1, (int)$req->getQueryParam('page', 1));
        $perPage = 25;

        $query = (new Query())
            ->select([
                's.id', 's.sessionToken', 's.pageUrl', 's.messageCount', 's.ratingPositive',
                's.ratingNegative', 's.chatRating', 's.ip', 's.starred', 's.adminNotes', 's.chatEndedAt', 's.dateCreated',
                'maxConf' => '(SELECT MAX(confidence) FROM {{%chatbot_messages}} WHERE sessionId = s.id AND role = \'bot\')',
            ])
            ->from(['s' => '{{%chatbot_sessions}}']);

        if ($search !== '') {
            // accept shortId formats: "00010-LYYN", "10-LYYN", "10", "LYYN", or raw token substring
            if (preg_match('/^(\d+)-([A-Za-z0-9]+)$/', $search, $m)) {
                $query->andWhere([
                    'and',
                    ['s.id' => (int)$m[1]],
                    ['like', 's.sessionToken', strtolower($m[2]) . '%', false],
                ]);
            } elseif (preg_match('/^\d+$/', $search)) {
                $query->andWhere(['s.id' => (int)$search]);
            } else {
                $query->andWhere([
                    'or',
                    ['like', 's.sessionToken', strtolower($search)],
                    ['like', 's.adminNotes', $search],
                    ['exists', (new Query())
                        ->from('{{%chatbot_messages}} m')
                        ->where('m.sessionId = s.id')
                        ->andWhere(['like', 'm.content', $search]),
                    ],
                ]);
            }
        }
        if ($rating === 'pos') {
            $query->andWhere([
                'or',
                ['>', 's.ratingPositive', 0],
                ['s.chatRating' => 1],
            ]);
        } elseif ($rating === 'neg') {
            $query->andWhere([
                'or',
                ['>', 's.ratingNegative', 0],
                ['s.chatRating' => -1],
            ]);
        }
        if ($starredOnly) {
            $query->andWhere(['s.starred' => true]);
        }
        if ($activeOnly) {
            $since = gmdate('Y-m-d H:i:s', time() - (15 * 60));
            $query->andWhere(['s.chatEndedAt' => null]);
            $query->andWhere(['exists', (new Query())
                ->from('{{%chatbot_messages}} m')
                ->where('m.sessionId = s.id')
                ->andWhere(['>=', 'm.dateCreated', $since])
            ]);
        }

        $rows = $query
            ->orderBy(['s.dateCreated' => SORT_DESC])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        // confidence filter in PHP (cheaper than complex SQL across drivers)
        if ($confidence !== '') {
            $rows = array_values(array_filter($rows, function ($r) use ($confidence) {
                $c = $r['maxConf'] !== null ? (float)$r['maxConf'] : null;
                return match ($confidence) {
                    'high' => $c !== null && $c >= 0.80,
                    'mid' => $c !== null && $c >= 0.60 && $c < 0.80,
                    'low' => $c !== null && $c < 0.60,
                    'none' => $c === null,
                    default => true,
                };
            }));
        }

        $total = (int)(new Query())->from('{{%chatbot_sessions}}')->count();

        return $this->renderTemplate('interactive-ai-assistant/logs/index', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'q' => $search,
            'rating' => $rating,
            'confidence' => $confidence,
            'starredOnly' => $starredOnly,
            'activeOnly' => $activeOnly,
        ]);
    }

    public function actionSaveNote(): Response
    {
        $this->requirePostRequest();
        $id = (int)Craft::$app->request->getRequiredBodyParam('id');
        $note = (string)Craft::$app->request->getBodyParam('note', '');
        $note = trim($note);
        $session = ChatSessionRecord::findOne($id);
        if (!$session) {
            return $this->asJson(['success' => false, 'error' => 'Session not found']);
        }
        $session->adminNotes = $note !== '' ? mb_substr($note, 0, 10000) : null;
        $session->save(false);
        return $this->asJson(['success' => true, 'note' => $session->adminNotes]);
    }

    public function actionToggleStar(): Response
    {
        $this->requirePostRequest();
        $id = (int)Craft::$app->request->getRequiredBodyParam('id');
        $starred = Plugin::getInstance()->handoff->toggleStar($id);
        return $this->asJson(['success' => $starred !== null, 'starred' => (bool)$starred]);
    }

    public function actionSession(int $id): Response
    {
        $session = ChatSessionRecord::findOne($id);
        if (!$session) {
            throw new \yii\web\NotFoundHttpException();
        }
        $messages = ChatMessageRecord::find()
            ->where(['sessionId' => $id])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        return $this->renderTemplate('interactive-ai-assistant/logs/session', [
            'session' => $session,
            'messages' => $messages,
        ]);
    }

    public function actionUseAsQa(): Response
    {
        $this->requirePostRequest();
        $messageId = (int)Craft::$app->request->getRequiredBodyParam('messageId');
        $msg = ChatMessageRecord::findOne($messageId);
        if (!$msg || $msg->role !== 'bot') {
            return $this->asJson(['success' => false, 'error' => 'Invalid message']);
        }
        // grab preceding user message in the session
        $userMsg = ChatMessageRecord::find()
            ->where(['sessionId' => $msg->sessionId, 'role' => 'user'])
            ->andWhere(['<', 'id', $msg->id])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        if (!$userMsg) {
            return $this->asJson(['success' => false, 'error' => 'No question found']);
        }
        $qa = new TrainingQaRecord();
        $qa->question = $userMsg->content;
        $qa->answer = $msg->content;
        $qa->source = 'log';
        $qa->active = true;
        $qa->sourceMessageId = $msg->id;
        $qa->save(false);

        $msg->usedAsQa = true;
        $msg->save(false);

        Plugin::getInstance()->training->trainQa((int)$qa->id);

        return $this->asJson(['success' => true, 'qaId' => (int)$qa->id]);
    }
}
