<?php

namespace cstudiossro\craftcschatbot\services;

use Craft;
use cstudiossro\craftcschatbot\Plugin;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use yii\base\Component;

class OpenAi extends Component
{
    public string $baseUrl = 'https://api.openai.com/v1';
    public float $timeout = 60.0;

    private ?Client $client = null;

    private function client(): Client
    {
        if ($this->client === null) {
            $key = Plugin::getInstance()->getSettings()->getOpenaiApiKey();
            if (!$key) {
                throw new RuntimeException('OpenAI API key not configured.');
            }
            $this->client = Craft::createGuzzleClient([
                'base_uri' => rtrim($this->baseUrl, '/') . '/',
                'timeout' => $this->timeout,
                'headers' => [
                    'Authorization' => 'Bearer ' . $key,
                    'Content-Type' => 'application/json',
                ],
            ]);
        }
        return $this->client;
    }

    /**
     * @param string[] $inputs
     * @return float[][] embedding vectors aligned to inputs
     */
    public function embed(array $inputs, ?string $model = null): array
    {
        if (empty($inputs)) {
            return [];
        }
        $model = $model ?: Plugin::getInstance()->getSettings()->embeddingModel;
        try {
            $res = $this->client()->post('embeddings', [
                'json' => [
                    'model' => $model,
                    'input' => array_values($inputs),
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('OpenAI embedding request failed: ' . $e->getMessage(), 0, $e);
        }
        $body = json_decode((string)$res->getBody(), true);
        $vectors = [];
        foreach ($body['data'] ?? [] as $row) {
            $vectors[$row['index']] = $row['embedding'];
        }
        ksort($vectors);
        return array_values($vectors);
    }

    /**
     * Convenience wrapper returning just the assistant text.
     *
     * @param array<int, array<string, mixed>> $messages
     */
    public function chat(array $messages, ?string $model = null, float $temperature = 0.2): string
    {
        $message = $this->chatRaw($messages, ['model' => $model, 'temperature' => $temperature]);
        return (string)($message['content'] ?? '');
    }

    /**
     * Low-level chat completion that returns the full assistant message
     * (including any `tool_calls`), so callers can drive a tool-calling loop.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array{model?:?string, temperature?:float, tools?:array, tool_choice?:mixed} $options
     * @return array<string, mixed> the assistant message
     */
    public function chatRaw(array $messages, array $options = []): array
    {
        $model = ($options['model'] ?? null) ?: Plugin::getInstance()->getSettings()->chatModel;
        $payload = [
            'model' => $model,
            'messages' => array_values($messages),
        ];
        if (!empty($options['tools'])) {
            $payload['tools'] = $options['tools'];
            $payload['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }
        // GPT-5 reasoning models only accept the default temperature (1) and reject a custom value.
        // The gpt-5-chat* (non-reasoning) variants still honor temperature.
        $isGpt5Reasoning = str_starts_with($model, 'gpt-5') && !str_starts_with($model, 'gpt-5-chat');
        if (!$isGpt5Reasoning) {
            $payload['temperature'] = $options['temperature'] ?? 0.2;
        }
        try {
            $res = $this->client()->post('chat/completions', [
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('OpenAI chat request failed: ' . $e->getMessage(), 0, $e);
        }
        $body = json_decode((string)$res->getBody(), true);
        $message = $body['choices'][0]['message'] ?? [];
        if (!isset($message['role'])) {
            $message['role'] = 'assistant';
        }
        return $message;
    }
}
