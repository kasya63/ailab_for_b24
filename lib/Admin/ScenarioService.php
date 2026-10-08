<?php
namespace Local\AiLab\Admin;

use Bitrix\Main\Type\DateTime;
use Local\AiLab\Json;
use Local\AiLab\Model\ConnectionTable;
use Local\AiLab\Model\ScenarioTable;
use Local\AiLab\Model\SourceTable;
use Local\AiLab\Scenario\OutputSchema;
use Local\AiLab\Scenario\SourceDef;

/** Проверка и сохранение формы сценария (основное + формат ответа). */
final class ScenarioService
{
    public const BLANK_FIELD_ROWS = 3;

    public static function defaults(): array
    {
        return [
            'NAME'             => '',
            'CODE'             => '',
            'CONNECTION_ID'    => '',
            'MODEL'            => '',
            'MAX_TOKENS'       => '0',
            'INSTRUCTION'      => '',
            'DAILY_LIMIT'      => '500',
            'MAX_PROMPT_CHARS' => '300000',
            'ACTIVE'           => 'Y',
            'OF'               => [],
        ];
    }

    public static function rowToForm(array $row): array
    {
        $rows = [];
        foreach (OutputSchema::fromJson($row['OUTPUT_FIELDS'])->fields() as $i => $f) {
            $rows[] = [
                'sort'        => (string)(($i + 1) * 10),
                'code'        => $f['code'],
                'type'        => $f['type'],
                'description' => $f['description'],
                'required'    => $f['required'] ? 'Y' : '',
                'enum'        => implode("\n", $f['enum']),
                'source_id'   => (string)$f['source_id'],
                'allow_zero'  => $f['allow_zero'] ? 'Y' : '',
                'max_items'   => $f['max_items'] ? (string)$f['max_items'] : '',
            ];
        }
        return [
            'NAME'             => (string)$row['NAME'],
            'CODE'             => (string)$row['CODE'],
            'CONNECTION_ID'    => (string)(int)$row['CONNECTION_ID'],
            'MODEL'            => (string)$row['MODEL'],
            'MAX_TOKENS'       => (string)(int)$row['MAX_TOKENS'],
            'INSTRUCTION'      => (string)$row['INSTRUCTION'],
            'DAILY_LIMIT'      => (string)(int)$row['DAILY_LIMIT'],
            'MAX_PROMPT_CHARS' => (string)(int)$row['MAX_PROMPT_CHARS'],
            'ACTIVE'           => $row['ACTIVE'] === 'Y' ? 'Y' : '',
            'OF'               => $rows,
        ];
    }

    /** @return array<int, string> ID источника => заголовок; только источники, которые дают ID */
    public static function refSourceOptions(int $scenarioId): array
    {
        $options = [];
        if ($scenarioId <= 0) {
            return $options;
        }
        $rs = SourceTable::getList(['filter' => ['=SCENARIO_ID' => $scenarioId], 'order' => ['SORT' => 'ASC', 'ID' => 'ASC']]);
        while ($row = $rs->fetch()) {
            if (SourceDef::fromRow($row)->providesIds()) {
                $options[(int)$row['ID']] = (string)$row['TITLE'];
            }
        }
        return $options;
    }

    /** @return array{0: int, 1: string[]} */
    public static function save(array $in): array
    {
        $id = max(0, (int)($in['ID'] ?? 0));
        $errors = [];

        $name = trim((string)($in['NAME'] ?? ''));
        if ($name === '') {
            $errors[] = 'Укажите название сценария.';
        }

        $code = trim((string)($in['CODE'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $code)) {
            $errors[] = 'Код сценария: латиница в нижнем регистре, цифры и «_», начинается с буквы, например expense_check.';
        } else {
            $filter = ['=CODE' => $code];
            if ($id > 0) {
                $filter['!=ID'] = $id;
            }
            if (ScenarioTable::getList(['filter' => $filter, 'select' => ['ID'], 'limit' => 1])->fetch()) {
                $errors[] = "Код «{$code}» уже занят другим сценарием.";
            }
        }

        $connectionId = (int)($in['CONNECTION_ID'] ?? 0);
        if ($connectionId <= 0 || !ConnectionTable::getById($connectionId)->fetch()) {
            $errors[] = 'Выберите подключение к ИИ.';
        }

        $instruction = trim((string)($in['INSTRUCTION'] ?? ''));
        if ($instruction === '') {
            $errors[] = 'Напишите инструкцию: что модель должна сделать с данными.';
        }

        if ($id > 0 && !ScenarioTable::getById($id)->fetch()) {
            $errors[] = "Сценарий #{$id} не найден.";
        }

        $fields = self::parseOutputFields((array)($in['OF'] ?? []), self::refSourceOptions($id), $errors);

        if ($errors) {
            return [$id, $errors];
        }

        $data = [
            'NAME'             => $name,
            'CODE'             => $code,
            'CONNECTION_ID'    => $connectionId,
            'MODEL'            => trim((string)($in['MODEL'] ?? '')),
            'MAX_TOKENS'       => self::intInRange($in['MAX_TOKENS'] ?? '', 0, 0, 64000),
            'INSTRUCTION'      => $instruction,
            'OUTPUT_FIELDS'    => Json::encode($fields),
            'DAILY_LIMIT'      => self::intInRange($in['DAILY_LIMIT'] ?? '', 500, 0, 100000),
            'MAX_PROMPT_CHARS' => self::intInRange($in['MAX_PROMPT_CHARS'] ?? '', 300000, 1000, 2000000),
            'ACTIVE'           => ($in['ACTIVE'] ?? '') === 'Y' ? 'Y' : 'N',
            'UPDATED_AT'       => new DateTime(),
        ];

        if ($id > 0) {
            $result = ScenarioTable::update($id, $data);
        } else {
            $data['CREATED_AT'] = new DateTime();
            $data['TEST_STATE'] = '';
            $result = ScenarioTable::add($data);
            $id = (int)$result->getId();
        }
        return $result->isSuccess() ? [$id, []] : [$id, $result->getErrorMessages()];
    }

    public static function delete(int $id): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('Не передан ID сценария');
        }
        $rs = SourceTable::getList(['select' => ['ID'], 'filter' => ['=SCENARIO_ID' => $id]]);
        while ($row = $rs->fetch()) {
            SourceTable::delete((int)$row['ID']);
        }
        $result = ScenarioTable::delete($id);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }
    }

    public static function testState(array $row): array
    {
        $state = json_decode((string)($row['TEST_STATE'] ?? ''), true);
        return is_array($state) ? $state : [];
    }

    public static function saveTestState(int $id, array $state): void
    {
        ScenarioTable::update($id, ['TEST_STATE' => Json::encode($state)]);
    }

    /** @return list<array> поля для OutputSchema */
    private static function parseOutputFields(array $rows, array $refSources, array &$errors): array
    {
        $prepared = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row) || ($row['delete'] ?? '') === 'Y') {
                continue;
            }
            $code = trim((string)($row['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $prepared[] = ['sort' => (int)($row['sort'] ?? 0), 'index' => (int)$index, 'row' => $row, 'code' => $code];
        }
        usort($prepared, static fn($a, $b) => [$a['sort'], $a['index']] <=> [$b['sort'], $b['index']]);

        $fields = [];
        $seen = [];
        foreach ($prepared as $item) {
            $row = $item['row'];
            $code = $item['code'];
            $type = (string)($row['type'] ?? 'string');

            if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $code)) {
                $errors[] = "Поле ответа «{$code}»: код — латиница в нижнем регистре, цифры и «_», до 40 символов.";
                continue;
            }
            if (in_array($code, OutputSchema::RESERVED, true)) {
                $errors[] = "Поле ответа «{$code}»: это имя занято служебным результатом кубика.";
                continue;
            }
            if (isset($seen[$code])) {
                $errors[] = "Поле ответа «{$code}» указано дважды.";
                continue;
            }
            if (!isset(OutputSchema::TYPES[$type])) {
                $errors[] = "Поле ответа «{$code}»: выберите тип.";
                continue;
            }
            $seen[$code] = true;

            $enum = preg_split('/\R/u', (string)($row['enum'] ?? '')) ?: [];
            $field = OutputSchema::normalizeField([
                'code'        => $code,
                'type'        => $type,
                'description' => (string)($row['description'] ?? ''),
                'required'    => ($row['required'] ?? '') === 'Y',
                'enum'        => $enum,
                'source_id'   => (int)($row['source_id'] ?? 0),
                'allow_zero'  => ($row['allow_zero'] ?? '') === 'Y',
                'max_items'   => (int)($row['max_items'] ?? 0),
            ]);

            if ($type === 'enum' && !$field['enum']) {
                $errors[] = "Поле ответа «{$code}»: перечислите варианты, по одному в строке.";
            }
            if (in_array($type, ['ref', 'ref_list'], true) && !isset($refSources[$field['source_id']])) {
                $errors[] = $refSources
                    ? "Поле ответа «{$code}»: выберите источник, из которого берутся ID."
                    : "Поле ответа «{$code}»: ссылаться можно только на источник с ID (элементы CRM/списка или SQL). Сначала добавьте его на вкладке «Источники».";
            }
            if (!in_array($type, ['ref', 'ref_list'], true)) {
                $field['source_id'] = 0;
            }
            $fields[] = $field;
        }
        return $fields;
    }

    private static function intInRange(mixed $raw, int $default, int $min, int $max): int
    {
        $raw = trim((string)$raw);
        return preg_match('/^\d+$/', $raw) ? max($min, min($max, (int)$raw)) : $default;
    }
}
