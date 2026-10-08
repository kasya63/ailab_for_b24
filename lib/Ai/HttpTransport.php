<?php
namespace Local\AiLab\Ai;

/**
 * curl-транспорт. createHandle()/result() вынесены отдельно, чтобы воркер очереди
 * мог гонять несколько запросов параллельно через curl_multi.
 */
final class HttpTransport
{
    public static function createHandle(HttpCall $call): \CurlHandle
    {
        $headers = [];
        foreach ($call->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $ch = curl_init($call->url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $call->body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(15, $call->timeout),
            CURLOPT_TIMEOUT        => $call->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'local.ailab/0.1',
        ]);
        return $ch;
    }

    /**
     * @param int|null $errno для curl_multi код берётся из curl_multi_info_read()
     * @return array{status: int, body: string, error: string, duration_ms: int}
     */
    public static function result(HttpCall $call, \CurlHandle $ch, ?string $body, float $startedAt, ?int $errno = null): array
    {
        $errno ??= curl_errno($ch);
        $error = '';
        if ($errno !== 0) {
            $error = $errno === CURLE_OPERATION_TIMEDOUT
                ? sprintf('Провайдер не ответил за %d с (таймаут подключения)', $call->timeout)
                : 'Сетевая ошибка: ' . (curl_error($ch) ?: curl_strerror($errno));
        }

        return [
            'status'      => $errno !== 0 ? 0 : (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'body'        => (string)$body,
            'error'       => $error,
            'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /** @return array{status: int, body: string, error: string, duration_ms: int} */
    public static function execute(HttpCall $call): array
    {
        $ch = self::createHandle($call);
        $startedAt = microtime(true);
        $body = curl_exec($ch);
        $result = self::result($call, $ch, is_string($body) ? $body : null, $startedAt);
        curl_close($ch);
        return $result;
    }
}
