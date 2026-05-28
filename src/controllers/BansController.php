<?php

namespace cstudiossro\craftcschatbot\controllers;

use Craft;
use craft\web\Controller;
use cstudiossro\craftcschatbot\Plugin;
use cstudiossro\craftcschatbot\services\Bans as BansService;
use yii\web\Response;

class BansController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requirePermission('accessPlugin-_cs-chatbot');
        $bans = Plugin::getInstance()->bans->listActive();
        $admins = [];
        foreach ($bans as &$b) {
            $aid = (int)($b['bannedByAdminId'] ?? 0);
            if ($aid && !isset($admins[$aid])) {
                $u = Craft::$app->users->getUserById($aid);
                $admins[$aid] = $u ? ($u->fullName ?: $u->username) : ('User #' . $aid);
            }
            $b['bannedByName'] = $aid ? $admins[$aid] : null;
        }
        return $this->renderTemplate('_cs-chatbot/bans/index', [
            'bans' => $bans,
        ]);
    }

    public function actionCreate(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-_cs-chatbot');
        $req = Craft::$app->request;
        $ip = trim((string)$req->getRequiredBodyParam('ip'));
        $duration = trim((string)$req->getBodyParam('duration', 'forever'));
        $reason = trim((string)$req->getBodyParam('reason', ''));
        if ($ip === '') {
            Craft::$app->session->setError('IP required');
            return $this->redirect('_cs-chatbot/bans');
        }
        $ttl = BansService::parseDuration($duration);
        if ($ttl === 0) {
            Craft::$app->session->setError('Invalid duration');
            return $this->redirect('_cs-chatbot/bans');
        }
        Plugin::getInstance()->bans->ban($ip, $ttl, $reason ?: null, (int)Craft::$app->user->id);
        Craft::$app->session->setNotice('IP banned');
        return $this->redirect('_cs-chatbot/bans');
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-_cs-chatbot');
        $id = (int)Craft::$app->request->getRequiredBodyParam('id');
        Plugin::getInstance()->bans->unban($id);
        return $this->asJson(['success' => true]);
    }
}
