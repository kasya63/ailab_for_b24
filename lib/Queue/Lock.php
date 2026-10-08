<?php
namespace Local\AiLab\Queue;

use Local\AiLab\Secret;

/**
 * Неблокирующая файловая блокировка (flock): второй экземпляр обработчика сразу выходит.
 * Блокировку снимает ОС, даже если процесс упал, — «вечных» замков не бывает.
 */
final class Lock
{
    /** @return resource|null null — блокировку держит другой процесс */
    public static function acquire(string $name)
    {
        $dir = Secret::privateDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Не удалось создать каталог {$dir}");
        }
        $path = $dir . '/' . $name . '.lock';
        $fp = @fopen($path, 'c');
        if ($fp === false) {
            throw new \RuntimeException("Не удалось открыть файл блокировки {$path}. Обработчик должен работать от того же пользователя, что и сайт (bitrix).");
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return null;
        }
        ftruncate($fp, 0);
        fwrite($fp, (string)getmypid());
        fflush($fp);
        return $fp;
    }

    /** @param resource $fp */
    public static function release($fp): void
    {
        if (is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
