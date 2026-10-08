<?php
/**
 * local.ailab — запросы к ИИ из бизнес-процессов.
 * Автозагрузка классов Local\AiLab\* из lib/: имя класса = путь к файлу.
 */

if (!defined('LOCAL_AILAB_AUTOLOAD')) {
    define('LOCAL_AILAB_AUTOLOAD', true);

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Local\\AiLab\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = __DIR__ . '/lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}
