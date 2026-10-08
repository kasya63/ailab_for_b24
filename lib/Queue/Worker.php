<?php
namespace Local\AiLab\Queue;

use Bitrix\Main\Type\DateTime;
use Local\AiLab\Ai\AiResponse;
use Local\AiLab\Json;
use Local\AiLab\Maintenance;
use Local\AiLab\Notifier;
use Local\AiLab\Scenario\Runner;
use Local\AiLab\Settings;

/**
 * Обработчик очереди. Запускается cron раз в минуту (tools/worker.php):
 *
 *  1. flock: второй экземпляр сразу выходит;
 *  2. сторож: задачи, зависшие в «отправлено» дольше 10 минут (обработчик упал), возвращаются в очередь;
 *  3. задачи с истёкшим сроком ожидания получают «время вышло»;
 *  4. цикл: забрать до N задач → отправить параллельно → разобрать ответы → повторить,
 *     пока очередь не опустеет. Новые задачи забираются первые 50 секунд, начатые доводятся до конца;
 *  5. пустая очередь — сразу выход.
 */
final class Worker
{
    public const STALE_AFTER = 600;
    public const CLAIM_BUDGET = 50;

    public array $stats = [
        'claimed' => 0, 'calls' => 0, 'success' => 0, 'error' => 0, 'invalid' => 0,
        'timeout' => 0, 'cancelled' => 0, 'rescheduled' => 0, 'recovered' => 0,
    ];

    private string $token;
    private float $startedAt;
    private int $lastBeat = 0;
    private int $waits = 0;

    public function __construct(private readonly int $parallel, private readonly ?\Closure $say = null)
    {
        $this->token = bin2hex(random_bytes(8));
        $this->startedAt = microtime(true);
    }

    public static function main(array $argv): int
    {
        $verbose = in_array('-v', $argv, true) || in_array('--verbose', $argv, true);
        $say = $verbose ? static function (string $message): void {
            echo '[' . date('H:i:s') . '] ' . $message . PHP_EOL;
        } : null;

        if (function_exists('posix_geteuid') && posix_geteuid() === 0 && !in_array('--allow-root', $argv, true)) {
            fwrite(STDERR, "Обработчик нельзя запускать от root: файлы кэша станут недоступны сайту.\nЗапускайте от пользователя сайта: crontab -u bitrix -e\n");
            return 2;
        }
        if (in_array('--status', $argv, true)) {
            echo self::statusText();
            return 0;
        }

        $lock = Lock::acquire('worker');
        if ($lock === null) {
            $say && $say('Другой экземпляр обработчика уже работает — выходим');
            return 0;
        }
        try {
            $worker = new self(Settings::parallel(), $say);
            $worker->run();
            WorkerStatus::finish($worker->stats + ['duration_s' => round(microtime(true) - $worker->startedAt, 1)]);
            $say && $say('Готово: ' . Json::encode($worker->stats));
        } finally {
            Lock::release($lock);
        }
        return 0;
    }

    public static function statusText(): string
    {
        $last = WorkerStatus::lastRun();
        $counts = TaskRepository::countByStatus(['@STATUS' => [TaskStatus::PENDING, TaskStatus::PROCESSING]]);
        return sprintf(
            "Последний запуск: %s\nВ очереди: %d, отправлено: %d\nПоследний итог: %s\n",
            WorkerStatus::lastBeat() ? date('d.m.Y H:i:s', WorkerStatus::lastBeat()) : 'ни разу',
            $counts[TaskStatus::PENDING] ?? 0,
            $counts[TaskStatus::PROCESSING] ?? 0,
            $last ? Json::encode($last) : '—'
        );
    }

    public function run(): void
    {
        $this->beat(true);
        $this->recoverStale();
        $this->expire();

        try {
            $cleaned = Maintenance::runDaily();
            if ($cleaned !== null) {
                $this->stats['cleaned'] = $cleaned;
                $this->say('Чистка журнала: ' . Json::encode($cleaned));
            }
        } catch (\Throwable $e) {
            $this->say('Чистка журнала не удалась: ' . $e->getMessage());
        }

        $paused = Settings::queuePaused();
        if ($paused) {
            $this->stats['paused'] = true;
            $this->say('Очередь на паузе (AI Lab → Настройки): новые задачи не отправляются');
        }

        $resend = [];
        while (true) {
            $runs = $resend;
            $resend = [];

            $claimedNow = 0;
            $slots = $this->parallel - count($runs);
            if (!$paused && $slots > 0 && microtime(true) - $this->startedAt < self::CLAIM_BUDGET) {
                $ids = TaskRepository::claim($slots, $this->token);
                $claimedNow = count($ids);
                $this->stats['claimed'] += $claimedNow;
                foreach ($ids as $id) {
                    $run = $this->prepare($id);
                    if ($run !== null) {
                        $runs[] = $run;
                    }
                }
            }

            if (!$runs) {
                if ($claimedNow > 0) {
                    continue; // все взятые завершились ещё при подготовке — пробуем следующие
                }
                if (!$paused && $this->waitForDeferred()) {
                    continue; // отложенный повтор созревает в пределах этого запуска
                }
                break;
            }

            $jobs = [];
            foreach ($runs as $i => $run) {
                $jobs[$i] = [$run->connection, $run->request];
            }
            $this->say('Отправляю: ' . implode(', ', array_map(static fn(TaskRun $r) => '#' . $r->id() . ($r->correctionUsed ? ' (исправление)' : ''), $runs)));

            $responses = ParallelSender::send($jobs);
            $this->stats['calls'] += count($jobs);

            foreach ($runs as $i => $run) {
                try {
                    if ($this->handle($run, $responses[$i])) {
                        $resend[] = $run;
                    }
                } catch (\Throwable $e) {
                    $this->finishTask($run->task, TaskStatus::ERROR, 'Сбой обработки ответа: ' . $e->getMessage(), $run->logFields());
                }
            }
            $this->beat();
        }

        try {
            $this->stats['renotified'] = \Local\AiLab\Bp\BpBridge::retryPending();
        } catch (\Throwable $e) {
            $this->say('Повторное пробуждение процессов не удалось: ' . $e->getMessage());
        }
    }

    /**
     * Очередь пуста, но есть отложенные повторы (после 429/5xx)? Если ближайший созреет
     * до конца окна набора задач — дождаться его здесь, а не ждать следующего запуска cron.
     */
    private function waitForDeferred(): bool
    {
        $claimUntil = $this->startedAt + self::CLAIM_BUDGET;
        if (time() >= $claimUntil || ++$this->waits > 10) {
            return false;
        }
        $next = TaskRepository::nextPendingAt();
        if ($next === null || $next >= $claimUntil) {
            return false;
        }
        $sleep = max(0, $next - time());
        if ($sleep > 0) {
            $this->say("Жду отложенный повтор: {$sleep} с");
            sleep($sleep);
        }
        $this->beat();
        return true;
    }

    private function prepare(int $id): ?TaskRun
    {
        $task = TaskRepository::get($id);
        if ($task === null) {
            return null;
        }
        if (!Completion::stillWanted($task)) {
            $this->finishTask($task, TaskStatus::CANCELLED, 'Бизнес-процесс больше не ждёт ответа');
            return null;
        }
        try {
            return TaskRun::start($task);
        } catch (\Throwable $e) {
            $this->finishTask($task, TaskStatus::ERROR, $e->getMessage());
            return null;
        }
    }

    /** @return bool true — задачу нужно сразу отправить ещё раз (исправление по ошибкам проверки) */
    private function handle(TaskRun $run, AiResponse $response): bool
    {
        $decision = Decision::make(
            $response, $run->schema, $run->refSets, $run->attempts(), $run->correctionUsed, time(), $run->deadline()
        );
        $run->log($response, $run->correctionUsed ? 'correction' : 'send', $decision->problems);
        $this->say(sprintf('#%d: %s → %s%s', $run->id(), $response->status, $decision->action, $decision->error !== '' ? ' (' . $decision->error . ')' : ''));

        switch ($decision->action) {
            case Decision::RESEND:
                $run->request = Runner::correctionRequest($run->request, $response, $decision->problems);
                $run->correctionUsed = true;
                return true;

            case Decision::RETRY_LATER:
                TaskRepository::update($run->id(), [
                    'STATUS'       => TaskStatus::PENDING,
                    'NEXT_RUN_AT'  => DateTime::createFromTimestamp($decision->nextRunAt),
                    'WORKER_TOKEN' => '',
                    'ERROR_TEXT'   => $decision->error,
                ] + $run->logFields());
                $this->stats['rescheduled']++;
                return false;

            case Decision::SUCCESS:
                $this->finishTask($run->task, TaskStatus::SUCCESS, '', [
                    'RESULT'   => Json::encode($decision->data),
                    'WARNINGS' => $decision->warnings ? Json::encode($decision->warnings) : '',
                ] + $run->logFields());
                return false;

            default: // error, invalid, timeout
                $this->finishTask($run->task, $decision->action, $decision->error, $run->logFields());
                if ($decision->action === Decision::ERROR && in_array($response->httpStatus, [401, 403, 404], true)) {
                    Notifier::admins('provider_' . $run->connection->id . '_' . $response->httpStatus, sprintf(
                        'подключение «%s»: провайдер отклоняет запросы (HTTP %d: %s). Запросы через него завершаются ошибкой — проверьте ключ и модель в «AI Lab → Подключения».',
                        $run->connection->name, $response->httpStatus, mb_substr($response->error, 0, 200)
                    ));
                }
                return false;
        }
    }

    /** Задачи, зависшие в «отправлено»: блокировка у нас, значит их обработчик уже не работает. */
    private function recoverStale(): void
    {
        foreach (TaskRepository::stale(self::STALE_AFTER) as $task) {
            $log = json_decode((string)$task['ATTEMPT_LOG'], true);
            $log = is_array($log) ? $log : [];
            $log[] = [
                'n' => count($log) + 1, 'kind' => 'watchdog', 'at' => date('d.m.Y H:i:s'),
                'attempt' => (int)$task['ATTEMPTS'], 'status' => 'error',
                'error' => 'Обработчик прервался, не дождавшись ответа провайдера',
            ];
            $deadline = $task['DEADLINE_AT'] instanceof DateTime ? $task['DEADLINE_AT']->getTimestamp() : 0;
            if ((int)$task['ATTEMPTS'] < Decision::MAX_ATTEMPTS && $deadline > time() + 5) {
                TaskRepository::update((int)$task['ID'], [
                    'STATUS' => TaskStatus::PENDING, 'NEXT_RUN_AT' => new DateTime(), 'WORKER_TOKEN' => '',
                    'ATTEMPT_LOG' => Json::encode($log),
                ]);
            } else {
                $this->finishTask($task, TaskStatus::ERROR, 'Обработчик прервался во время запроса', ['ATTEMPT_LOG' => Json::encode($log)]);
            }
            $this->stats['recovered']++;
        }
    }

    private function expire(): void
    {
        foreach (TaskRepository::expired() as $id) {
            $task = TaskRepository::get($id);
            if ($task !== null && $task['STATUS'] === TaskStatus::PENDING) {
                $this->finishTask($task, TaskStatus::TIMEOUT, (string)$task['ERROR_TEXT'] !== ''
                    ? 'Время ожидания вышло. Последняя ошибка: ' . $task['ERROR_TEXT']
                    : 'Задача не дождалась отправки за отведённое время');
            }
        }
    }

    private function finishTask(array $task, string $status, string $error, array $fields = []): void
    {
        TaskRepository::update((int)$task['ID'], [
            'STATUS'       => $status,
            'ERROR_TEXT'   => $error,
            'FINISHED_AT'  => new DateTime(),
            'WORKER_TOKEN' => '',
        ] + $fields);
        $this->stats[$status] = ($this->stats[$status] ?? 0) + 1;
        Completion::finished((int)$task['ID'], $status);
    }

    private function beat(bool $force = false): void
    {
        if ($force || time() - $this->lastBeat >= 15) {
            WorkerStatus::beat();
            $this->lastBeat = time();
        }
    }

    private function say(string $message): void
    {
        if ($this->say !== null) {
            ($this->say)($message);
        }
    }
}
