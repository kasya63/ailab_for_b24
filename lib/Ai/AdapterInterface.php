<?php
namespace Local\AiLab\Ai;

/**
 * Адаптер провайдера: превращает AiRequest в HTTP-запрос и разбирает ответ в AiResponse.
 * Сетью адаптер не занимается — это делает HttpTransport.
 */
interface AdapterInterface
{
    public static function code(): string;

    public static function title(): string;

    public static function defaultBaseUrl(): string;

    public function buildCall(Connection $connection, AiRequest $request): HttpCall;

    /** @param array{status: int, body: string, error: string, duration_ms: int} $http */
    public function parse(Connection $connection, AiRequest $request, array $http): AiResponse;
}
