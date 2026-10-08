<?php
namespace Local\AiLab\Queue;

use Local\AiLab\Ai\AdapterFactory;
use Local\AiLab\Ai\AiRequest;
use Local\AiLab\Ai\AiResponse;
use Local\AiLab\Ai\Connection;
use Local\AiLab\Ai\HttpTransport;

/**
 * Отправляет несколько запросов одновременно через curl_multi и разбирает ответы адаптерами.
 * Каждый запрос уходит ровно один раз; общая длительность ≈ самый долгий из них.
 */
final class ParallelSender
{
    /**
     * @param array<int|string, array{0: Connection, 1: AiRequest}> $jobs
     * @return array<int|string, AiResponse> в тех же ключах
     */
    public static function send(array $jobs): array
    {
        $results = [];
        $pending = [];
        $mh = curl_multi_init();

        foreach ($jobs as $key => [$connection, $request]) {
            if (!$connection->active) {
                $results[$key] = (new AiResponse())->fail('Подключение «' . $connection->name . '» выключено');
                continue;
            }
            try {
                $adapter = AdapterFactory::make($connection->adapter);
                $call = $adapter->buildCall($connection, $request);
            } catch (\Throwable $e) {
                $results[$key] = (new AiResponse())->fail('Не удалось собрать запрос: ' . $e->getMessage());
                continue;
            }
            $ch = HttpTransport::createHandle($call);
            curl_multi_add_handle($mh, $ch);
            $pending[spl_object_id($ch)] = [
                'key' => $key, 'ch' => $ch, 'call' => $call, 'adapter' => $adapter,
                'connection' => $connection, 'request' => $request,
            ];
        }

        $startedAt = microtime(true);
        $active = 0;
        do {
            $status = curl_multi_exec($mh, $active);
            self::collect($mh, $pending, $results, $startedAt);
            if ($active > 0 && $status === CURLM_OK && curl_multi_select($mh, 1.0) === -1) {
                usleep(50000);
            }
        } while ($active > 0 && $status === CURLM_OK);
        self::collect($mh, $pending, $results, $startedAt);

        foreach ($pending as $item) {
            $results[$item['key']] = (new AiResponse())->fail('Запрос не завершился (сбой curl_multi)', true);
            curl_multi_remove_handle($mh, $item['ch']);
            curl_close($item['ch']);
        }
        curl_multi_close($mh);

        $ordered = [];
        foreach (array_keys($jobs) as $key) {
            $ordered[$key] = $results[$key];
        }
        return $ordered;
    }

    private static function collect(\CurlMultiHandle $mh, array &$pending, array &$results, float $startedAt): void
    {
        while ($info = curl_multi_info_read($mh)) {
            if (($info['msg'] ?? 0) !== CURLMSG_DONE) {
                continue;
            }
            $ch = $info['handle'];
            $id = spl_object_id($ch);
            if (!isset($pending[$id])) {
                continue;
            }
            $item = $pending[$id];
            unset($pending[$id]);

            $body = curl_multi_getcontent($ch);
            $http = HttpTransport::result($item['call'], $ch, is_string($body) ? $body : null, $startedAt, (int)$info['result']);
            $response = $item['adapter']->parse($item['connection'], $item['request'], $http);
            $response->rawRequest = $item['call']->body;
            $results[$item['key']] = $response;

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
    }
}
