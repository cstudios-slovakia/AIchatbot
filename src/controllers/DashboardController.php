<?php

namespace cstudiossro\craftcschatbot\controllers;

use Craft;
use craft\web\Controller;
use cstudiossro\craftcschatbot\Plugin;
use DateTime;
use yii\web\Response;

class DashboardController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requirePermission('accessPlugin-interactive-ai-assistant');
        $from = Craft::$app->request->getParam('from');
        $to = Craft::$app->request->getParam('to');
        $fromDt = $from ? new DateTime($from) : (new DateTime())->modify('-30 days');
        $toDt = $to ? new DateTime($to) : new DateTime();

        $stats = Plugin::getInstance()->stats->summary($fromDt, $toDt);
        $suggestions = Plugin::getInstance()->stats->suggestionStats();
        $training = Plugin::getInstance()->stats->trainingSummary();

        return $this->renderTemplate('interactive-ai-assistant/dashboard/index', [
            'stats' => $stats,
            'suggestions' => $suggestions,
            'training' => $training,
            'missedChatsNew' => Plugin::getInstance()->contacts->newCount(),
            'from' => $fromDt->format('Y-m-d'),
            'to' => $toDt->format('Y-m-d'),
        ]);
    }

    public function actionStats(): Response
    {
        $this->requirePermission('accessPlugin-interactive-ai-assistant');
        $from = Craft::$app->request->getRequiredParam('from');
        $to = Craft::$app->request->getRequiredParam('to');
        return $this->asJson(Plugin::getInstance()->stats->summary(new DateTime($from), new DateTime($to)));
    }
}
