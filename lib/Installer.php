<?php
namespace Local\AiLab;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

/**
 * Таблицы модуля и их версии.
 * Новые этапы добавляют миграцию с номером SCHEMA_VERSION + 1; применяются они
 * автоматически при открытии страниц модуля (ensureSchema), переустановка не нужна.
 */
final class Installer
{
    public const MODULE_ID = 'local.ailab';
    public const SCHEMA_VERSION = 4;

    /** Код действия БП: папка /local/activities/<код>/ с заглушками, код — в activities/ модуля. */
    public const ACTIVITY_CODE = 'ailabrequestactivity';

    /** Заглушки в /bitrix/admin => файл страницы в admin/ модуля. */
    public const ADMIN_PAGES = [
        'ailab_connections.php'   => 'connections.php',
        'ailab_scenarios.php'     => 'scenarios.php',
        'ailab_scenario_edit.php' => 'scenario_edit.php',
        'ailab_source_edit.php'   => 'source_edit.php',
        'ailab_scenario_test.php' => 'scenario_test.php',
        'ailab_tasks.php'         => 'tasks.php',
        'ailab_task.php'          => 'task.php',
        'ailab_settings.php'      => 'settings.php',
    ];

    public static function install(): void
    {
        Secret::ensureKey();

        // Таблицы удалили руками, а опция осталась — начинаем схему заново.
        if (!Application::getConnection()->isTableExists('local_ailab_connection')) {
            Option::set(self::MODULE_ID, 'schema_version', '0');
        }
        self::ensureSchema();
        self::ensureAdminStubs();
    }

    /**
     * Создаёт недостающие заглушки страниц в /bitrix/admin.
     * Вызывается из menu.php: после заливки новой версии страницы появляются без переустановки.
     */
    public static function ensureAdminStubs(): void
    {
        $dir = Application::getDocumentRoot() . '/bitrix/admin';
        foreach (self::ADMIN_PAGES as $stub => $target) {
            $path = $dir . '/' . $stub;
            if (!is_file($path)) {
                @file_put_contents($path, "<?php\nrequire \$_SERVER['DOCUMENT_ROOT'] . '/local/modules/local.ailab/admin/{$target}';\n");
            }
        }
    }

    /**
     * Заглушки действия БП в /local/activities: сам код живёт в модуле,
     * поэтому обновление модуля обновляет и кубик.
     */
    public static function ensureActivityStubs(): void
    {
        $dir = Application::getDocumentRoot() . '/local/activities/' . self::ACTIVITY_CODE;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $base = "dirname(__DIR__, 2) . '/modules/local.ailab/activities/" . self::ACTIVITY_CODE;
        $stubs = [
            '.description.php'               => "require {$base}/.description.php';",
            self::ACTIVITY_CODE . '.php'     => "require_once {$base}/" . self::ACTIVITY_CODE . ".php';",
        ];
        foreach ($stubs as $file => $line) {
            $path = $dir . '/' . $file;
            if (!is_file($path)) {
                @file_put_contents($path, "<?php\n// AI Lab: действие живёт в модуле local.ailab, здесь только заглушка.\n{$line}\n");
            }
        }
    }

    /** Агент-сторож: регистрируется один раз. */
    public static function ensureAgent(): void
    {
        if (\Bitrix\Main\Config\Option::get('local.ailab', 'agent_registered', '') === '1' || !class_exists(\CAgent::class)) {
            return;
        }
        $exists = \CAgent::GetList([], ['NAME' => Agent::NAME, 'MODULE_ID' => 'local.ailab'])->Fetch();
        if (!$exists) {
            \CAgent::AddAgent(Agent::NAME, 'local.ailab', 'N', 300, '', 'Y', ConvertTimeStamp(time() + 300, 'FULL'));
        }
        \Bitrix\Main\Config\Option::set('local.ailab', 'agent_registered', '1');
    }

    public static function removeAgent(): void
    {
        if (class_exists(\CAgent::class)) {
            \CAgent::RemoveModuleAgents('local.ailab');
        }
        \Bitrix\Main\Config\Option::delete('local.ailab', ['name' => 'agent_registered']);
    }

    public static function removeActivityStubs(): void
    {
        $dir = Application::getDocumentRoot() . '/local/activities/' . self::ACTIVITY_CODE;
        foreach (['.description.php', self::ACTIVITY_CODE . '.php'] as $file) {
            @unlink($dir . '/' . $file);
        }
        @rmdir($dir);
    }

    public static function ensureSchema(): void
    {
        try {
            self::ensureAgent();
        } catch (\Throwable) {
            // агент — подстраховка, его отсутствие не должно ломать модуль
        }
        $current = (int)Option::get(self::MODULE_ID, 'schema_version', '0');
        if ($current >= self::SCHEMA_VERSION) {
            return;
        }

        $connection = Application::getConnection();
        if ($connection->getType() !== 'mysql') {
            throw new \RuntimeException('AI Lab работает только с MySQL, а база портала: ' . $connection->getType());
        }

        foreach (self::migrations() as $version => $statements) {
            if ($version <= $current) {
                continue;
            }
            foreach ($statements as $sql) {
                $connection->queryExecute($sql);
            }
            Option::set(self::MODULE_ID, 'schema_version', (string)$version);
        }
    }

    /** @return array<int, string[]> */
    private static function migrations(): array
    {
        return [
            1 => [
                "CREATE TABLE IF NOT EXISTS `local_ailab_connection` (
                    `ID`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `NAME`        VARCHAR(255) NOT NULL,
                    `ADAPTER`     VARCHAR(32)  NOT NULL,
                    `BASE_URL`    VARCHAR(500) NOT NULL,
                    `API_KEY_ENC` TEXT NULL,
                    `MODEL`       VARCHAR(255) NOT NULL,
                    `TIMEOUT`     INT NOT NULL DEFAULT 120,
                    `MAX_TOKENS`  INT NOT NULL DEFAULT 2048,
                    `PARAMS`      TEXT NULL,
                    `ACTIVE`      CHAR(1) NOT NULL DEFAULT 'Y',
                    `CREATED_AT`  DATETIME NOT NULL,
                    `UPDATED_AT`  DATETIME NOT NULL,
                    PRIMARY KEY (`ID`)
                ) ENGINE=InnoDB",
            ],
            2 => [
                "CREATE TABLE IF NOT EXISTS `local_ailab_scenario` (
                    `ID`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `CODE`             VARCHAR(64)  NOT NULL,
                    `NAME`             VARCHAR(255) NOT NULL,
                    `CONNECTION_ID`    INT UNSIGNED NOT NULL DEFAULT 0,
                    `MODEL`            VARCHAR(255) NOT NULL DEFAULT '',
                    `MAX_TOKENS`       INT NOT NULL DEFAULT 0,
                    `INSTRUCTION`      MEDIUMTEXT NULL,
                    `OUTPUT_FIELDS`    MEDIUMTEXT NULL,
                    `DAILY_LIMIT`      INT NOT NULL DEFAULT 500,
                    `MAX_PROMPT_CHARS` INT NOT NULL DEFAULT 300000,
                    `TEST_STATE`       MEDIUMTEXT NULL,
                    `ACTIVE`           CHAR(1) NOT NULL DEFAULT 'Y',
                    `CREATED_AT`       DATETIME NOT NULL,
                    `UPDATED_AT`       DATETIME NOT NULL,
                    PRIMARY KEY (`ID`),
                    UNIQUE KEY `ux_local_ailab_scenario_code` (`CODE`)
                ) ENGINE=InnoDB",
                "CREATE TABLE IF NOT EXISTS `local_ailab_source` (
                    `ID`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `SCENARIO_ID` INT UNSIGNED NOT NULL,
                    `SORT`        INT NOT NULL DEFAULT 100,
                    `TYPE`        VARCHAR(16)  NOT NULL,
                    `TITLE`       VARCHAR(255) NOT NULL,
                    `DESCRIPTION` TEXT NULL,
                    `CONFIG`      MEDIUMTEXT NULL,
                    `ACTIVE`      CHAR(1) NOT NULL DEFAULT 'Y',
                    `CREATED_AT`  DATETIME NOT NULL,
                    `UPDATED_AT`  DATETIME NOT NULL,
                    PRIMARY KEY (`ID`),
                    KEY `ix_local_ailab_source_scenario` (`SCENARIO_ID`, `SORT`)
                ) ENGINE=InnoDB",
            ],
            3 => [
                "CREATE TABLE IF NOT EXISTS `local_ailab_task` (
                    `ID`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `SCENARIO_ID`    INT UNSIGNED NOT NULL,
                    `SCENARIO_CODE`  VARCHAR(64)  NOT NULL DEFAULT '',
                    `ORIGIN`         VARCHAR(16)  NOT NULL DEFAULT 'bp',
                    `STATUS`         VARCHAR(16)  NOT NULL DEFAULT 'pending',
                    `INPUT`          MEDIUMTEXT NULL,
                    `BUILT`          MEDIUMTEXT NULL,
                    `PROMPT_HASH`    CHAR(40) NOT NULL DEFAULT '',
                    `RESULT`         MEDIUMTEXT NULL,
                    `ERROR_TEXT`     TEXT NULL,
                    `WARNINGS`       TEXT NULL,
                    `ATTEMPTS`       INT NOT NULL DEFAULT 0,
                    `ATTEMPT_LOG`    MEDIUMTEXT NULL,
                    `CALLS`          INT NOT NULL DEFAULT 0,
                    `INPUT_TOKENS`   INT NOT NULL DEFAULT 0,
                    `OUTPUT_TOKENS`  INT NOT NULL DEFAULT 0,
                    `CACHED_TOKENS`  INT NOT NULL DEFAULT 0,
                    `DURATION_MS`    INT NOT NULL DEFAULT 0,
                    `CONNECTION_ID`  INT UNSIGNED NOT NULL DEFAULT 0,
                    `MODEL`          VARCHAR(255) NOT NULL DEFAULT '',
                    `WORKFLOW_ID`    VARCHAR(64)  NOT NULL DEFAULT '',
                    `ACTIVITY_NAME`  VARCHAR(128) NOT NULL DEFAULT '',
                    `DOCUMENT_ID`    VARCHAR(255) NOT NULL DEFAULT '',
                    `CREATED_BY`     INT NOT NULL DEFAULT 0,
                    `WORKER_TOKEN`   VARCHAR(32)  NOT NULL DEFAULT '',
                    `CREATED_AT`     DATETIME NOT NULL,
                    `NEXT_RUN_AT`    DATETIME NOT NULL,
                    `DEADLINE_AT`    DATETIME NOT NULL,
                    `STARTED_AT`     DATETIME NULL,
                    `FINISHED_AT`    DATETIME NULL,
                    PRIMARY KEY (`ID`),
                    KEY `ix_local_ailab_task_queue` (`STATUS`, `NEXT_RUN_AT`),
                    KEY `ix_local_ailab_task_scenario` (`SCENARIO_ID`, `CREATED_AT`),
                    KEY `ix_local_ailab_task_workflow` (`WORKFLOW_ID`),
                    KEY `ix_local_ailab_task_created` (`CREATED_AT`)
                ) ENGINE=InnoDB",
                "CREATE TABLE IF NOT EXISTS `local_ailab_prompt` (
                    `HASH`       CHAR(40) NOT NULL,
                    `TEXT`       MEDIUMTEXT NOT NULL,
                    `CREATED_AT` DATETIME NOT NULL,
                    PRIMARY KEY (`HASH`)
                ) ENGINE=InnoDB",
            ],
            4 => [
                "ALTER TABLE `local_ailab_task`
                    ADD COLUMN `NOTIFY_STATE` VARCHAR(16) NOT NULL DEFAULT '' AFTER `DOCUMENT_ID`,
                    ADD COLUMN `NOTIFY_TRIES` INT NOT NULL DEFAULT 0 AFTER `NOTIFY_STATE`,
                    ADD COLUMN `NOTIFY_ERROR` TEXT NULL AFTER `NOTIFY_TRIES`,
                    ADD KEY `ix_local_ailab_task_notify` (`NOTIFY_STATE`)",
            ],
        ];
    }
}
