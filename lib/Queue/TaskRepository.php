<?php
namespace Local\AiLab\Queue;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Local\AiLab\Model\TaskTable;

/**
 * Операции с таблицей задач. Время везде берётся из PHP (как у ORM), а не NOW() MySQL,
 * чтобы не зависеть от часового пояса сервера базы.
 */
final class TaskRepository
{
    public static function get(int $id): ?array
    {
        return $id > 0 ? (TaskTable::getById($id)->fetch() ?: null) : null;
    }

    public static function update(int $id, array $fields): void
    {
        $result = TaskTable::update($id, $fields);
        if (!$result->isSuccess()) {
            throw new \RuntimeException("Задача #{$id} не сохранена: " . implode('; ', $result->getErrorMessages()));
        }
    }

    /**
     * Атомарно забирает до $limit задач: pending → processing.
     * UPDATE … WHERE STATUS = 'pending' срабатывает ровно у одного процесса,
     * поэтому одна задача не может уйти провайдеру дважды.
     *
     * @return int[]
     */
    public static function claim(int $limit, string $token): array
    {
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $now = $helper->convertToDbDateTime(new DateTime());
        $table = TaskTable::getTableName();

        $candidates = $connection->query(sprintf(
            "SELECT ID FROM %s WHERE STATUS = 'pending' AND NEXT_RUN_AT <= %s ORDER BY ID LIMIT %d",
            $table, $now, $limit * 3
        ))->fetchAll();

        $claimed = [];
        foreach ($candidates as $row) {
            if (count($claimed) >= $limit) {
                break;
            }
            $connection->queryExecute(sprintf(
                "UPDATE %s SET STATUS = 'processing', WORKER_TOKEN = %s, STARTED_AT = %s, ATTEMPTS = ATTEMPTS + 1 WHERE ID = %d AND STATUS = 'pending'",
                $table, $helper->convertToDbString($token), $now, (int)$row['ID']
            ));
            if ($connection->getAffectedRowsCount() === 1) {
                $claimed[] = (int)$row['ID'];
            }
        }
        return $claimed;
    }

    /** Отмена срабатывает, только пока задача не взята в работу. */
    public static function cancelPending(int $id, string $reason): bool
    {
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $connection->queryExecute(sprintf(
            "UPDATE %s SET STATUS = 'cancelled', ERROR_TEXT = %s, FINISHED_AT = %s WHERE ID = %d AND STATUS = 'pending'",
            TaskTable::getTableName(), $helper->convertToDbString($reason),
            $helper->convertToDbDateTime(new DateTime()), $id
        ));
        return $connection->getAffectedRowsCount() === 1;
    }

    /** @return list<array> задачи в processing дольше $seconds — их обработчик, видимо, упал */
    public static function stale(int $seconds): array
    {
        return TaskTable::getList([
            'select' => ['ID', 'ATTEMPTS', 'DEADLINE_AT', 'ATTEMPT_LOG'],
            'filter' => ['=STATUS' => TaskStatus::PROCESSING, '<STARTED_AT' => DateTime::createFromTimestamp(time() - $seconds)],
        ])->fetchAll();
    }

    /** @return int[] задачи в очереди, у которых истёк срок ожидания */
    public static function expired(): array
    {
        $rows = TaskTable::getList([
            'select' => ['ID'],
            'filter' => ['=STATUS' => TaskStatus::PENDING, '<DEADLINE_AT' => new DateTime()],
        ])->fetchAll();
        return array_map(static fn($r) => (int)$r['ID'], $rows);
    }

    /** Когда ближайшая отложенная задача станет готова к отправке (null — очередь пуста). */
    public static function nextPendingAt(): ?int
    {
        $row = TaskTable::getList([
            'select' => ['NEXT_RUN_AT'],
            'filter' => ['=STATUS' => TaskStatus::PENDING],
            'order'  => ['NEXT_RUN_AT' => 'ASC'],
            'limit'  => 1,
        ])->fetch();
        return $row && $row['NEXT_RUN_AT'] instanceof DateTime ? $row['NEXT_RUN_AT']->getTimestamp() : null;
    }

    /** @return array{count: int, in: int, out: int, cached: int} */
    public static function totals(array $filter): array
    {
        $row = TaskTable::getList([
            'select'  => ['CNT', 'SUM_IN', 'SUM_OUT', 'SUM_CACHED'],
            'filter'  => $filter,
            'runtime' => [
                new \Bitrix\Main\ORM\Fields\ExpressionField('CNT', 'COUNT(*)'),
                new \Bitrix\Main\ORM\Fields\ExpressionField('SUM_IN', 'SUM(%s)', 'INPUT_TOKENS'),
                new \Bitrix\Main\ORM\Fields\ExpressionField('SUM_OUT', 'SUM(%s)', 'OUTPUT_TOKENS'),
                new \Bitrix\Main\ORM\Fields\ExpressionField('SUM_CACHED', 'SUM(%s)', 'CACHED_TOKENS'),
            ],
        ])->fetch() ?: [];
        return [
            'count'  => (int)($row['CNT'] ?? 0),
            'in'     => (int)($row['SUM_IN'] ?? 0),
            'out'    => (int)($row['SUM_OUT'] ?? 0),
            'cached' => (int)($row['SUM_CACHED'] ?? 0),
        ];
    }

    /** @return array<string, int> статус => количество */
    public static function countByStatus(array $filter = []): array
    {
        $out = [];
        $rows = TaskTable::getList([
            'select'  => ['STATUS', 'CNT'],
            'filter'  => $filter,
            'group'   => ['STATUS'],
            'runtime' => [new \Bitrix\Main\ORM\Fields\ExpressionField('CNT', 'COUNT(*)')],
        ])->fetchAll();
        foreach ($rows as $row) {
            $out[(string)$row['STATUS']] = (int)$row['CNT'];
        }
        return $out;
    }
}
