<?php
namespace Local\AiLab\Ai\Adapter;

use Local\AiLab\Ai\AdapterInterface;
use Local\AiLab\Ai\AiRequest;
use Local\AiLab\Ai\AiResponse;
use Local\AiLab\Ai\Connection;
use Local\AiLab\Json;

abstract class AbstractAdapter implements AdapterInterface
{
    /** Временные ошибки: повтор через паузу имеет смысл. */
    protected const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504, 529];

    private const STATUS_HINTS = [
        400 => 'запрос отклонён',
        401 => 'ключ не принят, проверьте API-ключ',
        403 => 'доступ запрещён: у ключа нет прав на эту модель или регион не поддерживается',
        404 => 'не найдено: проверьте адрес API и название модели',
        408 => 'провайдер не дождался запроса',
        413 => 'запрос слишком большой',
        429 => 'превышен лимит запросов или исчерпан баланс',
        500 => 'ошибка на стороне провайдера',
        502 => 'провайдер временно недоступен',
        503 => 'провайдер временно недоступен',
        504 => 'провайдер не успел ответить',
        529 => 'провайдер перегружен',
    ];

    /** Текст ошибки из тела ответа провайдера. */
    abstract protected function errorMessage(?array $body): string;

    protected function newResponse(Connection $connection, AiRequest $request, array $http): AiResponse
    {
        $response = new AiResponse();
        $response->httpStatus = (int)$http['status'];
        $response->durationMs = (int)$http['duration_ms'];
        $response->rawResponse = (string)$http['body'];
        $response->model = $request->model ?: $connection->model;
        return $response;
    }

    /**
     * Сетевые и HTTP-ошибки. true — ответ 2xx и тело является JSON-объектом.
     */
    protected function checkHttp(AiResponse $response, array $http, ?array $body): bool
    {
        if ($http['error'] !== '') {
            $response->fail($http['error'], true);
            return false;
        }

        $status = $response->httpStatus;
        if ($status < 200 || $status >= 300) {
            $hint = self::STATUS_HINTS[$status] ?? 'неожиданный ответ';
            $message = $this->errorMessage($body);
            $response->fail(
                sprintf('HTTP %d, %s.', $status, $hint) . ($message !== '' ? ' Провайдер: ' . $message : ''),
                in_array($status, static::RETRYABLE_STATUSES, true)
            );
            return false;
        }

        if ($body === null) {
            $response->fail('Провайдер вернул ответ не в формате JSON', true);
            return false;
        }
        return true;
    }

    /** Заголовки: служебные модуля важнее пользовательских из настроек подключения. */
    protected function headers(Connection $connection, array $own): array
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        foreach ((array)$connection->param('extra_headers', []) as $name => $value) {
            $headers[(string)$name] = (string)$value;
        }
        return array_merge($headers, $own);
    }

    /**
     * Дополнительные параметры запроса из настроек подключения, например {"reasoning_effort": "low"}.
     * Ключи, которыми управляет модуль, перезаписать нельзя.
     */
    protected function mergeExtraBody(Connection $connection, array $payload, array $protected): array
    {
        foreach ((array)$connection->param('extra_body', []) as $key => $value) {
            if (!in_array((string)$key, $protected, true)) {
                $payload[(string)$key] = $value;
            }
        }
        return $payload;
    }

    protected function decodeBody(array $http): ?array
    {
        return Json::decodeObject((string)$http['body']);
    }

    protected function maxTokens(Connection $connection, AiRequest $request): int
    {
        return $request->maxTokens ?: $connection->maxTokens;
    }

    protected function sendTemperature(Connection $connection, AiRequest $request): bool
    {
        return $request->temperature !== null && (bool)$connection->param('send_temperature', true);
    }
}
