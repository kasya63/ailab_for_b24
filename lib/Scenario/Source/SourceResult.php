<?php
namespace Local\AiLab\Scenario\Source;

/** Что источник отдал в промт. */
final class SourceResult
{
    /** @var array<int, true> ID, которые ушли модели (для проверки ответа) */
    public array $ids = [];
    public int $rows = 0;
    /** @var string[] */
    public array $warnings = [];

    public function __construct(public string $text = '')
    {
    }

    /** @param int[] $ids */
    public function setIds(array $ids): void
    {
        $this->ids = [];
        foreach ($ids as $id) {
            $this->ids[(int)$id] = true;
        }
    }
}
