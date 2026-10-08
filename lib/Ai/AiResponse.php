<?php
namespace Local\AiLab\Ai;

/**
 * Нормализованный ответ любого провайдера.
 *
 * status:
 *   success — ответ получен и (если была схема) разобран в $data;
 *   error   — провайдер или сеть вернули ошибку; $retryable говорит, имеет ли смысл повтор;
 *   invalid — провайдер ответил, но не по формату (нет JSON, ответ обрезан, отказ модели).
 */
final class AiResponse
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR   = 'error';
    public const STATUS_INVALID = 'invalid';

    public string $status = self::STATUS_ERROR;
    public int $httpStatus = 0;
    public string $text = '';
    public ?array $data = null;
    public string $error = '';
    public bool $retryable = false;
    public string $stopReason = '';
    public string $model = '';
    public int $inputTokens = 0;
    public int $outputTokens = 0;
    public int $cacheReadTokens = 0;
    public int $cacheWriteTokens = 0;
    public int $durationMs = 0;
    /** Тело запроса без ключа (ключ уходит только в заголовке). */
    public string $rawRequest = '';
    public string $rawResponse = '';
    /** @var string[] подсказки для человека */
    public array $notes = [];

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function succeed(): self
    {
        $this->status = self::STATUS_SUCCESS;
        $this->error = '';
        $this->retryable = false;
        return $this;
    }

    public function fail(string $error, bool $retryable = false, string $status = self::STATUS_ERROR): self
    {
        $this->status = $status;
        $this->error = $error;
        $this->retryable = $retryable;
        return $this;
    }
}
