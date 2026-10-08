<?php
namespace Local\AiLab\Admin;

use Bitrix\Main\Type\DateTime;
use Local\AiLab\Data\EntityCatalog;
use Local\AiLab\Data\EntityReader;
use Local\AiLab\Data\FilterBuilder;
use Local\AiLab\Json;
use Local\AiLab\Model\ScenarioTable;
use Local\AiLab\Model\SourceTable;
use Local\AiLab\Scenario\SourceDef;
use Local\AiLab\Scenario\SourceException;
use Local\AiLab\Scenario\Source\SqlGuard;

/** Форма источника: проверка, сохранение и сборка SourceDef для предпросмотра без сохранения. */
final class SourceService
{
    public const BLANK_FILTER_ROWS = 3;

    public static function defaults(): array
    {
        return [
            'TITLE'       => '',
            'DESCRIPTION' => '',
            'SORT'        => '100',
            'ACTIVE'      => 'Y',
            'ENTITY'      => '',
            'FIELDS'      => [],
            'FILTER'      => [],
            'ORDER_FIELD' => 'ID',
            'ORDER_DIR'   => 'ASC',
            'LIMIT'       => '500',
            'SQL'         => '',
            'ID_COLUMN'   => 'ID',
            'CONTENT'     => '',
        ];
    }

    public static function rowToForm(array $row): array
    {
        $def = SourceDef::fromRow($row);
        $c = $def->config;
        return [
            'TITLE'       => $def->title,
            'DESCRIPTION' => $def->description,
            'SORT'        => (string)$def->sort,
            'ACTIVE'      => $def->active ? 'Y' : '',
            'ENTITY'      => (string)($c['entity'] ?? ''),
            'FIELDS'      => array_values((array)($c['fields'] ?? [])),
            'FILTER'      => array_values((array)($c['filter'] ?? [])),
            'ORDER_FIELD' => (string)($c['order_field'] ?? 'ID'),
            'ORDER_DIR'   => (string)($c['order_dir'] ?? 'ASC'),
            'LIMIT'       => (string)($c['limit'] ?? '500'),
            'SQL'         => (string)($c['sql'] ?? ''),
            'ID_COLUMN'   => (string)($c['id_column'] ?? 'ID'),
            'CONTENT'     => (string)($c['content'] ?? ''),
        ];
    }

    /** Проверяет форму и собирает источник. null — есть ошибки. */
    public static function buildDef(int $id, string $type, array $in, array &$errors): ?SourceDef
    {
        if (!isset(SourceDef::TYPES[$type])) {
            $errors[] = 'Неизвестный тип источника.';
            return null;
        }

        $title = trim((string)($in['TITLE'] ?? ''));
        if ($title === '') {
            $errors[] = 'Укажите заголовок: под ним данные увидит модель, например «Статьи расходов».';
        }

        $config = match ($type) {
            'entity' => self::entityConfig($in, $errors),
            'sql'    => self::sqlConfig($in, $errors),
            'static' => self::staticConfig($in, $errors),
        };

        if ($errors) {
            return null;
        }
        return new SourceDef(
            $id,
            $type,
            $title,
            trim((string)($in['DESCRIPTION'] ?? '')),
            $config,
            (int)($in['SORT'] ?? 100),
            ($in['ACTIVE'] ?? '') === 'Y',
        );
    }

    /** @return array{0: int, 1: string[]} */
    public static function save(int $scenarioId, int $id, string $type, array $in): array
    {
        $errors = [];
        if ($scenarioId <= 0 || !ScenarioTable::getById($scenarioId)->fetch()) {
            return [$id, ['Сценарий не найден.']];
        }
        if ($id > 0) {
            $row = SourceTable::getById($id)->fetch();
            if (!$row || (int)$row['SCENARIO_ID'] !== $scenarioId) {
                return [$id, ['Источник не найден.']];
            }
            $type = (string)$row['TYPE'];
        }

        $def = self::buildDef($id, $type, $in, $errors);
        if ($def === null) {
            return [$id, $errors];
        }

        $data = [
            'SCENARIO_ID' => $scenarioId,
            'SORT'        => $def->sort,
            'TYPE'        => $def->type,
            'TITLE'       => $def->title,
            'DESCRIPTION' => $def->description,
            'CONFIG'      => Json::encode($def->config),
            'ACTIVE'      => $def->active ? 'Y' : 'N',
            'UPDATED_AT'  => new DateTime(),
        ];
        if ($id > 0) {
            $result = SourceTable::update($id, $data);
        } else {
            $data['CREATED_AT'] = new DateTime();
            $result = SourceTable::add($data);
            $id = (int)$result->getId();
        }
        return $result->isSuccess() ? [$id, []] : [$id, $result->getErrorMessages()];
    }

    /** @return int ID сценария, к которому относился источник */
    public static function delete(int $id): int
    {
        $row = $id > 0 ? SourceTable::getById($id)->fetch() : false;
        if (!$row) {
            throw new \RuntimeException('Источник не найден');
        }
        $result = SourceTable::delete($id);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }
        return (int)$row['SCENARIO_ID'];
    }

    private static function entityConfig(array $in, array &$errors): array
    {
        $key = (string)($in['ENTITY'] ?? '');
        if ($key === '' || !EntityCatalog::exists($key)) {
            $errors[] = 'Выберите, откуда брать элементы.';
            return [];
        }
        try {
            $fields = EntityCatalog::fields($key);
        } catch (\Throwable $e) {
            $errors[] = 'Не удалось получить поля: ' . $e->getMessage();
            return [];
        }

        $chosen = array_values(array_filter(
            array_map('strval', (array)($in['FIELDS'] ?? [])),
            static fn($code) => isset($fields[$code]) && $code !== 'ID'
        ));
        if (!$chosen) {
            $errors[] = 'Отметьте хотя бы одно поле, кроме ID.';
        }

        $filter = [];
        foreach ((array)($in['FILTER'] ?? []) as $row) {
            $field = (string)($row['field'] ?? '');
            if ($field === '') {
                continue;
            }
            $op = (string)($row['op'] ?? '=');
            $value = trim((string)($row['value'] ?? ''));
            if (!isset($fields[$field])) {
                $errors[] = "Фильтр: поля {$field} нет в выбранной сущности.";
                continue;
            }
            if (!isset(FilterBuilder::OPS[$op])) {
                $errors[] = 'Фильтр: неизвестное условие.';
                continue;
            }
            if ($value === '' && !in_array($op, ['empty', 'not_empty'], true)) {
                $errors[] = "Фильтр по полю «{$fields[$field]->title}»: укажите значение или :параметр.";
                continue;
            }
            $filter[] = ['field' => $field, 'op' => $op, 'value' => $value];
        }

        $orderField = (string)($in['ORDER_FIELD'] ?? 'ID');
        return [
            'entity'      => $key,
            'fields'      => $chosen,
            'filter'      => $filter,
            'order_field' => isset($fields[$orderField]) ? $orderField : 'ID',
            'order_dir'   => strtoupper((string)($in['ORDER_DIR'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC',
            'limit'       => self::limit($in['LIMIT'] ?? ''),
        ];
    }

    private static function sqlConfig(array $in, array &$errors): array
    {
        $sql = trim((string)($in['SQL'] ?? ''));
        try {
            SqlGuard::check($sql);
        } catch (SourceException $e) {
            $errors[] = $e->getMessage();
        }
        return [
            'sql'       => $sql,
            'id_column' => trim((string)($in['ID_COLUMN'] ?? 'ID')),
            'limit'     => self::limit($in['LIMIT'] ?? ''),
        ];
    }

    private static function staticConfig(array $in, array &$errors): array
    {
        $content = trim((string)($in['CONTENT'] ?? ''));
        if ($content === '') {
            $errors[] = 'Вставьте текст источника.';
        }
        return ['content' => $content];
    }

    private static function limit(mixed $raw): int
    {
        $raw = trim((string)$raw);
        return preg_match('/^\d+$/', $raw) ? max(1, min(EntityReader::MAX_LIMIT, (int)$raw)) : 500;
    }
}
