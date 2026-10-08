<?php
namespace Local\AiLab\Bp;

use Bitrix\Main\Loader;

/**
 * Документ бизнес-процесса ↔ сущность, которую умеют читать EntityCatalog / EntityReader.
 * Благодаря этому поля документа в кубике выглядят так же, как в тестовом прогоне.
 *
 *   CRM:    ['crm', '…', 'DYNAMIC_150_15'] → ['crm:150', 15];  тип 'DYNAMIC_150' → 'crm:150'
 *           ['crm', 'CCrmDocumentDeal', 'DEAL_7'] → ['crm:2', 7]
 *   Списки: ['lists', 'BizprocDocument', '123'] → ['iblock:<ID инфоблока>', 123]; тип 'iblock_41' → 'iblock:41'
 */
final class DocumentMap
{
    private const CRM_TYPES = ['LEAD' => 1, 'DEAL' => 2, 'CONTACT' => 3, 'COMPANY' => 4, 'QUOTE' => 7, 'SMART_INVOICE' => 31];

    /** Ключ сущности для типа документа (в дизайнере) или null. */
    public static function entityKey(array $documentType): ?string
    {
        [$module, , $type] = array_pad(array_values($documentType), 3, '');
        $type = (string)$type;

        if ($module === 'crm') {
            if (preg_match('/^DYNAMIC_(\d+)$/', $type, $m)) {
                return 'crm:' . (int)$m[1];
            }
            $typeId = self::crmTypeId($type);
            return $typeId > 0 ? 'crm:' . $typeId : null;
        }
        if (in_array($module, ['lists', 'iblock'], true) && preg_match('/^iblock_(\d+)$/', $type, $m)) {
            return 'iblock:' . (int)$m[1];
        }
        return null;
    }

    /** @return array{0: string, 1: int}|null [ключ сущности, ID элемента] для документа (при выполнении) */
    public static function entity(array $documentId): ?array
    {
        [$module, , $id] = array_pad(array_values($documentId), 3, '');
        $id = (string)$id;

        if ($module === 'crm') {
            if (preg_match('/^DYNAMIC_(\d+)_(\d+)$/', $id, $m)) {
                return ['crm:' . (int)$m[1], (int)$m[2]];
            }
            if (preg_match('/^([A-Z_]+?)_(\d+)$/', $id, $m)) {
                $typeId = self::crmTypeId($m[1]);
                return $typeId > 0 ? ['crm:' . $typeId, (int)$m[2]] : null;
            }
            return null;
        }
        if (in_array($module, ['lists', 'iblock'], true) && ctype_digit($id)) {
            $iblockId = Loader::includeModule('iblock') ? (int)\CIBlockElement::GetIBlockByID((int)$id) : 0;
            return $iblockId > 0 ? ['iblock:' . $iblockId, (int)$id] : null;
        }
        return null;
    }

    /** Строка для журнала: «crm:DYNAMIC_150_15». */
    public static function toString(array $documentId): string
    {
        $documentId = array_values($documentId);
        return ($documentId[0] ?? '') . ':' . ($documentId[2] ?? '');
    }

    /** @return array{0: int, 1: int}|null [entityTypeId, ID] для строки из журнала, если это CRM */
    public static function crmFromString(string $value): ?array
    {
        if (!str_starts_with($value, 'crm:')) {
            return null;
        }
        $entity = self::entity(['crm', '', substr($value, 4)]);
        return $entity ? [(int)substr($entity[0], 4), $entity[1]] : null;
    }

    private static function crmTypeId(string $name): int
    {
        if (isset(self::CRM_TYPES[$name])) {
            return self::CRM_TYPES[$name];
        }
        if (class_exists(\CCrmOwnerType::class)) {
            $id = (int)\CCrmOwnerType::ResolveID($name);
            return $id > 0 ? $id : 0;
        }
        return 0;
    }
}
