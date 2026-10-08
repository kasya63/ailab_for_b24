<?php
namespace Local\AiLab\Scenario;

use Local\AiLab\Ai\AiResponse;

/** Итог прогона сценария: статус, проверенные данные и все попытки. */
final class RunResult
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR   = 'error';
    public const STATUS_INVALID = 'invalid';

    public string $status = self::STATUS_ERROR;
    /** @var array<string, mixed>|null проверенный ответ по схеме */
    public ?array $data = null;
    /** Ответ текстом, если у сценария нет схемы. */
    public string $text = '';
    public string $error = '';
    public ?ValidationResult $validation = null;
    public ?BuiltPrompt $built = null;
    /** @var AiResponse[] */
    public array $attempts = [];

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function totalDurationMs(): int
    {
        return array_sum(array_map(static fn(AiResponse $r) => $r->durationMs, $this->attempts));
    }

    /** @return array{input: int, output: int, cached: int} */
    public function totalTokens(): array
    {
        $total = ['input' => 0, 'output' => 0, 'cached' => 0];
        foreach ($this->attempts as $r) {
            $total['input'] += $r->inputTokens;
            $total['output'] += $r->outputTokens;
            $total['cached'] += $r->cacheReadTokens;
        }
        return $total;
    }
}
