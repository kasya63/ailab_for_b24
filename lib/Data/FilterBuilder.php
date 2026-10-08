<?php
namespace Local\AiLab\Data;

use Local\AiLab\Scenario\Params;
use Local\AiLab\Scenario\SourceException;

/** Строки фильтра из админки → фильтр CRM (ORM) или списков (CIBlockElement). */
final class FilterBuilder
{
    public const OPS = [
        '='         => 'равно',
        '!='        => 'не равно',
        '>'         => 'больше',
        '>='        => 'не меньше',
        '<'         => 'меньше',
        '<='        => 'не больше',
        'contains'  => 'содержит',
        'in'        => 'одно из, через запятую',
        'empty'     => 'пусто',
        'not_empty' => 'заполнено',
    ];

    /** Фильтр ORM D7: CRM (фабрика) и HL-блоки. */
    public static function orm(array $rows, array $fields, array $params): array
    {
        return self::crm($rows, $fields, $params);
    }

    /**
     * @param list<array{field: string, op: string, value: string}> $rows
     * @param array<string, FieldMeta> $fields
     */
    public static function crm(array $rows, array $fields, array $params): array
    {
        $filter = [];
        foreach ($rows as $row) {
            [$code, $op, $meta] = self::check($row, $fields);
            switch ($op) {
                case 'empty':
                    $filter[] = $meta->isTextual()
                        ? ['LOGIC' => 'OR', ['=' . $code => null], ['=' . $code => '']]
                        : ['=' . $code => null];
                    break;
                case 'not_empty':
                    $filter[] = $meta->isTextual()
                        ? [['!=' . $code => null], ['!=' . $code => '']]
                        : ['!=' . $code => null];
                    break;
                case 'in':
                    $filter[] = ['@' . $code => self::splitList(Params::resolve($row['value'], $params))];
                    break;
                case 'contains':
                    $filter[] = ['%' . $code => Params::resolve($row['value'], $params)];
                    break;
                default:
                    $filter[] = [$op . $code => Params::resolve($row['value'], $params)];
            }
        }
        return $filter;
    }

    /**
     * @param list<array{field: string, op: string, value: string}> $rows
     * @param array<string, FieldMeta> $fields
     */
    public static function iblock(array $rows, array $fields, array $params): array
    {
        $filter = [];
        foreach ($rows as $row) {
            [$code, $op] = self::check($row, $fields);
            $filter[] = match ($op) {
                'empty'     => [$code => false],
                'not_empty' => ['!' . $code => false],
                'in'        => [$code => self::splitList(Params::resolve($row['value'], $params))],
                'contains'  => ['%' . $code => Params::resolve($row['value'], $params)],
                '!='        => ['!' . $code => Params::resolve($row['value'], $params)],
                default     => [$op . $code => Params::resolve($row['value'], $params)],
            };
        }
        return $filter;
    }

    /** @return array{0: string, 1: string, 2: FieldMeta} */
    private static function check(array $row, array $fields): array
    {
        $code = (string)($row['field'] ?? '');
        $op = (string)($row['op'] ?? '=');
        if (!isset($fields[$code])) {
            throw new SourceException('Поле фильтра не найдено: ' . $code);
        }
        if (!isset(self::OPS[$op])) {
            throw new SourceException('Неизвестное условие фильтра: ' . $op);
        }
        return [$code, $op, $fields[$code]];
    }

    /** @return string[] */
    private static function splitList(string $value): array
    {
        $items = array_values(array_filter(array_map('trim', explode(',', $value)), static fn($v) => $v !== ''));
        if (!$items) {
            throw new SourceException('Для условия «одно из» нужен хотя бы один вариант');
        }
        return $items;
    }
}
