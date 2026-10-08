<?php
namespace Local\AiLab\Data;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use Local\AiLab\Scenario\SourceException;

/**
 * Читает элементы CRM / смарт-процесса / списка и сразу переводит значения в текст.
 * Берёт на одну строку больше лимита, чтобы честно сообщить о переполнении, а не обрезать молча.
 */
final class EntityReader
{
    public const MAX_LIMIT = 5000;

    /**
     * @param string[] $codes поля для чтения (ID добавляется всегда)
     * @param list<array{field: string, op: string, value: string}> $filterRows
     * @return array{rows: list<array<string, string>>, raw: list<array<string, mixed>>, ids: int[], overflow: bool, fields: array<string, FieldMeta>}
     */
    public static function read(
        string $key,
        array $codes,
        array $filterRows,
        array $params,
        int $limit,
        string $orderField = 'ID',
        string $orderDir = 'ASC',
    ): array {
        [$kind, $id] = EntityCatalog::parseKey($key);
        $fields = EntityCatalog::fields($key);

        $codes = array_values(array_unique(array_filter($codes, static fn($c) => $c !== 'ID')));
        foreach ($codes as $code) {
            if (!isset($fields[$code])) {
                throw new SourceException(sprintf('Поле %s не найдено в «%s»', $code, EntityCatalog::title($key)));
            }
        }
        if (!isset($fields[$orderField])) {
            $orderField = 'ID';
        }
        $orderDir = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $raw = match ($kind) {
            'crm'    => self::readCrm($id, $codes, $fields, $filterRows, $params, $limit + 1, $orderField, $orderDir),
            'iblock' => self::readIblock($id, $codes, $fields, $filterRows, $params, $limit + 1, $orderField, $orderDir),
            'hl'     => self::readHl($id, $codes, $fields, $filterRows, $params, $limit + 1, $orderField, $orderDir),
        };

        $overflow = count($raw) > $limit;
        if ($overflow) {
            $raw = array_slice($raw, 0, $limit, true);
        }

        $rows = [];
        $rawRows = [];
        foreach ($raw as $itemId => $values) {
            $rawRows[] = $values;
            $row = ['ID' => (string)$itemId];
            foreach ($codes as $code) {
                $row[$code] = ValueFormatter::format($fields[$code], $values[$code] ?? null);
            }
            $rows[] = $row;
        }

        return [
            'rows'     => $rows,
            'raw'      => $rawRows,
            'ids'      => array_map('intval', array_keys($raw)),
            'overflow' => $overflow,
            'fields'   => array_intersect_key($fields, array_flip($codes)),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function readCrm(int $entityTypeId, array $codes, array $fields, array $filterRows, array $params, int $limit, string $orderField, string $orderDir): array
    {
        if (!Loader::includeModule('crm')) {
            throw new SourceException('Модуль crm не подключен');
        }
        $factory = Container::getInstance()->getFactory($entityTypeId);
        if (!$factory) {
            throw new SourceException('Нет CRM-сущности с entityTypeId=' . $entityTypeId);
        }

        $order = [$orderField => $orderDir];
        if ($orderField !== 'ID') {
            $order['ID'] = 'ASC';
        }

        try {
            $items = $factory->getItems([
                'select' => array_values(array_unique(array_merge(['ID'], $codes))),
                'filter' => FilterBuilder::crm($filterRows, $fields, $params),
                'order'  => $order,
                'limit'  => $limit,
            ]);
        } catch (SourceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new SourceException('CRM не отдал элементы: ' . $e->getMessage());
        }

        $out = [];
        foreach ($items as $item) {
            $values = [];
            foreach ($codes as $code) {
                $values[$code] = $item->get($code);
            }
            $out[(int)$item->getId()] = $values;
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private static function readHl(int $hlId, array $codes, array $fields, array $filterRows, array $params, int $limit, string $orderField, string $orderDir): array
    {
        $dataClass = self::hlDataClass($hlId);
        $order = [$orderField => $orderDir];
        if ($orderField !== 'ID') {
            $order['ID'] = 'ASC';
        }
        try {
            $rs = $dataClass::getList([
                'select' => array_values(array_unique(array_merge(['ID'], $codes))),
                'filter' => FilterBuilder::orm($filterRows, $fields, $params),
                'order'  => $order,
                'limit'  => $limit,
            ]);
        } catch (SourceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new SourceException('HL-блок не отдал записи: ' . $e->getMessage());
        }

        $out = [];
        while ($row = $rs->fetch()) {
            $values = [];
            foreach ($codes as $code) {
                $values[$code] = $row[$code] ?? null;
            }
            $out[(int)$row['ID']] = $values;
        }
        return $out;
    }

    /** ORM-класс HL-блока (кэшируется на время запроса). */
    public static function hlDataClass(int $hlId): string
    {
        static $classes = [];
        if (isset($classes[$hlId])) {
            return $classes[$hlId];
        }
        if (!Loader::includeModule('highloadblock')) {
            throw new SourceException('Модуль highloadblock не подключен');
        }
        $block = \Bitrix\Highloadblock\HighloadBlockTable::getById($hlId)->fetch();
        if (!$block) {
            throw new SourceException("HL-блок #{$hlId} не найден");
        }
        return $classes[$hlId] = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($block)->getDataClass();
    }

    /** @return array<int, array<string, mixed>> */
    private static function readIblock(int $iblockId, array $codes, array $fields, array $filterRows, array $params, int $limit, string $orderField, string $orderDir): array
    {
        if (!Loader::includeModule('iblock')) {
            throw new SourceException('Модуль iblock не подключен');
        }

        $standard = [];
        $properties = []; // ID свойства => код поля
        foreach ($codes as $code) {
            if (str_starts_with($code, 'PROPERTY_')) {
                $properties[(int)substr($code, 9)] = $code;
            } else {
                $standard[] = $code;
            }
        }

        $filter = array_merge(
            ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'],
            FilterBuilder::iblock($filterRows, $fields, $params)
        );
        $order = [$orderField => $orderDir];
        if ($orderField !== 'ID') {
            $order['ID'] = 'ASC';
        }

        $out = [];
        $rs = \CIBlockElement::GetList($order, $filter, false, ['nTopCount' => $limit], array_values(array_unique(array_merge(['ID', 'IBLOCK_ID'], $standard))));
        while ($el = $rs->Fetch()) {
            $values = [];
            foreach ($standard as $code) {
                $values[$code] = $el[$code] ?? null;
            }
            $out[(int)$el['ID']] = $values;
        }

        if ($properties && $out) {
            $propValues = [];
            \CIBlockElement::GetPropertyValuesArray($propValues, $iblockId, ['ID' => array_keys($out)], ['ID' => array_keys($properties)]);
            foreach ($propValues as $elementId => $elementProps) {
                foreach ((array)$elementProps as $pv) {
                    $propId = (int)($pv['ID'] ?? 0);
                    if (isset($properties[$propId], $out[$elementId])) {
                        $out[$elementId][$properties[$propId]] = $pv['VALUE'] ?? null;
                    }
                }
            }
        }
        return $out;
    }
}
