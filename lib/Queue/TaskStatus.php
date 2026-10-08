<?php
namespace Local\AiLab\Queue;

final class TaskStatus
{
    public const PENDING    = 'pending';
    public const PROCESSING = 'processing';
    public const SUCCESS    = 'success';
    public const ERROR      = 'error';
    public const INVALID    = 'invalid';
    public const TIMEOUT    = 'timeout';
    public const CANCELLED  = 'cancelled';

    public const LABELS = [
        self::PENDING    => 'в очереди',
        self::PROCESSING => 'отправлено',
        self::SUCCESS    => 'успешно',
        self::ERROR      => 'ошибка',
        self::INVALID    => 'не по формату',
        self::TIMEOUT    => 'время вышло',
        self::CANCELLED  => 'отменено',
    ];

    public const FINAL = [self::SUCCESS, self::ERROR, self::INVALID, self::TIMEOUT, self::CANCELLED];

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function isFinal(string $status): bool
    {
        return in_array($status, self::FINAL, true);
    }
}
