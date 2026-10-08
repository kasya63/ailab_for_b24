<?php
namespace Local\AiLab\Bp;

use Bitrix\Main\Loader;

/** Комментарий в таймлайн CRM-элемента — только для документов CRM. */
final class Timeline
{
    public static function comment(array $documentId, string $text, int $authorId): bool
    {
        $entity = DocumentMap::entity($documentId);
        if ($entity === null || !str_starts_with($entity[0], 'crm:') || !Loader::includeModule('crm')) {
            return false;
        }
        $id = \Bitrix\Crm\Timeline\CommentEntry::create([
            'TEXT'      => $text,
            'AUTHOR_ID' => $authorId > 0 ? $authorId : 1,
            'BINDINGS'  => [['ENTITY_TYPE_ID' => (int)substr($entity[0], 4), 'ENTITY_ID' => $entity[1]]],
        ]);
        return (int)$id > 0;
    }
}
