<?php

namespace cstudiossro\craftcschatbot\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use cstudiossro\craftcschatbot\Plugin;
use cstudiossro\craftcschatbot\records\ChatMessageRecord;
use cstudiossro\craftcschatbot\records\TrainingQaRecord;
use DateTime;
use yii\base\Component;

/**
 * The questions the assistant handled badly, and the way to fix them.
 *
 * Everything else in this plugin reports what the assistant *did*: how many
 * conversations, how fast, how many thumbs up. None of that says which
 * questions it could not answer — and an assistant that answers confidently
 * from nothing reads exactly like one that answers well. This turns the
 * retrieval facts recorded per turn into a list of specific questions somebody
 * can write an answer for.
 */
class Gaps extends Component
{
    /** Why a turn is on the list. Ordered worst first. */
    public const REASON_NO_CONTEXT = 'no_context';
    public const REASON_RATED_DOWN = 'rated_down';
    public const REASON_LOW_CONFIDENCE = 'low_confidence';
    public const REASON_OFFERED_HUMAN = 'offered_human';

    /**
     * Answered turns worth a second look, newest first.
     *
     * @param bool $includeResolved show turns already dealt with
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function list(int $page = 1, int $perPage = 25, bool $includeResolved = false): array
    {
        $query = $this->baseQuery($includeResolved);
        $total = (int)(clone $query)->count();

        $rows = (clone $query)
            ->select([
                'id' => 'm.id',
                'sessionId' => 'm.sessionId',
                'question' => 'q.content',
                'retrievalQuery' => 'm.retrievalQuery',
                'answer' => 'm.content',
                'confidence' => 'm.confidence',
                'contextChunks' => 'm.contextChunks',
                'rating' => 'm.rating',
                'offerHuman' => 'm.offerHuman',
                'gapResolvedAt' => 'm.gapResolvedAt',
                'dateCreated' => 'm.dateCreated',
            ])
            ->orderBy(['m.id' => SORT_DESC])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        foreach ($rows as &$row) {
            $row['reason'] = $this->reasonFor($row);
        }

        return ['rows' => $rows, 'total' => $total];
    }

    public function openCount(): int
    {
        return (int)$this->baseQuery(false)->count();
    }

    /**
     * Bot turns that went badly, joined to the visitor message that prompted
     * them. The user message is the row immediately before the bot's in the
     * same session, which is what the conversation loop guarantees.
     */
    private function baseQuery(bool $includeResolved): Query
    {
        $floor = (float)Plugin::getInstance()->getSettings()->minSimilarityScore;

        $query = (new Query())
            ->from(['m' => '{{%chatbot_messages}}'])
            ->leftJoin(['q' => '{{%chatbot_messages}}'], [
                'and',
                'q.sessionId = m.sessionId',
                'q.role = \'user\'',
                'q.id < m.id',
                ['not exists', (new Query())
                    ->from(['between' => '{{%chatbot_messages}}'])
                    ->where('between.sessionId = m.sessionId')
                    ->andWhere('between.role = \'user\'')
                    ->andWhere('between.id > q.id')
                    ->andWhere('between.id < m.id'),
                ],
            ])
            ->where(['m.role' => 'bot'])
            // Turns where retrieval never ran carry no signal about the index.
            ->andWhere(['not', ['m.contextChunks' => null]])
            ->andWhere([
                'or',
                ['m.contextChunks' => 0],
                ['m.rating' => -1],
                ['m.offerHuman' => true],
                ['<', 'm.confidence', $floor],
            ]);

        if (!$includeResolved) {
            $query->andWhere(['m.gapResolvedAt' => null]);
        }
        return $query;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function reasonFor(array $row): string
    {
        if ((int)$row['contextChunks'] === 0) {
            return self::REASON_NO_CONTEXT;
        }
        if ((int)$row['rating'] === -1) {
            return self::REASON_RATED_DOWN;
        }
        $floor = (float)Plugin::getInstance()->getSettings()->minSimilarityScore;
        if ((float)$row['confidence'] < $floor) {
            return self::REASON_LOW_CONFIDENCE;
        }
        return self::REASON_OFFERED_HUMAN;
    }

    public function resolve(int $messageId, bool $resolved = true): bool
    {
        $rec = ChatMessageRecord::findOne($messageId);
        if (!$rec || $rec->role !== 'bot') {
            return false;
        }
        $rec->gapResolvedAt = $resolved ? Db::prepareDateForDb(new DateTime()) : null;
        $rec->save(false);
        return true;
    }

    /**
     * Answer a gap for good: store the pair, index it, and mark the turn dealt
     * with. The question defaults to what the visitor actually typed, because
     * that is the phrasing the next visitor will use too.
     */
    public function answerWithQa(int $messageId, string $question, string $answer, ?int $siteId, bool $translate): ?TrainingQaRecord
    {
        $question = trim($question);
        $answer = trim($answer);
        if ($question === '' || $answer === '') {
            return null;
        }

        $rec = new TrainingQaRecord();
        $rec->question = $question;
        $rec->answer = $answer;
        $rec->source = 'chat';
        $rec->sourceMessageId = $messageId;
        $rec->active = true;
        $rec->siteId = $siteId ?: null;
        $rec->translate = $rec->siteId === null && $translate;
        if (!$rec->save()) {
            return null;
        }

        Plugin::getInstance()->training->trainQa((int)$rec->id);

        $message = ChatMessageRecord::findOne($messageId);
        if ($message) {
            $message->usedAsQa = true;
            $message->gapResolvedAt = Db::prepareDateForDb(new DateTime());
            $message->save(false);
        }
        return $rec;
    }

    /**
     * The questions that came up most often across gaps, so the biggest holes
     * get filled first rather than the most recent one.
     *
     * @return array<int, array{question:string, hits:int}>
     */
    public function commonQuestions(int $limit = 10): array
    {
        $rows = (clone $this->baseQuery(false))
            ->select(['question' => 'q.content'])
            ->andWhere(['not', ['q.content' => null]])
            ->limit(500)
            ->column();

        $counts = [];
        foreach ($rows as $question) {
            $key = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]+/u', '', (string)$question) ?? ''));
            $key = trim(preg_replace('/\s+/', ' ', $key) ?? '');
            if ($key === '' || $key === '[redacted]') {
                continue;
            }
            if (!isset($counts[$key])) {
                $counts[$key] = ['question' => (string)$question, 'hits' => 0];
            }
            $counts[$key]['hits']++;
        }
        uasort($counts, fn($a, $b) => $b['hits'] <=> $a['hits']);
        return array_slice(array_values($counts), 0, $limit);
    }

    /**
     * Discard telemetry older than the log retention window, alongside the
     * messages themselves — handled by the existing purge, this is just the
     * resolved-gap bookkeeping that would otherwise linger.
     */
    public function purgeResolved(int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }
        $cutoff = (new DateTime())->modify("-{$retentionDays} days");
        return (int)Craft::$app->db->createCommand()
            ->update(
                '{{%chatbot_messages}}',
                ['retrievalQuery' => null],
                ['and', ['<', 'gapResolvedAt', Db::prepareDateForDb($cutoff)]],
            )
            ->execute();
    }
}
