<?php
namespace Local\AiLab\Ai\Adapter;

use Local\AiLab\Ai\AiRequest;
use Local\AiLab\Ai\AiResponse;
use Local\AiLab\Ai\Connection;
use Local\AiLab\Ai\HttpCall;
use Local\AiLab\Json;

/**
 * Anthropic Messages API.
 * Строгий формат: схема передаётся как инструмент, вызов которого модель обязана сделать
 * (tool_choice). Работает на всех моделях Claude без бета-заголовков.
 * Постоянная часть промта (system) помечается для кэша.
 */
final class Anthropic extends AbstractAdapter
{
    public const TOOL_NAME = 'submit_result';
    private const API_VERSION = '2023-06-01';

    public static function code(): string
    {
        return 'anthropic';
    }

    public static function title(): string
    {
        return 'Anthropic (Claude)';
    }

    public static function defaultBaseUrl(): string
    {
        return 'https://api.anthropic.com';
    }

    public function buildCall(Connection $connection, AiRequest $request): HttpCall
    {
        $base = rtrim($connection->baseUrl, '/');
        $url = (str_ends_with($base, '/v1') ? $base : $base . '/v1') . '/messages';

        $payload = [
            'model'      => $request->model ?: $connection->model,
            'max_tokens' => $this->maxTokens($connection, $request),
            'messages'   => [['role' => 'user', 'content' => $this->content($request)]],
        ];

        if ($request->system !== '') {
            $block = ['type' => 'text', 'text' => $request->system];
            if ($connection->param('prompt_cache', true)) {
                $block['cache_control'] = ['type' => 'ephemeral'];
            }
            $payload['system'] = [$block];
        }

        if ($this->sendTemperature($connection, $request)) {
            $payload['temperature'] = $request->temperature;
        }

        if ($request->schema !== null) {
            $payload['tools'] = [[
                'name'         => self::TOOL_NAME,
                'description'  => 'Передай итоговый результат строго по схеме.',
                'input_schema' => $request->schema,
            ]];
            $payload['tool_choice'] = ['type' => 'tool', 'name' => self::TOOL_NAME];
        }

        $payload = $this->mergeExtraBody($connection, $payload, ['model', 'messages', 'system', 'tools', 'tool_choice', 'stream']);

        $own = ['anthropic-version' => self::API_VERSION];
        if ($connection->apiKey !== '') {
            $own['x-api-key'] = $connection->apiKey;
        }

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
        $response->stopReason = (string)($body['stop_reason'] ?? '');
        $usage = (array)($body['usage'] ?? []);
        $response->inputTokens = (int)($usage['input_tokens'] ?? 0);
        $response->outputTokens = (int)($usage['output_tokens'] ?? 0);
        $response->cacheReadTokens = (int)($usage['cache_read_input_tokens'] ?? 0);
        $response->cacheWriteTokens = (int)($usage['cache_creation_input_tokens'] ?? 0);

        $texts = [];
        $toolInput = null;
        foreach ((array)($body['content'] ?? []) as $block) {
            $type = $block['type'] ?? '';
            if ($type === 'text') {
                $texts[] = (string)($block['text'] ?? '');
            } elseif ($type === 'tool_use' && ($block['name'] ?? '') === self::TOOL_NAME) {
                $toolInput = $block['input'] ?? null;
            }
        }
        $response->text = trim(implode("\n", $texts));

        if ($response->stopReason === 'refusal') {
            return $response->fail('Модель отказалась отвечать', false, AiResponse::STATUS_INVALID);
        }
        if ($request->schema === null) {
            return $response->succeed();
        }
        if ($response->stopReason === 'max_tokens') {
            return $response->fail('Ответ обрезан: не хватило лимита токенов ответа', false, AiResponse::STATUS_INVALID);
        }

        if (!is_array($toolInput)) {
            $toolInput = Json::extractObject($response->text);
        }
        if (!is_array($toolInput)) {
            return $response->fail('Модель не вернула ответ по схеме', false, AiResponse::STATUS_INVALID);
        }

        $response->data = $toolInput;
        return $response->succeed();
    }

    protected function errorMessage(?array $body): string
    {
        return (string)($body['error']['message'] ?? '');
    }

    private function content(AiRequest $request): array
    {
        $content = [];
        foreach ($request->userParts as $part) {
            switch ($part['type'] ?? '') {
                case 'text':
                    $content[] = ['type' => 'text', 'text' => (string)$part['text']];
                    break;
                case 'image':
                    $content[] = ['type' => 'image', 'source' => [
                        'type' => 'base64', 'media_type' => (string)$part['media_type'], 'data' => (string)$part['data'],
                    ]];
                    break;
                case 'pdf':
                    $content[] = ['type' => 'document', 'source' => [
                        'type' => 'base64', 'media_type' => 'application/pdf', 'data' => (string)$part['data'],
                    ]];
                    break;
            }
        }
        return $content ?: [['type' => 'text', 'text' => '.']];
    }
}
