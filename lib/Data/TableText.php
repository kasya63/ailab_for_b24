<?php
namespace Local\AiLab\Data;

/** Компактная таблица для промта: одна строка — один элемент, колонки через «|». */
final class TableText
{
    /**
     * @param array<string, string> $headers код => подпись
     * @param list<array<string, string>> $rows
     */
    public static function render(array $headers, array $rows): string
    {
        if (!$rows) {
            return 'Нет данных.';
        }
        $lines = [
            'Строк: ' . count($rows),
            'Колонки: ' . implode(' | ', array_map([self::class, 'cell'], $headers)),
        ];
        foreach ($rows as $row) {
            $cells = [];
            foreach (array_keys($headers) as $key) {
                $cells[] = self::cell((string)($row[$key] ?? ''));
            }
            $lines[] = implode(' | ', $cells);
        }
        return implode("\n", $lines);
    }

    public static function cell(string $value): string
    {
        $value = str_replace(["\r\n", "\r", "\n"], ' / ', $value);
        $value = str_replace('|', '/', $value);
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
