<?php
namespace Local\AiLab;

use Local\AiLab\Model\TaskTable;
use Local\AiLab\Queue\TaskStatus;
use Local\AiLab\Queue\WorkerStatus;

/**
 * Агент-сторож (раз в 5 минут, через cron_events Битрикса — независимо от нашего обработчика):
 * если задачи ждут, а обработчик очереди молчит, пишет администраторам.
 */
final class Agent
{
    public const NAME = '\\Local\\AiLab\\Agent::watchdog();';

    public static function watchdog(): string
    {
        try {
            $pending = (int)TaskTable::getCount(['=STATUS' => TaskStatus::PENDING]);
            if ($pending > 0 && !WorkerStatus::isAlive() && !Settings::queuePaused()) {
                $beat = WorkerStatus::lastBeat();
                Notifier::admins('worker_silent', sprintf(
                    'обработчик очереди %s, а в очереди ждут задач: %d. Бизнес-процессы с кубиком AI Lab стоят. Проверьте cron пользователя bitrix.',
                    $beat > 0 ? 'не запускался с ' . date('d.m.Y H:i', $beat) : 'ни разу не запускался',
                    $pending
                ));
            }
        } catch (\Throwable) {
            // агент не должен падать
        }
        return self::NAME;
    }
}
