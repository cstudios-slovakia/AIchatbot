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

        $formsEnabled = Plugin::getInstance()->getSettings()->formsEnabled;
        $stats = Plugin::getInstance()->stats->summary($fromDt, $toDt);
        $suggestions = Plugin::getInstance()->stats->suggestionStats();
        $training = Plugin::getInstance()->stats->trainingSummary();

        return $this->renderTemplate('interactive-ai-assistant/dashboard/index', [
            'stats' => $stats,
            'suggestions' => $suggestions,
            'training' => $training,
            'health' => Plugin::getInstance()->training->indexHealth(),
            'openGaps' => Plugin::getInstance()->gaps->openCount(),
            'missedChatsNew' => Plugin::getInstance()->contacts->newCount(),
            'formsEnabled' => $formsEnabled,
            'newSubmissions' => $formsEnabled ? Plugin::getInstance()->forms->unreadCount() : 0,
            'from' => $fromDt->format('Y-m-d'),
            'to' => $toDt->format('Y-m-d'),
        ]);
    }

    /**
     * Re-queue every source whose content changed after it was last indexed.
     */
    public function actionRetrainStale(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-interactive-ai-assistant');
        $queued = Plugin::getInstance()->training->retrainStale();
        return $this->asJson(['success' => true, 'queued' => $queued]);
    }

    public function actionStats(): Response
    {
        $this->requirePermission('accessPlugin-interactive-ai-assistant');
        $from = Craft::$app->request->getRequiredParam('from');
        $to = Craft::$app->request->getRequiredParam('to');
        return $this->asJson(Plugin::getInstance()->stats->summary(new DateTime($from), new DateTime($to)));
    }
}
