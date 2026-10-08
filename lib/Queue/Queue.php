<?php
namespace Local\AiLab\Queue;

use Bitrix\Main\Type\DateTime;
use Local\AiLab\Bp\BpBridge;
use Local\AiLab\Json;
use Local\AiLab\Model\TaskTable;
use Local\AiLab\Notifier;
use Local\AiLab\Settings;
use Local\AiLab\Scenario\RunInput;
use Local\AiLab\Scenario\Scenario;

/** Постановка задач в очередь и отмена. */
final class Queue
{
    public const ORIGIN_BP   = 'bp';
    public const ORIGIN_TEST = 'test';

    /** Секунд до первой отправки задачи из бизнес-процесса. */
    public const WORKFLOW_HEAD_START = 3;

    public const ORIGINS = [
        self::ORIGIN_BP   => 'бизнес-процесс',
        self::ORIGIN_TEST => 'тест',
    ];

    /**
     * @param array{origin?: string, timeout?: int, workflow_id?: string, activity?: string,
     *              document_id?: string, user_id?: int} $context
     * @throws QueueException сценарий выключен или исчерпан суточный лимит
     */
    public static function enqueue(Scenario $scenario, RunInput $input, array $context = []): int
    {
        $origin = (string)($context['origin'] ?? self::ORIGIN_BP);

        if (!$scenario->active && $origin !== self::ORIGIN_TEST) {
            throw new QueueException("Сценарий «{$scenario->code}» выключен");
        }
        if ($origin !== self::ORIGIN_TEST && $scenario->dailyLimit > 0 && self::usedToday($scenario->id) >= $scenario->dailyLimit) {
            $message = sprintf(
                'Сценарий «%s»: исчерпан суточный лимит в %d запросов. Проверьте, не зациклился ли бизнес-процесс; лимит меняется в настройках сценария.',
                $scenario->code, $scenario->dailyLimit
            );
            Notifier::admins('daily_limit_' . $scenario->code, $message, 6 * 3600);
            throw new QueueException($message);
        }
        $pending = (int)TaskTable::getCount(['=STATUS' => TaskStatus::PENDING]);
        if ($pending >= Settings::maxPending()) {
            $message = sprintf(
                'Очередь переполнена: ждут отправки %d задач (предел %d). Похоже, обработчик не работает или процесс зациклился — новые задачи не принимаются.',
                $pending, Settings::maxPending()
            );
            Notifier::admins('queue_overflow', $message);
            throw new QueueException($message);
        }

        $timeout = max(60, min(86400, (int)($context['timeout'] ?? Settings::defaultTimeout())));
        $userId = (int)($context['user_id'] ?? self::currentUserId());
        $workflowId = (string)($context['workflow_id'] ?? '');
        $now = time();
        // Фора процессу, чтобы веб-запрос успел сохранить его в состоянии «ждёт» до ответа обработчика.
        $startAt = $workflowId !== '' ? $now + self::WORKFLOW_HEAD_START : $now;

        $result = TaskTable::add([
            'SCENARIO_ID'   => $scenario->id,
            'SCENARIO_CODE' => $scenario->code,
            'ORIGIN'        => $origin,
            'STATUS'        => TaskStatus::PENDING,
            'INPUT'         => Json::encode($input->toArray()),
            'BUILT'         => '',
            'RESULT'        => '',
            'ERROR_TEXT'    => '',
            'WARNINGS'      => '',
            'ATTEMPT_LOG'   => '',
            'CONNECTION_ID' => $scenario->connectionId,
            'WORKFLOW_ID'   => $workflowId,
            'NOTIFY_STATE'  => $workflowId !== '' ? BpBridge::NOTIFY_WAIT : '',
            'NOTIFY_TRIES'  => 0,
            'NOTIFY_ERROR'  => '',
            'ACTIVITY_NAME' => (string)($context['activity'] ?? ''),
            'DOCUMENT_ID'   => (string)($context['document_id'] ?? ''),
            'CREATED_BY'    => $userId,
            'CREATED_AT'    => DateTime::createFromTimestamp($now),
            'NEXT_RUN_AT'   => DateTime::createFromTimestamp($startAt),
            'DEADLINE_AT'   => DateTime::createFromTimestamp($now + $timeout),
        ]);
        if (!$result->isSuccess()) {
            throw new \RuntimeException('Задача не поставлена: ' . implode('; ', $result->getErrorMessages()));
        }
        return (int)$result->getId();
    }

    /** Запросов сценария с начала суток (тестовые не считаются). */
    public static function usedToday(int $scenarioId): int
    {
        return (int)TaskTable::getCount([
            '=SCENARIO_ID' => $scenarioId,
            '!=ORIGIN'     => self::ORIGIN_TEST,
            '>=CREATED_AT' => DateTime::createFromTimestamp(strtotime('today')),
        ]);
    }

    /** @param bool $notifyWorkflow false — отменяет сам кубик, будить процесс не нужно */
    public static function cancel(int $id, string $reason = 'Отменено вручную', bool $notifyWorkflow = true): bool
    {
        if (TaskRepository::cancelPending($id, $reason)) {
            if (!$notifyWorkflow) {
                TaskRepository::update($id, ['NOTIFY_STATE' => BpBridge::NOTIFY_DONE]);
            }
            Completion::finished($id, TaskStatus::CANCELLED, $notifyWorkflow);
            return true;
        }
        return false;
    }

    private static function currentUserId(): int
    {
        global $USER;
        return is_object($USER) ? (int)$USER->GetID() : 0;
    }
}
