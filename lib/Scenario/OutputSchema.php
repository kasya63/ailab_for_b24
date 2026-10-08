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
        ];
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
        }
        return [false, null, 'неизвестный тип'];
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

    private static function fieldSchema(array $f): array
    {
        $schema = match ($f['type']) {
            'string'          => ['type' => 'string'],
            'integer', 'ref'  => ['type' => 'integer'],
            'number'          => ['type' => 'number'],
            'boolean'         => ['type' => 'boolean'],
            'enum'            => ['type' => 'string', 'enum' => $f['enum']],
            'ref_list'        => ['type' => 'array', 'items' => ['type' => 'integer']],
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
