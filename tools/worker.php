<?php
/**
 * Обработчик очереди AI Lab. Запуск из cron пользователя bitrix, раз в минуту:
 *
 *   * * * * * /usr/bin/php -f /home/bitrix/www/local/modules/local.ailab/tools/worker.php >/dev/null 2>&1
 *
 * Вручную:  php -f worker.php -- -v        подробный вывод
 *           php -f worker.php -- --status  состояние очереди
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../../..');
$DOCUMENT_ROOT = $_SERVER['DOCUMENT_ROOT'];

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);
define('NO_AGENT_STATISTIC', true);
define('DisableEventsCheck', true);
define('STOP_STATISTICS', true);
define('BX_NO_ACCELERATOR_RESET', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

@set_time_limit(0);
@ignore_user_abort(true);

if (!\Bitrix\Main\Loader::includeModule('local.ailab')) {
    fwrite(STDERR, "Модуль local.ailab не установлен\n");
    exit(1);
}

try {
    \Local\AiLab\Installer::ensureSchema();
    $code = \Local\AiLab\Queue\Worker::main($argv ?? []);
} catch (\Throwable $e) {
    fwrite(STDERR, 'AI Lab worker: ' . $e->getMessage() . PHP_EOL);
    $code = 1;
}
exit($code);
