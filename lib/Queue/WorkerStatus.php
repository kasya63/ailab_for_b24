<?php
namespace Local\AiLab\Queue;

use Bitrix\Main\Config\Option;
use Local\AiLab\Json;

/** «Признаки жизни» обработчика: когда запускался и что сделал. */
final class WorkerStatus
{
    private const MODULE_ID = 'local.ailab';

    /** Обработчик считается живым, если отмечался за последние 5 минут. */
    public const ALIVE_WITHIN = 300;

    public static function beat(): void
    {
        Option::set(self::MODULE_ID, 'worker_heartbeat', (string)time());
    }

    public static function finish(array $stats): void
    {
        Option::set(self::MODULE_ID, 'worker_last_run', Json::encode(['at' => time()] + $stats));
        self::beat();
    }

    public static function lastBeat(): int
    {
        return (int)Option::get(self::MODULE_ID, 'worker_heartbeat', '0');
    }

    public static function lastRun(): array
    {
        $data = json_decode(Option::get(self::MODULE_ID, 'worker_last_run', ''), true);
        return is_array($data) ? $data : [];
    }

    public static function isAlive(): bool
    {
        return self::lastBeat() > time() - self::ALIVE_WITHIN;
    }

    /** Строка для cron с путями этого сервера. */
    public static function cronLine(): string
    {
        $php = PHP_BINDIR . '/php';
        if (!is_file($php)) {
            $php = '/usr/bin/php';
        }
        $script = dirname(__DIR__, 2) . '/tools/worker.php';
        return "* * * * * {$php} -f {$script} >/dev/null 2>&1";
    }
}
