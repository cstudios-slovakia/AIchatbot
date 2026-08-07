<?php

namespace cstudiossro\craftcschatbot\controllers;

use Craft;
use craft\web\Controller;
use cstudiossro\craftcschatbot\Plugin;
use yii\web\Response;

/**
 * The questions the assistant handled badly, and answering them.
 */
class GapsController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requirePermission('accessPlugin-interactive-ai-assistant');
        $request = Craft::$app->request;
        $page = max(1, (int)$request->getParam('page', 1));
        $includeResolved = (bool)$request->getParam('resolved', false);

        $gaps = Plugin::getInstance()->gaps;
        $result = $gaps->list($page, 25, $includeResolved);

        return $this->renderTemplate('interactive-ai-assistant/training/gaps', [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => 25,
            'includeResolved' => $includeResolved,
            'common' => $gaps->commonQuestions(),
            'sites' => Craft::$app->sites->getAllSites(),
        ]);
    }

    public function actionResolve(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-interactive-ai-assistant');
        $request = Craft::$app->request;
        $ok = Plugin::getInstance()->gaps->resolve(
            (int)$request->getRequiredBodyParam('id'),
            (bool)$request->getBodyParam('resolved', true),
        );
        return $this->asJson(['success' => $ok]);
    }

    public function actionAnswer(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-interactive-ai-assistant');
        $request = Craft::$app->request;
        $siteId = (int)$request->getBodyParam('siteId', 0);

        $rec = Plugin::getInstance()->gaps->answerWithQa(
            (int)$request->getRequiredBodyParam('id'),
            (string)$request->getRequiredBodyParam('question'),
            (string)$request->getRequiredBodyParam('answer'),
            $siteId > 0 ? $siteId : null,
            (bool)$request->getBodyParam('translate', false),
        );
        if (!$rec) {
            return $this->asJson(['success' => false, 'error' => 'Give both a question and an answer.']);
        }
        return $this->asJson(['success' => true, 'qaId' => (int)$rec->id]);
    }
}
