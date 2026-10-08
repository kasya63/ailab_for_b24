<?php
namespace Local\AiLab\Queue;

/** @deprecated оставлен для совместимости, используйте \Local\AiLab\Settings */
final class Settings
{
    public static function parallel(): int
    {
        return \Local\AiLab\Settings::parallel();
    }

    public static function defaultTimeout(): int
    {
        return \Local\AiLab\Settings::defaultTimeout();
    }
}
