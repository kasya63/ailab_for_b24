<?php
namespace Local\AiLab\Scenario;

use Local\AiLab\Json;

/**
 * Формат ответа сценария — контракт между моделью и бизнес-процессом.
 *
 * Из одного списка полей получаются:
 *  - JSON Schema для провайдера (совместима со strict-режимом OpenAI: все поля required,
 *    необязательные — через null);
 *  - текстовое описание формата для промта;
 *  - серверная проверка ответа, включая «ID есть среди отправленных».
 */
final class OutputSchema
{
    public const TYPES = [
        'string'   => 'Строка',
        'integer'  => 'Целое число',
        'number'   => 'Число',
        'boolean'  => 'Да / нет',
        'enum'     => 'Один из вариантов',
        'ref'      => 'ID из источника',
        'ref_list' => 'Список ID из источника',
        'table'    => 'Таблица (строки с колонками)',
    ];

    /** Типы колонок таблицы. */
    public const COLUMN_TYPES = [
        'string' => 'строка',
        'number' => 'число',
        'date'   => 'дата',
        'ref'    => 'ID из источника',
    ];

    /** Служебные результаты кубика, их нельзя занимать. */
    public const RESERVED = ['status', 'error_text', 'log_id', 'attempts'];

    /** @param list<array> $fields нормализованные поля */
    private function __construct(private readonly array $fields)
    {
    }

    public static function fromJson(?string $json): self
    {
        $data = json_decode((string)$json, true);
        return self::fromArray(is_array($data) ? $data : []);
    }

    public static function fromArray(array $fields): self
    {
        $normalized = [];
        foreach ($fields as $field) {
            $f = self::normalizeField($field);
            if ($f !== null) {
                $normalized[] = $f;
            }
        }
        return new self($normalized);
    }

    public static function normalizeField(mixed $f): ?array
    {
        if (!is_array($f)) {
            return null;
        }
        $code = trim((string)($f['code'] ?? ''));
        $type = (string)($f['type'] ?? 'string');
        if ($code === '' || !isset(self::TYPES[$type])) {
            return null;
        }
        $enum = array_values(array_unique(array_filter(
            array_map(static fn($v) => trim((string)$v), (array)($f['enum'] ?? [])),
            static fn($v) => $v !== ''
        )));
        return [
            'code'        => $code,
            'type'        => $type,
            'description' => trim((string)($f['description'] ?? '')),
            'required'    => (bool)($f['required'] ?? true),
            'enum'        => $enum,
            'source_id'   => (int)($f['source_id'] ?? 0),
            'allow_zero'  => (bool)($f['allow_zero'] ?? false),
            'max_items'   => max(0, (int)($f['max_items'] ?? 0)),
            'columns'     => $type === 'table' ? self::normalizeColumns($f['columns'] ?? []) : [],
        ];
    }

    /** @return list<array{code: string, type: string, source_id: int, description: string}> */
    public static function normalizeColumns(mixed $columns): array
    {
        $out = [];
        $seen = [];
        foreach ((array)$columns as $c) {
            if (!is_array($c)) {
                continue;
            }
            $code = trim((string)($c['code'] ?? ''));
            $type = (string)($c['type'] ?? 'string');
            if ($code === '' || isset($seen[$code]) || !isset(self::COLUMN_TYPES[$type])) {
                continue;
            }
            $seen[$code] = true;
            $out[] = [
                'code'        => $code,
                'type'        => $type,
                'source_id'   => $type === 'ref' ? (int)($c['source_id'] ?? 0) : 0,
                'description' => trim((string)($c['description'] ?? '')),
            ];
        }
        return $out;
    }

    /** @return list<array> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    public function toJson(): string
    {
        return Json::encode($this->fields);
    }

    public function jsonSchema(): array
    {
        $properties = [];
        $required = [];
        foreach ($this->fields as $f) {
            $properties[$f['code']] = self::fieldSchema($f);
            $required[] = $f['code'];
        }
        return [
            'type'                 => 'object',
            'properties'           => (object)$properties,
            'required'             => $required,
            'additionalProperties' => false,
        ];
    }

    /** @param array<int, string> $sourceTitles ID источника => заголовок */
    public function formatText(array $sourceTitles): string
    {
        $lines = ['Ответь одним JSON-объектом со следующими полями:'];
        foreach ($this->fields as $f) {
            $source = $sourceTitles[$f['source_id']] ?? ('источник #' . $f['source_id']);
            $what = match ($f['type']) {
                'string'   => 'строка',
                'integer'  => 'целое число',
                'number'   => 'число',
                'boolean'  => 'true или false',
                'enum'     => 'одно из значений: ' . implode(', ', array_map(static fn($v) => '"' . $v . '"', $f['enum'])),
                'ref'      => 'целое число — ID строки из раздела «' . $source . '»'
                    . ($f['allow_zero'] ? '; 0 — если ничего не подходит' : ''),
                'ref_list' => 'массив целых чисел — ID строк из раздела «' . $source . '»'
                    . ($f['max_items'] > 0 ? ', не больше ' . $f['max_items'] : ''),
                'table'    => self::tableText($f, $sourceTitles),
            };
            $line = sprintf('- "%s": %s.', $f['code'], $what);
            if (!$f['required']) {
                $line .= ' Необязательное: null, если значения нет.';
            }
            if ($f['description'] !== '') {
                $line .= ' ' . $f['description'];
            }
            $lines[] = $line;
        }
        $lines[] = 'Никакого текста до или после JSON. ID бери только из переданных данных, не придумывай их.';
        return implode("\n", $lines);
    }

    /**
     * @param array<int, array<int, true>> $refSets ID источника => множество ID, которые реально ушли в промт
     */
    public function validate(array $data, array $refSets): ValidationResult
    {
        $clean = [];
        $errors = [];
        $warnings = [];

        $known = array_column($this->fields, 'code');
        $extra = array_diff(array_keys($data), $known);
        if ($extra) {
            $warnings[] = 'Лишние поля проигнорированы: ' . implode(', ', $extra);
        }

        foreach ($this->fields as $f) {
            $code = $f['code'];
            $value = $data[$code] ?? null;

            if ($value === null || ($value === '' && $f['type'] !== 'string')) {
                if ($f['required']) {
                    $errors[] = "Нет обязательного поля «{$code}»";
                }
                $clean[$code] = null;
                continue;
            }

            [$ok, $converted, $message] = $this->checkValue($f, $value, $refSets, $warnings);
            if (!$ok) {
                $errors[] = "Поле «{$code}»: {$message}";
                $clean[$code] = null;
                continue;
            }
            $clean[$code] = $converted;
        }

        return new ValidationResult($errors === [], $clean, $errors, $warnings);
    }

    /** @return array{0: bool, 1: mixed, 2: string} */
    private function checkValue(array $f, mixed $value, array $refSets, array &$warnings): array
    {
        switch ($f['type']) {
            case 'string':
                return is_scalar($value) ? [true, (string)$value, ''] : [false, null, 'ожидалась строка'];

            case 'integer':
                $int = self::toInt($value);
                return $int !== null ? [true, $int, ''] : [false, null, 'ожидалось целое число'];

            case 'number':
                if (is_int($value) || is_float($value)) {
                    return [true, $value, ''];
                }
                if (is_string($value) && is_numeric(str_replace(',', '.', $value))) {
                    return [true, (float)str_replace(',', '.', $value), ''];
                }
                return [false, null, 'ожидалось число'];

            case 'boolean':
                if (is_bool($value)) {
                    return [true, $value, ''];
                }
                $s = mb_strtolower(trim((string)$value));
                if (in_array($s, ['true', '1', 'да', 'yes'], true)) {
                    return [true, true, ''];
                }
                if (in_array($s, ['false', '0', 'нет', 'no'], true)) {
                    return [true, false, ''];
                }
                return [false, null, 'ожидалось true или false'];

            case 'enum':
                $s = is_scalar($value) ? trim((string)$value) : '';
                if (in_array($s, $f['enum'], true)) {
                    return [true, $s, ''];
                }
                foreach ($f['enum'] as $option) {
                    if (mb_strtolower($option) === mb_strtolower($s)) {
                        return [true, $option, ''];
                    }
                }
                return [false, null, '«' . $s . '» нет среди допустимых значений'];

            case 'ref':
                $id = self::toInt($value);
                if ($id === null) {
                    return [false, null, 'ожидался ID — целое число'];
                }
                if ($id === 0 && $f['allow_zero']) {
                    return [true, 0, ''];
                }
                if (!isset($refSets[$f['source_id']])) {
                    return [false, null, 'источник, на который ссылается поле, не участвовал в запросе'];
                }
                return isset($refSets[$f['source_id']][$id])
                    ? [true, $id, '']
                    : [false, null, "ID {$id} нет среди переданных модели"];

            case 'ref_list':
                if (!is_array($value)) {
                    $value = [$value];
                    $warnings[] = "Поле «{$f['code']}»: ожидался массив, одиночное значение обёрнуто в массив";
                }
                if (!isset($refSets[$f['source_id']])) {
                    return [false, null, 'источник, на который ссылается поле, не участвовал в запросе'];
                }
                $ids = [];
                foreach ($value as $item) {
                    $id = self::toInt($item);
                    if ($id === null || !isset($refSets[$f['source_id']][$id])) {
                        $warnings[] = "Поле «{$f['code']}»: значение " . Json::encode($item) . ' нет среди переданных ID и отброшено';
                        continue;
                    }
                    $ids[$id] = $id;
                }
                $ids = array_values($ids);
                if ($f['max_items'] > 0 && count($ids) > $f['max_items']) {
                    $warnings[] = "Поле «{$f['code']}»: оставлены первые {$f['max_items']} из " . count($ids);
                    $ids = array_slice($ids, 0, $f['max_items']);
                }
                return [true, $ids, ''];

            case 'table':
                return $this->checkTable($f, $value, $refSets, $warnings);
        }
        return [false, null, 'неизвестный тип'];
    }

    /** @return array{0: bool, 1: mixed, 2: string} */
    private function checkTable(array $f, mixed $value, array $refSets, array &$warnings): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                return [false, null, 'ожидался массив строк таблицы'];
            }
            $value = $decoded;
        }
        if (is_array($value) && isset($value['rows']) && is_array($value['rows'])) {
            $value = $value['rows'];
        }
        if (!is_array($value) || !array_is_list($value)) {
            return [false, null, 'ожидался массив строк таблицы'];
        }

        $rows = [];
        foreach ($value as $i => $row) {
            $n = $i + 1;
            if (!is_array($row)) {
                $warnings[] = "Поле «{$f['code']}», строка {$n}: не объект — пропущена";
                continue;
            }
            $clean = [];
            $filled = false;
            foreach ($f['columns'] as $col) {
                $warn = '';
                $cell = self::tableCell($col, $row[$col['code']] ?? null, $refSets, $warn);
                if ($warn !== '') {
                    $warnings[] = "Поле «{$f['code']}», строка {$n}, «{$col['code']}»: {$warn}";
                }
                $clean[$col['code']] = $cell;
                $filled = $filled || $cell !== null;
            }
            if ($filled) {
                $rows[] = $clean;
            }
        }

        if ($f['max_items'] > 0 && count($rows) > $f['max_items']) {
            $warnings[] = "Поле «{$f['code']}»: оставлены первые {$f['max_items']} строк из " . count($rows);
            $rows = array_slice($rows, 0, $f['max_items']);
        }
        return [true, $rows, ''];
    }

    /**
     * Ячейка таблицы. Ошибки ячейки не валят ответ целиком: ячейка становится пустой
     * (для ID — остаётся только название), а в предупреждения пишется причина.
     */
    private static function tableCell(array $col, mixed $raw, array $refSets, string &$warn): mixed
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        switch ($col['type']) {
            case 'string':
                if (!is_scalar($raw)) {
                    $warn = 'ожидалась строка';
                    return null;
                }
                $s = trim((string)$raw);
                return $s === '' ? null : $s;

            case 'number':
                if (is_int($raw) || is_float($raw)) {
                    return $raw;
                }
                $s = is_scalar($raw) ? self::numericString((string)$raw) : null;
                if ($s !== null) {
                    return (float)$s;
                }
                $warn = 'не число: ' . Json::encode($raw);
                return null;

            case 'date':
                $s = is_scalar($raw) ? trim((string)$raw) : '';
                if (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})/', $s, $m)) {
                    [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
                } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
                    [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
                } else {
                    $warn = 'не дата: ' . Json::encode($raw);
                    return null;
                }
                if (!checkdate($mo, $d, $y)) {
                    $warn = 'несуществующая дата: ' . $s;
                    return null;
                }
                return sprintf('%02d.%02d.%04d', $d, $mo, $y);

            case 'ref':
                $id = null;
                $title = '';
                if (is_array($raw)) {
                    $id = self::toInt($raw['id'] ?? null);
                    $title = is_scalar($raw['title'] ?? null) ? trim((string)$raw['title']) : '';
                } elseif (is_int($raw)) {
                    $id = $raw;
                } elseif (is_scalar($raw)) {
                    $title = trim((string)$raw);
                }
                if ($id !== null && $id <= 0) {
                    $id = null;
                }
                if ($id !== null && !isset($refSets[$col['source_id']][$id])) {
                    $warn = "ID {$id} нет среди переданных модели — оставлено только название";
                    $id = null;
                }
                if ($id === null && $title === '') {
                    return null;
                }
                return ['id' => $id, 'title' => $title];
        }
        return null;
    }

    /**
     * «12,300» / «₸61,500» / «1 234 567,89» / «1,234,567.89» → «12300» / «61500» / «1234567.89».
     * Разделитель, за которым до конца ровно 3 цифры, — тысячи; иначе — дробная часть.
     */
    public static function numericString(string $raw): ?string
    {
        $s = str_replace(["\u{00A0}", "\u{202F}", ' ', "'", '₸', 'KZT', 'тг'], '', trim($raw));
        if ($s === '') {
            return null;
        }
        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');
        if ($lastComma !== false && $lastDot !== false) {
            $decimal = $lastComma > $lastDot ? ',' : '.';
            $thousand = $decimal === ',' ? '.' : ',';
            $s = str_replace($thousand, '', $s);
            $s = str_replace($decimal, '.', $s);
        } elseif ($lastComma !== false || $lastDot !== false) {
            $sep = $lastComma !== false ? ',' : '.';
            if (preg_match('/^-?\d{1,3}(' . preg_quote($sep, '/') . '\d{3})+$/', $s)) {
                $s = str_replace($sep, '', $s);
            } else {
                $s = str_replace(',', '.', $s);
            }
        }
        return is_numeric($s) ? $s : null;
    }

    private static function tableText(array $f, array $sourceTitles): string
    {
        $parts = [];
        foreach ($f['columns'] as $col) {
            $what = match ($col['type']) {
                'string' => 'строка',
                'number' => 'число',
                'date'   => 'дата ДД.ММ.ГГГГ',
                'ref'    => 'объект {"id": ID строки из раздела «'
                    . ($sourceTitles[$col['source_id']] ?? ('источник #' . $col['source_id']))
                    . '» или null, если подходящей строки нет; "title": название}',
            };
            $line = '"' . $col['code'] . '" — ' . $what . ' или null';
            if ($col['description'] !== '') {
                $line .= ' (' . $col['description'] . ')';
            }
            $parts[] = $line;
        }
        return 'массив строк таблицы' . ($f['max_items'] > 0 ? ' (не больше ' . $f['max_items'] . ')' : '')
            . '; каждая строка — объект с полями: ' . implode('; ', $parts)
            . '. Пустой массив [] — если строк нет';
    }

    private static function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int)$value;
        }
        if (is_string($value) && preg_match('/^\s*-?\d+\s*$/', $value)) {
            return (int)trim($value);
        }
        return null;
    }

    private static function rowSchema(array $columns): array
    {
        $props = [];
        foreach ($columns as $col) {
            $props[$col['code']] = match ($col['type']) {
                'string', 'date' => ['type' => ['string', 'null']],
                'number'         => ['type' => ['number', 'null']],
                'ref'            => [
                    'type'                 => 'object',
                    'properties'           => [
                        'id'    => ['type' => ['integer', 'null']],
                        'title' => ['type' => 'string'],
                    ],
                    'required'             => ['id', 'title'],
                    'additionalProperties' => false,
                ],
            };
            if ($col['description'] !== '') {
                $props[$col['code']]['description'] = $col['description'];
            }
        }
        return [
            'type'                 => 'object',
            'properties'           => (object)$props,
            'required'             => array_column($columns, 'code'),
            'additionalProperties' => false,
        ];
    }

    private static function fieldSchema(array $f): array
    {
        $schema = match ($f['type']) {
            'string'          => ['type' => 'string'],
            'integer', 'ref'  => ['type' => 'integer'],
            'number'          => ['type' => 'number'],
            'boolean'         => ['type' => 'boolean'],
            'enum'            => ['type' => 'string', 'enum' => $f['enum']],
            'ref_list'        => ['type' => 'array', 'items' => ['type' => 'integer']],
            'table'           => ['type' => 'array', 'items' => self::rowSchema($f['columns'])],
        };
        if (!$f['required']) {
            $schema['type'] = [$schema['type'], 'null'];
            if (isset($schema['enum'])) {
                $schema['enum'][] = null;
            }
        }
        if ($f['description'] !== '') {
            $schema['description'] = $f['description'];
        }
        return $schema;
    }
}
