<?php
namespace Local\AiLab\Data;

/**
 * Значения полей «Привязка к элементам CRM».
 * Если в поле разрешён один тип — хранится просто ID ("15"),
 * если несколько — с префиксом ("D_15", "T8a_15", "DYNAMIC_138_15").
 */
final class CrmBinding
{
    /** @return int[] entityTypeId, разрешённые в настройках поля */
    public static function allowedTypes(array $settings): array
    {
        $result = [];
        foreach ($settings as $key => $value) {
            if ($value !== 'Y') {
                continue;
            }
            if (preg_match('/^DYNAMIC_(\d+)$/', (string)$key, $m)) {
                $result[] = (int)$m[1];
            } elseif (class_exists(\CCrmOwnerType::class)) {
                $typeId = \CCrmOwnerType::ResolveID((string)$key);
                if ($typeId !== \CCrmOwnerType::Undefined) {
                    $result[] = (int)$typeId;
                }
            }
        }
        return array_values(array_unique($result));
    }

    /** @return array{0: int, 1: int}|null [entityTypeId, id] */
    public static function parse(mixed $raw, array $allowed): ?array
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return null;
        }
        if (ctype_digit($raw)) {
            return count($allowed) === 1 ? [$allowed[0], (int)$raw] : null;
        }
        if (preg_match('/^DYNAMIC_(\d+)_(\d+)$/', $raw, $m)) {
            return [(int)$m[1], (int)$m[2]];
        }
        if (preg_match('/^([A-Za-z0-9]+)_(\d+)$/', $raw, $m)) {
            $typeId = 0;
            if (class_exists(\CCrmOwnerTypeAbbr::class) && method_exists(\CCrmOwnerTypeAbbr::class, 'ResolveTypeID')) {
                $typeId = (int)\CCrmOwnerTypeAbbr::ResolveTypeID($m[1]);
            }
            if ($typeId <= 0 && preg_match('/^T([0-9a-f]+)$/i', $m[1], $h)) {
                $typeId = (int)hexdec($h[1]);
            }
            return $typeId > 0 ? [$typeId, (int)$m[2]] : null;
        }
        return null;
    }
}
