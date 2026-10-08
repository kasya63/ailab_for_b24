<?php
namespace Local\AiLab\Ai;

/**
 * Пробный запрос: проверяет сразу ключ, модель и поддержку структурированного ответа.
 * Стоит копейки — несколько десятков токенов.
 */
final class ConnectionTester
{
    private const TEST_TIMEOUT = 60;

    public static function run(Connection $connection): AiResponse
    {
        @set_time_limit(self::TEST_TIMEOUT + 30);

        $request = new AiRequest();
        $request->system = 'Это проверка подключения. Ответь JSON-объектом {"ok": true, "echo": "pong"} и больше ничего не пиши.';
        $request->userParts = [AiRequest::textPart('ping')];
        $request->schemaName = 'connection_check';
        $request->schema = [
            'type'                 => 'object',
            'properties'           => [
                'ok'   => ['type' => 'boolean', 'description' => 'Всегда true'],
                'echo' => ['type' => 'string', 'description' => 'Слово pong'],
            ],
            'required'             => ['ok', 'echo'],
            'additionalProperties' => false,
        ];

        $response = AiClient::send($connection->withTimeout(min($connection->timeout, self::TEST_TIMEOUT)), $request, true);

        if ($response->isSuccess() && ($response->data['echo'] ?? null) !== 'pong') {
            $response->notes[] = 'Модель ответила по схеме, но не выполнила инструкцию. Проверьте, та ли модель выбрана.';
        }
        if (!$response->isSuccess()) {
            $response->notes = array_merge($response->notes, self::hints($response->error));
        }
        if (!$connection->active) {
            $response->notes[] = 'Подключение выключено: проверить его можно, но сценарии им пользоваться не будут.';
        }
        return $response;
    }

    /** @return string[] */
    private static function hints(string $error): array
    {
        $e = mb_strtolower($error);
        $hints = [];
        if (str_contains($e, 'temperature')) {
            $hints[] = 'Модель не принимает temperature: снимите галочку «Передавать temperature» на вкладке «Дополнительно».';
        }
        if (str_contains($e, 'max_completion_tokens') || str_contains($e, "'max_tokens'")) {
            $hints[] = 'Смените «Поле лимита токенов» на вкладке «Дополнительно».';
        }
        if (str_contains($e, 'model') && (str_contains($e, 'does not exist') || str_contains($e, 'not found') || str_contains($e, 'not_found'))) {
            $hints[] = 'Проверьте название модели: похоже на опечатку, или у ключа нет доступа к этой модели. Список доступных моделей есть в кабинете провайдера.';
        }
        if (str_contains($e, 'reasoning_effort') || str_contains($e, 'unrecognized request argument') || str_contains($e, 'extra inputs')) {
            $hints[] = 'Провайдер не принял дополнительный параметр запроса: уберите его на вкладке «Дополнительно».';
        }
        if (str_contains($e, 'response_format') || str_contains($e, 'json_schema')) {
            $hints[] = 'Провайдер не поддерживает этот режим строгого ответа: выберите «JSON-объект» или «Только описание в промте».';
        }
        return $hints;
    }
}
