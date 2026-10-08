<?php
namespace Local\AiLab\Bp;

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Local\AiLab\Json;
use Local\AiLab\Model\TaskTable;
use Local\AiLab\Notifier;
use Local\AiLab\Queue\TaskRepository;
use Local\AiLab\Queue\TaskStatus;
use Local\AiLab\Scenario\OutputSchema;
use Local\AiLab\Scenario\Scenario;

/**
 * Мост очередь ↔ бизнес-процесс.
 *
 * Пробуждение: CBPRuntime::sendExternalEvent(процесс, имя кубика). Событие только будит кубик —
 * результат он читает из журнала сам, поэтому ничего не теряется, даже если событие не дошло
 * (тогда кубик разбудит страховочный таймер, и он найдёт готовый ответ).
 * Если процесс в этот момент занят (сохраняется веб-запросом), попытка повторяется
 * при следующих запусках обработчика, до 5 раз.
 */
final class BpBridge
{
    public const NOTIFY_WAIT   = 'wait';
    public const NOTIFY_DONE   = 'done';
    public const NOTIFY_RETRY  = 'retry';
    public const NOTIFY_FAILED = 'failed';
    public const MAX_TRIES = 5;

    /** Тип поля ответа → тип результата БП. */
    private const BP_TYPES = [
        'string'   => 'string',
        'enum'     => 'string',
        'integer'  => 'int',
        'ref'      => 'int',
        'ref_list' => 'int',
        'number'   => 'double',
        'boolean'  => 'bool',
    ];

    public static function notify(int $taskId): void
    {
        $task = TaskRepository::get($taskId);
        if ($task === null || (string)$task['WORKFLOW_ID'] === '' || $task['NOTIFY_STATE'] === self::NOTIFY_DONE) {
            return;
        }
        $tries = (int)$task['NOTIFY_TRIES'] + 1;
        try {
            if (!Loader::includeModule('bizproc')) {
                throw new \RuntimeException('Модуль bizproc не подключен');
            }
            \CBPRuntime::sendExternalEvent((string)$task['WORKFLOW_ID'], (string)$task['ACTIVITY_NAME'], ['AILAB_TASK_ID' => $taskId]);
            TaskRepository::update($taskId, ['NOTIFY_STATE' => self::NOTIFY_DONE, 'NOTIFY_TRIES' => $tries, 'NOTIFY_ERROR' => '']);
        } catch (\Throwable $e) {
            $failed = $tries >= self::MAX_TRIES;
            TaskRepository::update($taskId, [
                'NOTIFY_STATE' => $failed ? self::NOTIFY_FAILED : self::NOTIFY_RETRY,
                'NOTIFY_TRIES' => $tries,
                'NOTIFY_ERROR' => mb_substr($e->getMessage(), 0, 1000),
            ]);
            if ($failed) {
                Notifier::admins('bp_wake_failed', sprintf(
                    'не удалось продолжить бизнес-процесс после ответа по задаче №%d (%s). Процесс разбудит страховочный таймер кубика.',
                    $taskId, mb_substr($e->getMessage(), 0, 200)
                ));
            }
        }
    }

    /** Повторить пробуждение процессов, которые не удалось продолжить с первого раза. */
    public static function retryPending(): int
    {
        $rows = TaskTable::getList([
            'select' => ['ID'],
            'filter' => [
                '=NOTIFY_STATE' => self::NOTIFY_RETRY,
                '<FINISHED_AT'  => DateTime::createFromTimestamp(time() - 20),
            ],
            'order'  => ['ID' => 'ASC'],
            'limit'  => 50,
        ])->fetchAll();
        foreach ($rows as $row) {
            self::notify((int)$row['ID']);
        }
        return count($rows);
    }

    /** Ждёт ли ещё процесс ответа. Остановленный процесс удаляется из b_bp_workflow_instance. */
    public static function isWaiting(array $task): bool
    {
        $workflowId = (string)($task['WORKFLOW_ID'] ?? '');
        if ($workflowId === '' || !Loader::includeModule('bizproc')) {
            return true;
        }
        $table = \Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable::class;
        if (!class_exists($table)) {
            return true;
        }
        return (bool)$table::getList(['select' => ['ID'], 'filter' => ['=ID' => $workflowId], 'limit' => 1])->fetch();
    }

    /**
     * Описание «Дополнительных результатов» кубика для дизайнера (ADDITIONAL_RESULT).
     * @return array<string, array{Name: string, Type: string, Multiple: bool}>
     */
    public static function resultFields(Scenario $scenario): array
    {
        return self::fieldsDefinition($scenario->schema->fields());
    }

    /** @param list<array> $fields поля OutputSchema */
    public static function fieldsDefinition(array $fields): array
    {
        if (!$fields) {
            return ['text' => ['Name' => 'text — ответ модели', 'Type' => 'text', 'Multiple' => false]];
        }
        $out = [];
        foreach (OutputSchema::fromArray($fields)->fields() as $f) {
            $name = $f['code'] . ($f['description'] !== '' ? ' — ' . $f['description'] : '');
            $out[$f['code']] = [
                'Name'     => mb_strimwidth($name, 0, 90, '…'),
                'Type'     => self::BP_TYPES[$f['type']] ?? 'string',
                'Multiple' => $f['type'] === 'ref_list',
            ];
        }
        return $out;
    }

    /** Значения результатов из завершённой задачи, приведённые к типам БП. */
    public static function resultValues(array $task): array
    {
        $data = json_decode((string)$task['RESULT'], true);
        $data = is_array($data) ? $data : [];
        $fields = self::builtFields($task);

        if (!$fields) {
            return ['text' => (string)($data['text'] ?? '')];
        }
        $values = [];
        foreach ($fields as $f) {
            $v = $data[$f['code']] ?? null;
            $values[$f['code']] = match ($f['type']) {
                'boolean'        => $v === null ? null : ($v ? 'Y' : 'N'),
                'integer', 'ref' => $v === null ? null : (int)$v,
                'number'         => $v === null ? null : (float)$v,
                'ref_list'       => array_values(array_map('intval', (array)$v)),
                default          => $v === null ? '' : (string)$v,
            };
        }
        return $values;
    }

    /** @return array<string, array{Type: string, Multiple?: bool}> */
    public static function resultTypes(array $task): array
    {
        $types = [];
        foreach (self::fieldsDefinition(self::builtFields($task)) as $code => $def) {
            $types[$code] = ['Type' => $def['Type'], 'Multiple' => $def['Multiple']];
        }
        return $types;
    }

    /** Короткая сводка ответа для журнала БП и таймлайна. */
    public static function summary(array $task, int $limit = 600): string
    {
        $parts = [];
        foreach (self::resultValues($task) as $code => $value) {
            if (is_array($value)) {
                $value = $value ? implode(', ', $value) : '—';
            } elseif ($value === null || $value === '') {
                $value = '—';
            }
            $parts[] = $code . ': ' . $value;
        }
        return mb_strimwidth(implode('; ', $parts), 0, $limit, '…');
    }

    public static function statusLabel(string $taskStatus): string
    {
        return TaskStatus::label($taskStatus);
    }

    /** Поля ответа в том виде, в каком они были при сборке промта задачи. */
    private static function builtFields(array $task): array
    {
        $built = json_decode((string)($task['BUILT'] ?? ''), true);
        return is_array($built) ? (array)($built['fields'] ?? []) : [];
    }
}
