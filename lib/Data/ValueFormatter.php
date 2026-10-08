<?php
namespace Local\AiLab\Data;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\StatusTable;
use Bitrix\Main\UserTable;
use Local\AiLab\Json;

/**
 * Сырое значение поля → текст, понятный модели и человеку:
 * пользователь → ФИО и должность, вариант списка → его название, привязка → название элемента,
 * деньги → «150000 KZT», дата → 01.10.2026. Неизвестные типы выводятся как есть.
 * Справочные значения кэшируются на время запроса.
 */
final class ValueFormatter
{
    public const MAX_LENGTH = 2000;

    private static array $cache = [];

    public static function format(FieldMeta $field, mixed $raw): string
    {
        $values = (is_array($raw) && array_is_list($raw)) ? $raw : [$raw];

        $parts = [];
        foreach ($values as $value) {
            if ($value === null || $value === '' || $value === [] || ($value === false && $field->type !== 'boolean')) {
                continue;
            }
            $text = trim(self::one($field, $value));
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        return self::clip(implode('; ', $parts));
    }

    private static function one(FieldMeta $f, mixed $v): string
    {
        try {
            return match ($f->type) {
                'boolean'               => self::boolean($v),
                'date'                  => self::date($v, 'd.m.Y'),
                'datetime'              => self::date($v, 'd.m.Y H:i'),
                'user', 'employee'      => self::user((int)$v),
                'enumeration'           => self::userFieldEnum((int)($f->settings['ufId'] ?? 0), $v),
                'iblock_enum'           => self::iblockEnum($v),
                'crm'                   => self::crmBinding($v, (array)($f->settings['ufSettings'] ?? $f->settings['userTypeSettings'] ?? [])),
                'crm_company'           => self::crmItem(\CCrmOwnerType::Company, (int)$v),
                'crm_contact'           => self::crmItem(\CCrmOwnerType::Contact, (int)$v),
                'crm_status'            => self::status((string)$v, (string)($f->settings['statusEntityId'] ?? '')),
                'crm_category'          => self::category((int)($f->settings['entityTypeId'] ?? 0), (int)$v),
                'iblock_element'        => self::iblockElement((int)$v),
                'hlblock'               => self::hlElement((array)($f->settings['ufSettings'] ?? []), (int)$v),
                'iblock_section'        => self::iblockSection((int)$v),
                'file'                  => self::file((int)$v),
                'money'                 => self::money((string)$v),
                'html', 'text'          => self::plainText($v),
                'address'               => explode('|', (string)$v)[0],
                default                 => self::scalar($v),
            };
        } catch (\Throwable) {
            return self::scalar($v);
        }
    }

    private static function boolean(mixed $v): string
    {
        return in_array($v, [true, 1, '1', 'Y', 'y', 'true'], true) ? 'да' : 'нет';
    }

    private static function date(mixed $v, string $format): string
    {
        if ($v instanceof \Bitrix\Main\Type\Date || $v instanceof \DateTimeInterface) {
            return $v->format($format);
        }
        return (string)$v;
    }

    private static function user(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        return self::$cache['user'][$id] ??= (static function () use ($id): string {
            $u = UserTable::getList([
                'select' => ['ID', 'NAME', 'LAST_NAME', 'LOGIN', 'WORK_POSITION'],
                'filter' => ['=ID' => $id],
                'limit'  => 1,
            ])->fetch();
            if (!$u) {
                return 'пользователь #' . $id;
            }
            $name = trim($u['LAST_NAME'] . ' ' . $u['NAME']);
            $name = $name !== '' ? $name : (string)$u['LOGIN'];
            return trim((string)$u['WORK_POSITION']) !== '' ? $name . ' (' . trim($u['WORK_POSITION']) . ')' : $name;
        })();
    }

    private static function userFieldEnum(int $ufId, mixed $v): string
    {
        if (!isset(self::$cache['ufEnum'][$ufId])) {
            $map = [];
            if ($ufId > 0) {
                $rs = \CUserFieldEnum::GetList([], ['USER_FIELD_ID' => $ufId]);
                while ($row = $rs->Fetch()) {
                    $map[(int)$row['ID']] = (string)$row['VALUE'];
                }
            }
            self::$cache['ufEnum'][$ufId] = $map;
        }
        return self::$cache['ufEnum'][$ufId][(int)$v] ?? self::scalar($v);
    }

    private static function iblockEnum(mixed $v): string
    {
        $id = (int)$v;
        if ($id <= 0) {
            return self::scalar($v);
        }
        return self::$cache['ibEnum'][$id] ??= (string)((\CIBlockPropertyEnum::GetByID($id) ?: [])['VALUE'] ?? $id);
    }

    private static function crmBinding(mixed $v, array $settings): string
    {
        $parsed = CrmBinding::parse($v, CrmBinding::allowedTypes($settings));
        return $parsed ? self::crmItem($parsed[0], $parsed[1]) : self::scalar($v);
    }

    private static function crmItem(int $entityTypeId, int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        $key = $entityTypeId . ':' . $id;
        return self::$cache['crm'][$key] ??= (static function () use ($entityTypeId, $id): string {
            $factory = Container::getInstance()->getFactory($entityTypeId);
            if (!$factory) {
                return '#' . $id;
            }
            $item = $factory->getItem($id);
            $type = (string)$factory->getEntityDescription();
            if (!$item) {
                return sprintf('%s #%d (не найден)', $type, $id);
            }
            $title = method_exists($item, 'getHeading') ? (string)$item->getHeading() : '';
            if ($title === '') {
                $title = (string)$item->getTitle();
            }
            return sprintf('%s (%s #%d)', $title !== '' ? $title : 'без названия', $type, $id);
        })();
    }

    private static function status(string $statusId, string $entityId): string
    {
        if ($statusId === '') {
            return '';
        }
        $key = $entityId . '|' . $statusId;
        return self::$cache['status'][$key] ??= (static function () use ($statusId, $entityId): string {
            $filter = ['=STATUS_ID' => $statusId];
            if ($entityId !== '') {
                $filter['=ENTITY_ID'] = $entityId;
            }
            $row = StatusTable::getList(['select' => ['NAME'], 'filter' => $filter, 'limit' => 1])->fetch();
            return $row ? (string)$row['NAME'] : $statusId;
        })();
    }

    private static function category(int $entityTypeId, int $categoryId): string
    {
        $key = $entityTypeId . ':' . $categoryId;
        return self::$cache['category'][$key] ??= (static function () use ($entityTypeId, $categoryId): string {
            $factory = $entityTypeId > 0 ? Container::getInstance()->getFactory($entityTypeId) : null;
            if (!$factory) {
                return (string)$categoryId;
            }
            $category = $factory->getCategory($categoryId);
            if (!$category && $categoryId === 0 && method_exists($factory, 'getDefaultCategory')) {
                $category = $factory->getDefaultCategory();
            }
            return $category ? (string)$category->getName() : (string)$categoryId;
        })();
    }

    private static function iblockElement(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        return self::$cache['element'][$id] ??= (static function () use ($id): string {
            $row = \CIBlockElement::GetList([], ['ID' => $id, 'CHECK_PERMISSIONS' => 'N'], false, false, ['ID', 'NAME'])->Fetch();
            return $row ? sprintf('%s (#%d)', $row['NAME'], $id) : '#' . $id;
        })();
    }

    /** Привязка к элементу HL-блока: значение поля, выбранного для отображения, иначе #ID. */
    private static function hlElement(array $settings, int $id): string
    {
        $hlId = (int)($settings['HLBLOCK_ID'] ?? 0);
        if ($id <= 0 || $hlId <= 0) {
            return $id > 0 ? '#' . $id : '';
        }
        $fieldId = (int)($settings['HLFIELD_ID'] ?? 0);
        return self::$cache['hl'][$hlId . ':' . $fieldId . ':' . $id] ??= (static function () use ($hlId, $fieldId, $id): string {
            $display = '';
            if ($fieldId > 0) {
                $field = \CUserTypeEntity::GetByID($fieldId);
                $display = is_array($field) ? (string)$field['FIELD_NAME'] : '';
            }
            $class = EntityReader::hlDataClass($hlId);
            $row = $class::getList(['select' => array_values(array_filter(['ID', $display])), 'filter' => ['=ID' => $id], 'limit' => 1])->fetch();
            if (!$row) {
                return '#' . $id . ' (не найден)';
            }
            $title = $display !== '' ? trim(self::scalar($row[$display] ?? '')) : '';
            return $title !== '' ? sprintf('%s (#%d)', $title, $id) : '#' . $id;
        })();
    }

    private static function iblockSection(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        return self::$cache['section'][$id] ??= (static function () use ($id): string {
            $row = \CIBlockSection::GetList([], ['ID' => $id, 'CHECK_PERMISSIONS' => 'N'], false, ['ID', 'NAME'])->Fetch();
            return $row ? (string)$row['NAME'] : '#' . $id;
        })();
    }

    private static function file(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        return self::$cache['file'][$id] ??= (static function () use ($id): string {
            $file = \CFile::GetFileArray($id);
            return $file ? (string)($file['ORIGINAL_NAME'] ?: $file['FILE_NAME']) : 'файл #' . $id;
        })();
    }

    private static function money(string $v): string
    {
        [$sum, $currency] = array_pad(explode('|', $v, 2), 2, '');
        return trim($sum . ' ' . $currency);
    }

    private static function plainText(mixed $v): string
    {
        if (is_array($v)) {
            $v = $v['TEXT'] ?? Json::encode($v);
        }
        $text = str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</li>', '</div>'], "\n", (string)$v);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/[ \t]+/u", ' ', $text) ?? $text);
    }

    private static function scalar(mixed $v): string
    {
        if ($v instanceof \Bitrix\Main\Type\DateTime) {
            return $v->format('d.m.Y H:i');
        }
        if ($v instanceof \Bitrix\Main\Type\Date) {
            return $v->format('d.m.Y');
        }
        if (is_bool($v)) {
            return $v ? 'да' : 'нет';
        }
        if (is_scalar($v)) {
            return (string)$v;
        }
        if (is_object($v) && method_exists($v, '__toString')) {
            return (string)$v;
        }
        try {
            return Json::encode($v);
        } catch (\Throwable) {
            return '';
        }
    }

    private static function clip(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return $text;
        }
        return mb_substr($text, 0, self::MAX_LENGTH) . '…';
    }
}
