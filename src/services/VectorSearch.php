<?php

namespace cstudiossro\craftcschatbot\services;

use Craft;
use yii\base\Component;

class VectorSearch extends Component
{
    /**
     * @param float[] $query
     * @return array<int, array{id:int, sourceType:string, sourceId:int, content:string, score:float}>
     */
    public function topK(array $query, int $k = 5, float $minScore = 0.0): array
    {
        if (empty($query)) {
            return [];
        }
        $rows = (new \craft\db\Query())
            ->select(['id', 'sourceType', 'sourceId', 'content', 'embedding'])
            ->from('{{%chatbot_chunks}}')
            ->where(['not', ['embedding' => null]])
            ->all(Craft::$app->db);

        $qNorm = $this->norm($query);
        if ($qNorm === 0.0) {
            return [];
        }

        $scored = [];
        foreach ($rows as $row) {
            $vec = json_decode((string)$row['embedding'], true);
            if (!is_array($vec) || empty($vec)) {
                continue;
            }
            $score = $this->cosine($query, $qNorm, $vec);
            if ($score >= $minScore) {
                $scored[] = [
                    'id' => (int)$row['id'],
                    'sourceType' => (string)$row['sourceType'],
                    'sourceId' => (int)$row['sourceId'],
                    'content' => (string)$row['content'],
                    'score' => $score,
                ];
            }
        }
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $k);
    }

    private function norm(array $v): float
    {
        $sum = 0.0;
        foreach ($v as $x) {
            $sum += $x * $x;
        }
        return sqrt($sum);
    }

    private function cosine(array $a, float $aNorm, array $b): float
    {
        $dot = 0.0;
        $bSum = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $bSum += $b[$i] * $b[$i];
        }
        $bNorm = sqrt($bSum);
        if ($aNorm === 0.0 || $bNorm === 0.0) {
            return 0.0;
        }
        return $dot / ($aNorm * $bNorm);
    }
}
