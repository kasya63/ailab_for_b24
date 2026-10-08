<?php
namespace Local\AiLab\Ai;

/** Синхронная отправка одного запроса. Очередь (этап 3) использует те же адаптеры через curl_multi. */
final class AiClient
{
    public static function send(Connection $connection, AiRequest $request, bool $allowInactive = false): AiResponse
    {
        if (!$connection->active && !$allowInactive) {
            return (new AiResponse())->fail('Подключение «' . $connection->name . '» выключено');
        }

        $adapter = AdapterFactory::make($connection->adapter);
        $call = $adapter->buildCall($connection, $request);
        $http = HttpTransport::execute($call);

        $response = $adapter->parse($connection, $request, $http);
        $response->rawRequest = $call->body;
        return $response;
    }
}
