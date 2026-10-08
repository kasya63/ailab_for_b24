<?php
namespace Local\AiLab\Ai\Adapter;

use Local\AiLab\Ai\AiRequest;
use Local\AiLab\Ai\AiResponse;
use Local\AiLab\Ai\Connection;
use Local\AiLab\Ai\HttpCall;
use Local\AiLab\Json;

/**
 * Chat Completions API: OpenAI, OpenRouter и прочие совместимые провайдеры.
 *
 * Строгий формат (параметр structured_mode):
 *   json_schema — response_format с JSON Schema, strict (OpenAI и большинство совместимых);
 *   json_object — только «верни JSON», схема описывается в промте;
 *   prompt      — ничего не передаём, формат описан только в промте.
 */
final class OpenAiCompatible extends AbstractAdapter
{
    public static function code(): string
    {
        return 'openai';
    }

    public static function title(): string
    {
        return 'OpenAI-совместимый (OpenAI, OpenRouter и др.)';
    }

    public static function defaultBaseUrl(): string
    {
        return 'https://api.openai.com/v1';
    }

    public function buildCall(Connection $connection, AiRequest $request): HttpCall
    {
        $url = rtrim($connection->baseUrl, '/') . '/chat/completions';

        $messages = [];
        if ($request->system !== '') {
            $messages[] = ['role' => 'system', 'content' => $request->system];
        }
        $messages[] = ['role' => 'user', 'content' => $this->content($request)];

        $payload = [
            'model'    => $request->model ?: $connection->model,
            'messages' => $messages,
        ];

        $tokensField = $connection->param('max_tokens_field', 'max_tokens') === 'max_completion_tokens'
            ? 'max_completion_tokens'
            : 'max_tokens';
        $payload[$tokensField] = $this->maxTokens($connection, $request);

        if ($this->sendTemperature($connection, $request)) {
            $payload['temperature'] = $request->temperature;
        }

        if ($request->schema !== null) {
            switch ($connection->param('structured_mode', 'json_schema')) {
                case 'json_schema':
                    $payload['response_format'] = [
                        'type'        => 'json_schema',
                        'json_schema' => ['name' => $request->schemaName, 'strict' => true, 'schema' => $request->schema],
                    ];
                    break;
                case 'json_object':
                    $payload['response_format'] = ['type' => 'json_object'];
                    break;
            }
        }

        $payload = $this->mergeExtraBody($connection, $payload, ['model', 'messages', 'response_format', 'tools', 'tool_choice', 'stream']);

        $own = $connection->apiKey !== '' ? ['Authorization' => 'Bearer ' . $connection->apiKey] : [];

        return new HttpCall($url, $this->headers($connection, $own), Json::encode($payload), $connection->timeout);
    }

    public function parse(Connection $connection, AiRequest $request, array $http): AiResponse
    {
        $response = $this->newResponse($connection, $request, $http);
        $body = $this->decodeBody($http);
        if (!$this->checkHttp($response, $http, $body)) {
            return $response;
        }

        $response->model = (string)($body['model'] ?? $response->model);
        $usage = (array)($body['usage'] ?? []);
        $response->inputTokens = (int)($usage['prompt_tokens'] ?? 0);
        $response->outputTokens = (int)($usage['completion_tokens'] ?? 0);
        $response->cacheReadTokens = (int)($usage['prompt_tokens_details']['cached_tokens'] ?? 0);

        $choice = $body['choices'][0] ?? null;
        if (!is_array($choice)) {
            return $response->fail('В ответе провайдера нет вариантов ответа (choices)', true);
        }
        $message = (array)($choice['message'] ?? []);
        $response->stopReason = (string)($choice['finish_reason'] ?? '');

        $content = $message['content'] ?? '';
        if (is_array($content)) {
            $content = implode("\n", array_map(
                static fn($part) => is_array($part) ? (string)($part['text'] ?? '') : (string)$part,
                $content
            ));
        }
        $response->text = trim((string)$content);

        if (!empty($message['refusal'])) {
            return $response->fail('Модель отказалась отвечать: ' . $message['refusal'], false, AiResponse::STATUS_INVALID);
        }
        if ($request->schema === null) {
            return $response->succeed();
        }
        if ($response->stopReason === 'length') {
            return $response->fail('Ответ обрезан: не хватило лимита токенов ответа', false, AiResponse::STATUS_INVALID);
        }

        $data = Json::extractObject($response->text);
        if ($data === null) {
            return $response->fail('Модель не вернула JSON-объект', false, AiResponse::STATUS_INVALID);
        }

        $response->data = $data;
        return $response->succeed();
    }

    protected function errorMessage(?array $body): string
    {
        $error = $body['error'] ?? null;
        if (is_array($error)) {
            return (string)($error['message'] ?? '');
        }
        if (is_string($error)) {
            return $error;
        }
        return (string)($body['message'] ?? '');
    }

    /** Только текст — строкой (так понимают все совместимые API), с файлами — массивом частей. */
    private function content(AiRequest $request): string|array
    {
        $onlyText = true;
        foreach ($request->userParts as $part) {
            if (($part['type'] ?? '') !== 'text') {
                $onlyText = false;
                break;
            }
        }

        if ($onlyText) {
            $text = implode("\n\n", array_map(static fn($part) => (string)$part['text'], $request->userParts));
            return $text !== '' ? $text : '.';
        }

        $content = [];
        foreach ($request->userParts as $part) {
            switch ($part['type'] ?? '') {
                case 'text':
                    $content[] = ['type' => 'text', 'text' => (string)$part['text']];
                    break;
                case 'image':
                    $content[] = ['type' => 'image_url', 'image_url' => [
                        'url' => 'data:' . $part['media_type'] . ';base64,' . $part['data'],
                    ]];
                    break;
                case 'pdf':
                    $content[] = ['type' => 'file', 'file' => [
                        'filename'  => (string)($part['name'] ?? 'document.pdf'),
                        'file_data' => 'data:application/pdf;base64,' . $part['data'],
                    ]];
                    break;
            }
        }
        return $content;
    }
}
