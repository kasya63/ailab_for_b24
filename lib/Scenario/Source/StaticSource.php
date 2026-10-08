<?php
namespace Local\AiLab\Scenario\Source;

use Local\AiLab\Scenario\SourceDef;

/** Текст или JSON как есть: правила, памятки, небольшие справочники. */
final class StaticSource implements SourceInterface
{
    public function run(SourceDef $def, array $params): SourceResult
    {
        $text = trim((string)($def->config['content'] ?? ''));
        $result = new SourceResult($text !== '' ? $text : 'Нет данных.');
        if ($text === '') {
            $result->warnings[] = 'Текст источника пуст';
        }
        return $result;
    }

    public function params(SourceDef $def): array
    {
        return [];
    }
}
