<?php

namespace cstudiossro\craftcschatbot\controllers;

use Craft;
use craft\web\Controller;
use cstudiossro\craftcschatbot\Plugin;
use yii\web\Response;

class ContactsController extends Controller
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
        $status = (string)$req->getQueryParam('status', '');
        $page = max(1, (int)$req->getQueryParam('page', 1));
        $perPage = 25;

        $result = Plugin::getInstance()->contacts->listForAdmin($status, $page, $perPage);

        return $this->renderTemplate('interactive-ai-assistant/missed-chats/index', [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'status' => $status,
            'page' => $page,
            'perPage' => $perPage,
            'newCount' => Plugin::getInstance()->contacts->newCount(),
        ]);
    }

    public function actionResolve(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $req = Craft::$app->request;
        $id = (int)$req->getRequiredBodyParam('id');
        $resolved = (bool)$req->getBodyParam('resolved', true);
        $ok = Plugin::getInstance()->contacts->resolve($id, (int)Craft::$app->user->id, $resolved);
        return $this->asJson(['success' => $ok, 'resolved' => $resolved]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $id = (int)Craft::$app->request->getRequiredBodyParam('id');
        $ok = Plugin::getInstance()->contacts->softDelete($id);
        return $this->asJson(['success' => $ok]);
    }

    public function actionRestore(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $id = (int)Craft::$app->request->getRequiredBodyParam('id');
        $ok = Plugin::getInstance()->contacts->restore($id);
        return $this->asJson(['success' => $ok]);
    }

    public function actionDestroy(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $id = (int)Craft::$app->request->getRequiredBodyParam('id');
        $ok = Plugin::getInstance()->contacts->delete($id);
        return $this->asJson(['success' => $ok]);
    }
}
