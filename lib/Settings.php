<?php
namespace Local\AiLab;

use Bitrix\Main\Config\Option;

/** Настройки модуля (страница «AI Lab → Настройки»). Значения по умолчанию — здесь. */
final class Settings
{
    public const MODULE_ID = 'local.ailab';

    public const DEFAULTS = [
        'queue_parallel'      => '3',
        'queue_timeout_min'   => '10',
        'queue_paused'        => 'N',
        'queue_max_pending'   => '300',
        'log_days'            => '90',
        'files_max_mb'        => '20',
        'files_image_max_mb'  => '5',
        'files_pdf_max_pages' => '50',
        'files_text_max_chars'=> '80000',
        'files_max_count'     => '10',
        'path_soffice'        => '/usr/bin/soffice',
        'path_pdftotext'      => '/usr/bin/pdftotext',
        'path_pdfinfo'        => '/usr/bin/pdfinfo',
        'notify_enabled'      => 'Y',
    ];

    public static function get(string $key): string
    {
        return (string)Option::get(self::MODULE_ID, $key, self::DEFAULTS[$key] ?? '');
    }

    public static function int(string $key, int $min, int $max): int
    {
        $value = (int)self::get($key);
        if ($value < $min || $value > $max) {
            $value = (int)(self::DEFAULTS[$key] ?? $min);
        }
        return max($min, min($max, $value));
    }

    public static function flag(string $key): bool
    {
        return self::get($key) === 'Y';
    }

    public static function set(string $key, string $value): void
    {
        Option::set(self::MODULE_ID, $key, $value);
    }

    /* Удобные геттеры */

    public static function parallel(): int
    {
        return self::int('queue_parallel', 1, 10);
    }

    /** Ожидание ответа по умолчанию, секунд. */
    public static function defaultTimeout(): int
    {
        return self::int('queue_timeout_min', 1, 1440) * 60;
    }

    public static function queuePaused(): bool
    {
        return self::flag('queue_paused');
    }

    public static function maxPending(): int
    {
        return self::int('queue_max_pending', 10, 100000);
    }

    public static function logDays(): int
    {
        return self::int('log_days', 1, 3650);
    }

    public static function binary(string $name): string
    {
        $configured = self::get('path_' . $name);
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }
        return $name; // пусть ищет по PATH
    }
}
