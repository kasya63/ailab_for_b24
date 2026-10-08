<?php
namespace Local\AiLab\Queue;

use Bitrix\Main\Event;
use Local\AiLab\Bp\BpBridge;

/**
 * Точка выхода задачи из очереди: событие модуля OnTaskFinished(ID, STATUS)
 * и пробуждение бизнес-процесса, если задачу поставил кубик.
 */
final class Completion
{
    public static function finished(int $taskId, string $status, bool $notifyWorkflow = true): void
    {
        try {
            (new Event('local.ailab', 'OnTaskFinished', ['ID' => $taskId, 'STATUS' => $status]))->send();
        } catch (\Throwable) {
            // обработчик события не должен ронять очередь
        }
        if ($notifyWorkflow) {
            BpBridge::notify($taskId);
        }
    }

    /** Нужен ли ещё ответ: false, если бизнес-процесс остановлен или удалён. */
    public static function stillWanted(array $task): bool
    {
        try {
            return BpBridge::isWaiting($task);
        } catch (\Throwable) {
            return true;
        }
    }
}
