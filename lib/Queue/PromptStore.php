<?php
namespace Local\AiLab\Queue;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;

/**
 * Постоянная часть промта (инструкция + справочники + формат) одинакова у сотен задач,
 * поэтому хранится один раз по sha1, а задача ссылается на неё.
 */
final class PromptStore
{
    private const TABLE = 'local_ailab_prompt';

    public static function save(string $text): string
    {
        $hash = sha1($text);
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $connection->queryExecute(sprintf(
            'INSERT IGNORE INTO %s (HASH, TEXT, CREATED_AT) VALUES (%s, %s, %s)',
            self::TABLE,
            $helper->convertToDbString($hash),
            $helper->convertToDbString($text),
            $helper->convertToDbDateTime(new DateTime())
        ));
        return $hash;
    }

    public static function get(string $hash): ?string
    {
        if ($hash === '') {
            return null;
        }
        $connection = Application::getConnection();
        $row = $connection->query(sprintf(
            'SELECT TEXT FROM %s WHERE HASH = %s',
            self::TABLE,
            $connection->getSqlHelper()->convertToDbString($hash)
        ))->fetch();
        return $row ? (string)$row['TEXT'] : null;
    }
}
