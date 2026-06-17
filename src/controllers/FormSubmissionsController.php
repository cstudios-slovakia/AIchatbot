<?php

namespace cstudiossro\craftcschatbot\controllers;

use Craft;
use craft\web\Controller;
use cstudiossro\craftcschatbot\Plugin;
use cstudiossro\craftcschatbot\records\FormSubmissionRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP list of completed form submissions, with delivery status, manual retry and
 * delete. Mirrors the missed-chats (Contacts) screen.
 */
class FormSubmissionsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('accessPlugin-interactive-ai-assistant');
        if (!Plugin::getInstance()->getSettings()->formsEnabled) {
            throw new NotFoundHttpException('Forms are disabled.');
        }
        return true;
    }

    public function actionIndex(): Response
    {
        $req = Craft::$app->request;
        $formName = (string)$req->getQueryParam('form', '');
        $status = (string)$req->getQueryParam('status', '');
        $page = max(1, (int)$req->getQueryParam('page', 1));
        $perPage = 25;

        $forms = Plugin::getInstance()->forms;
        $result = $forms->listForAdmin($formName, $status, $page, $perPage);

        // Decode payloads for display.
        foreach ($result['rows'] as &$row) {
            $row['data'] = json_decode((string)($row['payload'] ?? ''), true) ?: [];
        }
        unset($row);

        return $this->renderTemplate('interactive-ai-assistant/submissions/index', [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'formName' => $formName,
            'status' => $status,
            'page' => $page,
            'perPage' => $perPage,
            'formOptions' => Plugin::getInstance()->getSettings()->formDefinitions(),
        ]);
    }

    public function actionView(int $id): Response
    {
        $rec = FormSubmissionRecord::findOne($id);
        if (!$rec) {
            throw new NotFoundHttpException('Submission not found.');
        }
        return $this->renderTemplate('interactive-ai-assistant/submissions/view', [
            'submission' => $rec,
            'data' => json_decode((string)$rec->payload, true) ?: [],
            'form' => Plugin::getInstance()->getSettings()->getForm($rec->formName),
        ]);
    }

    public function actionRetry(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $id = (int)Craft::$app->request->getRequiredBodyParam('id');
        $ok = Plugin::getInstance()->forms->retry($id);
        return $this->asJson(['success' => $ok]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $id = (int)Craft::$app->request->getRequiredBodyParam('id');
        $ok = Plugin::getInstance()->forms->delete($id);
        return $this->asJson(['success' => $ok]);
    }
}
