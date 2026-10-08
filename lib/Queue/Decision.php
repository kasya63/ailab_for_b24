<?php
namespace Local\AiLab\Queue;

use Local\AiLab\Ai\AiResponse;
use Local\AiLab\Scenario\OutputSchema;

/**
 * Что делать с ответом провайдера. Чистая логика без базы — её проверяют тесты.
 *
 *   ответ прошёл проверку                      → success
 *   не по формату / не прошёл проверку, 1-й раз → resend (сразу, с перечнем ошибок)
 *   не по формату второй раз                   → invalid
 *   временная ошибка (429/5xx/таймаут/сеть)    → retry_later через 15 с, потом 60 с; всего 3 попытки
 *   повтор не успевает до срока ожидания       → timeout
 *   постоянная ошибка (400/401/403/404)        → error
 */
final class Decision
{
    public const SUCCESS     = 'success';
    public const RESEND      = 'resend';
    public const RETRY_LATER = 'retry_later';
    public const ERROR       = 'error';
    public const INVALID     = 'invalid';
    public const TIMEOUT     = 'timeout';

    public const MAX_ATTEMPTS = 3;
    /** Пауза перед повтором после N-й неудачной попытки, секунд. */
    public const BACKOFF = [1 => 15, 2 => 60];

    private function __construct(
        public readonly string $action,
        public readonly string $error = '',
        public readonly ?array $data = null,
        public readonly array $warnings = [],
        public readonly int $nextRunAt = 0,
        public readonly array $problems = [],
    ) {
    }

    /**
     * @param int $attempts сколько раз задача уже бралась в работу, включая текущий
     * @param array<int, array<int, true>> $refSets
     */
    public static function make(
        AiResponse $response,
        OutputSchema $schema,
        array $refSets,
        int $attempts,
        bool $correctionUsed,
        int $now,
        int $deadline,
    ): self {
        if ($response->status === AiResponse::STATUS_ERROR) {
            if (!$response->retryable) {
                return new self(self::ERROR, $response->error);
            }
            if ($attempts >= self::MAX_ATTEMPTS) {
                return new self(self::ERROR, sprintf('После %d попыток: %s', $attempts, $response->error));
            }
            $next = $now + (self::BACKOFF[$attempts] ?? max(self::BACKOFF));
            if ($next >= $deadline) {
                return new self(self::TIMEOUT, 'Повтор не успевает до конца времени ожидания. Последняя ошибка: ' . $response->error);
            }
            return new self(self::RETRY_LATER, $response->error, null, [], $next);
        }

        if ($response->status === AiResponse::STATUS_INVALID) {
            $problems = [$response->error];
        } elseif ($schema->isEmpty()) {
            return new self(self::SUCCESS, '', ['text' => $response->text]);
        } else {
            $validation = $schema->validate($response->data ?? [], $refSets);
            if ($validation->ok) {
                return new self(self::SUCCESS, '', $validation->data, $validation->warnings);
            }
            $problems = $validation->errors;
        }

        if (!$correctionUsed) {
            return new self(self::RESEND, implode('; ', $problems), null, [], 0, $problems);
        }
        return new self(self::INVALID, implode('; ', $problems), null, [], 0, $problems);
    }
}
