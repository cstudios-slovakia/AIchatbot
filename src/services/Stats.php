<?php

namespace cstudiossro\craftcschatbot\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use yii\base\Component;

class Stats extends Component
{
    /**
     * @return array{
     *   totalConversations:int,
     *   totalMessages:int,
     *   avgResponseTime:float,
     *   avgPerDay:float,
     *   positive:int,
     *   negative:int,
     *   activity:array<int, array{date:string, conversations:int, messages:int}>
     * }
     */
    public function summary(?DateTime $from = null, ?DateTime $to = null): array
    {
        $from ??= (new DateTime())->modify('-30 days');
        $to ??= new DateTime();

        $fromStr = Db::prepareDateForDb($from);
        $toStr = Db::prepareDateForDb($to);

        $convCount = (int)(new Query())
            ->from('{{%chatbot_sessions}}')
            ->where(['between', 'dateCreated', $fromStr, $toStr])
            ->count();

        $msgCount = (int)(new Query())
            ->from('{{%chatbot_messages}}')
            ->where(['between', 'dateCreated', $fromStr, $toStr])
            ->count();

        $avgResp = (float)(new Query())
            ->from('{{%chatbot_messages}}')
            ->where(['role' => 'bot'])
            ->andWhere(['between', 'dateCreated', $fromStr, $toStr])
            ->average('responseTime');

        $positive = (int)(new Query())
            ->from('{{%chatbot_messages}}')
            ->where(['rating' => 1])
            ->andWhere(['between', 'dateCreated', $fromStr, $toStr])
            ->count();
        $positive += (int)(new Query())
            ->from('{{%chatbot_sessions}}')
            ->where(['chatRating' => 1])
            ->andWhere(['between', 'dateCreated', $fromStr, $toStr])
            ->count();

        $negative = (int)(new Query())
            ->from('{{%chatbot_messages}}')
            ->where(['rating' => -1])
            ->andWhere(['between', 'dateCreated', $fromStr, $toStr])
            ->count();
        $negative += (int)(new Query())
            ->from('{{%chatbot_sessions}}')
            ->where(['chatRating' => -1])
            ->andWhere(['between', 'dateCreated', $fromStr, $toStr])
            ->count();

        $days = max(1, (int)$from->diff($to)->days);
        $avgPerDay = round($convCount / $days, 2);

        $activity = $this->activity($from, $to);
        $activeNow = $this->activeNow();

        return [
            'totalConversations' => $convCount,
            'totalMessages' => $msgCount,
            'avgResponseTime' => round($avgResp, 3),
            'avgPerDay' => $avgPerDay,
            'positive' => $positive,
            'negative' => $negative,
            'activity' => $activity,
            'activeNow' => $activeNow,
        ];
    }

    /**
     * Sessions considered "active right now": not ended, with any message in the last 15 minutes.
     * Includes both bot-only conversations and handoff sessions.
     */
    public function activeNow(int $windowMinutes = 15): int
    {
        $since = gmdate('Y-m-d H:i:s', time() - ($windowMinutes * 60));
        return (int)(new Query())
            ->from(['s' => '{{%chatbot_sessions}}'])
            ->where(['s.chatEndedAt' => null])
            ->andWhere(['exists', (new Query())
                ->from('{{%chatbot_messages}} m')
                ->where('m.sessionId = s.id')
                ->andWhere(['>=', 'm.dateCreated', $since])
            ])
            ->count();
    }

    /**
     * @return array<int, array{date:string, conversations:int, messages:int}>
     */
    public function activity(DateTime $from, DateTime $to): array
    {
        $db = Craft::$app->db;
        $dateExpr = $db->getDriverName() === 'pgsql'
            ? "to_char(\"dateCreated\", 'YYYY-MM-DD')"
            : "DATE(`dateCreated`)";

        $conv = (new Query())
            ->select(["d" => new \yii\db\Expression($dateExpr), 'c' => 'COUNT(*)'])
            ->from('{{%chatbot_sessions}}')
            ->where(['between', 'dateCreated', Db::prepareDateForDb($from), Db::prepareDateForDb($to)])
            ->groupBy('d')
            ->all();

        $msgs = (new Query())
            ->select(["d" => new \yii\db\Expression($dateExpr), 'c' => 'COUNT(*)'])
            ->from('{{%chatbot_messages}}')
            ->where(['between', 'dateCreated', Db::prepareDateForDb($from), Db::prepareDateForDb($to)])
            ->groupBy('d')
            ->all();

        $byDate = [];
        $cursor = clone $from;
        while ($cursor <= $to) {
            $key = $cursor->format('Y-m-d');
            $byDate[$key] = ['date' => $key, 'conversations' => 0, 'messages' => 0];
            $cursor = $cursor->modify('+1 day');
        }
        foreach ($conv as $r) {
            $key = substr((string)$r['d'], 0, 10);
            if (isset($byDate[$key])) {
                $byDate[$key]['conversations'] = (int)$r['c'];
            }
        }
        foreach ($msgs as $r) {
            $key = substr((string)$r['d'], 0, 10);
            if (isset($byDate[$key])) {
                $byDate[$key]['messages'] = (int)$r['c'];
            }
        }
        return array_values($byDate);
    }

    public function suggestionStats(): array
    {
        return (new Query())
            ->select(['suggestion', 'clicks', 'lastClickedAt'])
            ->from('{{%chatbot_suggestion_stats}}')
            ->orderBy(['clicks' => SORT_DESC])
            ->all();
    }

    public function trainingSummary(): array
    {
        $entryCount = (int)(new Query())->from('{{%chatbot_training_entries}}')->count();
        $entryChunks = (int)(new Query())->from('{{%chatbot_chunks}}')->where(['sourceType' => 'entry'])->count();
        $fileCount = (int)(new Query())->from('{{%chatbot_training_files}}')->count();
        $fileChunks = (int)(new Query())->from('{{%chatbot_chunks}}')->where(['sourceType' => 'file'])->count();
        $urlCount = (int)(new Query())->from('{{%chatbot_training_urls}}')->count();
        $urlChunks = (int)(new Query())->from('{{%chatbot_chunks}}')->where(['sourceType' => 'url'])->count();
        $qaCount = (int)(new Query())->from('{{%chatbot_training_qa}}')->count();
        $qaChunks = (int)(new Query())->from('{{%chatbot_chunks}}')->where(['sourceType' => 'qa'])->count();

        $lastEntry = (new Query())->select('MAX(lastTrainedAt)')->from('{{%chatbot_training_entries}}')->scalar();
        $lastFile = (new Query())->select('MAX(lastTrainedAt)')->from('{{%chatbot_training_files}}')->scalar();

        return [
            'entries' => ['count' => $entryCount, 'chunks' => $entryChunks, 'lastTrainedAt' => $lastEntry],
            'files' => ['count' => $fileCount, 'chunks' => $fileChunks, 'lastTrainedAt' => $lastFile],
            'urls' => ['count' => $urlCount, 'chunks' => $urlChunks],
            'qa' => ['count' => $qaCount, 'chunks' => $qaChunks],
        ];
    }
}
