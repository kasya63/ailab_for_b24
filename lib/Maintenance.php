<?php
namespace Local\AiLab;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;

/** Чистка журнала: завершённые задачи старше N дней и постоянные части промтов, на которые никто не ссылается. */
final class Maintenance
{
    private const BATCH = 2000;

    /** Раз в сутки, из обработчика очереди. @return array{tasks: int, prompts: int}|null null — сегодня уже чистили */
    public static function runDaily(): ?array
    {
        if ((int)Option::get(Settings::MODULE_ID, 'cleanup_last', '0') > time() - 86400) {
            return null;
        }
        Option::set(Settings::MODULE_ID, 'cleanup_last', (string)time());
        return self::cleanup(Settings::logDays());
    }

    /** @return array{tasks: int, prompts: int} */
    public static function cleanup(int $days): array
    {
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $border = $helper->convertToDbDateTime(DateTime::createFromTimestamp(time() - max(1, $days) * 86400));

        $tasks = 0;
        do {
            $connection->queryExecute(sprintf(
                "DELETE FROM local_ailab_task WHERE CREATED_AT < %s AND STATUS IN ('success','error','invalid','timeout','cancelled') LIMIT %d",
                $border, self::BATCH
            ));
            $deleted = $connection->getAffectedRowsCount();
            $tasks += $deleted;
        } while ($deleted === self::BATCH && $tasks < 500000);

        // Сутки форы: задача могла только что сохранить промт и ещё не записать на него ссылку.
        $dayAgo = $helper->convertToDbDateTime(DateTime::createFromTimestamp(time() - 86400));
        $connection->queryExecute(sprintf(
            "DELETE FROM local_ailab_prompt WHERE CREATED_AT < %s AND HASH NOT IN (SELECT PROMPT_HASH FROM local_ailab_task WHERE PROMPT_HASH <> '')",
            $dayAgo
        ));
        return ['tasks' => $tasks, 'prompts' => $connection->getAffectedRowsCount()];
    }
}
