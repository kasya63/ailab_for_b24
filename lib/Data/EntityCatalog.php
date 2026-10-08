<?php
namespace Local\AiLab\Data;

use Bitrix\Crm\Model\Dynamic\TypeTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;

/**
 * Какие сущности можно читать (CRM, смарт-процессы, списки, HL-блоки) и какие у них поля.
 * Ключ сущности: crm:<entityTypeId>, iblock:<IBLOCK_ID> или hl:<ID HL-блока>.
 * Поля списков: стандартные по коду (NAME, CREATED_BY…), свойства — PROPERTY_<ID>.
 * Поля HL-блоков: ID и пользовательские поля HLBLOCK_<ID> (UF_*).
 */
final class EntityCatalog
{
    private const LIST_IBLOCK_TYPES = ['lists', 'bitrix_processes', 'lists_socnet'];

    /** Поля CRM, которые не читаются как обычные значения. */
    private const CRM_SKIP = ['PRODUCT_ROWS'];

    private const IBLOCK_STD = [
        'ID'                => ['ID', 'integer'],
        'NAME'              => ['Название', 'string'],
        'CODE'              => ['Символьный код', 'string'],
        'ACTIVE'            => ['Активность', 'boolean'],
        'DATE_CREATE'       => ['Дата создания', 'datetime'],
        'TIMESTAMP_X'       => ['Дата изменения', 'datetime'],
        'CREATED_BY'        => ['Кем создан', 'user'],
        'MODIFIED_BY'       => ['Кем изменён', 'user'],
        'IBLOCK_SECTION_ID' => ['Раздел', 'iblock_section'],
        'PREVIEW_TEXT'      => ['Описание для анонса', 'text'],
        'DETAIL_TEXT'       => ['Детальное описание', 'text'],
    ];

    private static ?array $entities = null;
    private static array $fields = [];

    /** @return array<string, array{title: string, group: string}> */
    public static function entities(): array
    {
        if (self::$entities !== null) {
            return self::$entities;
        }
        $out = [];

        if (Loader::includeModule('crm')) {
            $types = [\CCrmOwnerType::Lead, \CCrmOwnerType::Deal, \CCrmOwnerType::Contact, \CCrmOwnerType::Company];
            if (defined('CCrmOwnerType::SmartInvoice')) {
                $types[] = \CCrmOwnerType::SmartInvoice;
            }
            foreach ($types as $typeId) {
                $factory = Container::getInstance()->getFactory($typeId);
                if ($factory) {
                    $out['crm:' . $typeId] = ['title' => (string)$factory->getEntityDescription(), 'group' => 'CRM'];
                }
            }
            $rows = TypeTable::getList(['select' => ['ENTITY_TYPE_ID', 'TITLE'], 'order' => ['TITLE' => 'ASC']])->fetchAll();
            foreach ($rows as $row) {
                $typeId = (int)$row['ENTITY_TYPE_ID'];
                $out['crm:' . $typeId] = ['title' => $row['TITLE'] . ' (' . $typeId . ')', 'group' => 'Смарт-процессы'];
            }
        }

        if (Loader::includeModule('iblock')) {
            foreach (self::LIST_IBLOCK_TYPES as $iblockType) {
                $rs = \CIBlock::GetList(['NAME' => 'ASC'], ['TYPE' => $iblockType, 'CHECK_PERMISSIONS' => 'N']);
                while ($iblock = $rs->Fetch()) {
                    $out['iblock:' . (int)$iblock['ID']] = [
                        'title' => $iblock['NAME'] . ' (ID ' . $iblock['ID'] . ')',
                        'group' => 'Списки',
                    ];
                }
            }
        }

        if (Loader::includeModule('highloadblock')) {
            $names = [];
            if (class_exists(\Bitrix\Highloadblock\HighloadBlockLangTable::class)) {
                $lang = defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru';
                foreach (\Bitrix\Highloadblock\HighloadBlockLangTable::getList(['filter' => ['=LID' => $lang]])->fetchAll() as $row) {
                    $names[(int)$row['ID']] = trim((string)$row['NAME']);
                }
            }
            $blocks = \Bitrix\Highloadblock\HighloadBlockTable::getList(['select' => ['ID', 'NAME'], 'order' => ['NAME' => 'ASC']])->fetchAll();
            foreach ($blocks as $block) {
                $id = (int)$block['ID'];
                $title = ($names[$id] ?? '') !== '' ? $names[$id] . ' — ' . $block['NAME'] : (string)$block['NAME'];
                $out['hl:' . $id] = ['title' => $title . ' (HL ' . $id . ')', 'group' => 'Highload-блоки'];
            }
        }

        return self::$entities = $out;
    }

    /** @return array{0: string, 1: int} */
    public static function parseKey(string $key): array
    {
        if (!preg_match('/^(crm|iblock|hl):(\d+)$/', $key, $m)) {
            throw new \InvalidArgumentException('Неверный ключ сущности: ' . $key);
        }
        return [$m[1], (int)$m[2]];
    }

    public static function exists(string $key): bool
    {
        return isset(self::entities()[$key]);
    }

    public static function title(string $key): string
    {
        return self::entities()[$key]['title'] ?? $key;
    }

    /** @return array<string, FieldMeta> */
    public static function fields(string $key): array
    {
        if (!isset(self::$fields[$key])) {
            [$kind, $id] = self::parseKey($key);
            self::$fields[$key] = match ($kind) {
                'crm'    => self::crmFields($id),
                'iblock' => self::iblockFields($id),
                'hl'     => self::hlFields($id),
            };
        }
        return self::$fields[$key];
    }

    /** @return array<string, FieldMeta> */
    private static function crmFields(int $entityTypeId): array
    {
        if (!Loader::includeModule('crm')) {
            throw new \RuntimeException('Модуль crm не подключен');
        }
        $factory = Container::getInstance()->getFactory($entityTypeId);
        if (!$factory) {
            throw new \RuntimeException('Нет CRM-сущности с entityTypeId=' . $entityTypeId);
        }

        $out = [];
        foreach ($factory->getFieldsCollection() as $field) {
            $code = (string)$field->getName();
            if ($code === '' || in_array($code, self::CRM_SKIP, true)) {
                continue;
            }
            $type = (string)$field->getType();
            $settings = ['entityTypeId' => $entityTypeId];

            if ($field->isUserField()) {
                $uf = (array)$field->getUserField();
                $type = (string)($uf['USER_TYPE_ID'] ?? $type);
                $settings['ufId'] = (int)($uf['ID'] ?? 0);
                $settings['ufSettings'] = (array)($uf['SETTINGS'] ?? []);
            } elseif (method_exists($field, 'getSettings')) {
                $settings += (array)$field->getSettings();
            }

            $title = (string)$field->getTitle();
            $out[$code] = new FieldMeta($code, $title !== '' ? $title : $code, $type, (bool)$field->isMultiple(), $settings);
        }
        return $out;
    }

    /** @return array<string, FieldMeta> */
    private static function iblockFields(int $iblockId): array
    {
        if (!Loader::includeModule('iblock')) {
            throw new \RuntimeException('Модуль iblock не подключен');
        }

        $out = [];
        foreach (self::IBLOCK_STD as $code => [$title, $type]) {
            $out[$code] = new FieldMeta($code, $title, $type, false, ['iblockId' => $iblockId]);
        }

        $rs = \CIBlockProperty::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y']);
        while ($p = $rs->Fetch()) {
            $code = 'PROPERTY_' . (int)$p['ID'];
            $out[$code] = new FieldMeta($code, (string)$p['NAME'], self::iblockPropertyType($p), $p['MULTIPLE'] === 'Y', [
                'iblockId'         => $iblockId,
                'propId'           => (int)$p['ID'],
                'linkIblockId'     => (int)$p['LINK_IBLOCK_ID'],
                'userTypeSettings' => self::unserializeSettings($p['USER_TYPE_SETTINGS'] ?? null),
            ]);
        }
        return $out;
    }

    /** @return array<string, FieldMeta> */
    private static function hlFields(int $hlId): array
    {
        if (!Loader::includeModule('highloadblock')) {
            throw new \RuntimeException('Модуль highloadblock не подключен');
        }
        global $USER_FIELD_MANAGER;
        $out = ['ID' => new FieldMeta('ID', 'ID', 'integer', false, ['hlId' => $hlId])];
        $lang = defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru';
        foreach ((array)$USER_FIELD_MANAGER->GetUserFields('HLBLOCK_' . $hlId, 0, $lang) as $code => $uf) {
            $title = trim((string)($uf['EDIT_FORM_LABEL'] ?? '')) ?: trim((string)($uf['LIST_COLUMN_LABEL'] ?? '')) ?: (string)$code;
            $out[(string)$code] = new FieldMeta((string)$code, $title, (string)$uf['USER_TYPE_ID'], ($uf['MULTIPLE'] ?? 'N') === 'Y', [
                'hlId'       => $hlId,
                'ufId'       => (int)($uf['ID'] ?? 0),
                'ufSettings' => (array)($uf['SETTINGS'] ?? []),
            ]);
        }
        return $out;
    }

    private static function iblockPropertyType(array $p): string
    {
        $userType = (string)($p['USER_TYPE'] ?? '');
        return match ((string)$p['PROPERTY_TYPE']) {
            'S' => match ($userType) {
                'HTML'      => 'html',
                'Date'      => 'date',
                'DateTime'  => 'datetime',
                'employee'  => 'user',
                'ECrm'      => 'crm',
                'Money'     => 'money',
                'DiskFile'  => 'disk_file',
                default     => 'string',
            },
            'N' => 'number',
            'L' => 'iblock_enum',
            'E' => 'iblock_element',
            'G' => 'iblock_section',
            'F' => 'file',
            default => 'string',
        };
    }

    private static function unserializeSettings(mixed $settings): array
    {
        if (is_string($settings) && $settings !== '') {
            $settings = @unserialize($settings, ['allowed_classes' => false]);
        }
        return is_array($settings) ? $settings : [];
    }
}
