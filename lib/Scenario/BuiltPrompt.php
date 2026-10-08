<?php
namespace Local\AiLab\Scenario;

use Local\AiLab\Ai\AiRequest;
use Local\AiLab\Scenario\Source\SourceResult;

/** Собранный запрос плюс сведения для показа и проверки ответа. */
final class BuiltPrompt
{
    public AiRequest $request;
    public string $systemText = '';
    public string $userText = '';
    /** @var array<int, SourceResult> ID источника => результат */
    public array $sources = [];
    /** @var string[] */
    public array $warnings = [];
    /** @var list<array> сведения о файлах (без содержимого): label, file_id, name, kind, pages, chars, notes */
    public array $attachments = [];

    public function chars(): int
    {
        return mb_strlen($this->systemText) + mb_strlen($this->userText);
    }

    /** Грубая оценка: для смеси кириллицы и цифр ~3 символа на токен. */
    public function approxTokens(): int
    {
        return (int)ceil($this->chars() / 3);
    }

    /** @return array<int, array<int, true>> */
    public function refSets(): array
    {
        $sets = [];
        foreach ($this->sources as $sourceId => $result) {
            $sets[$sourceId] = $result->ids;
        }
        return $sets;
    }
}
