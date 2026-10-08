<?php
namespace Local\AiLab;

final class Json
{
    public static function encode(mixed $value, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return json_encode($value, $flags);
    }

    /** JSON-объект → массив; всё остальное (скаляр, список, мусор) → null. */
    public static function decodeObject(string $json): ?array
    {
        $json = trim($json);
        if ($json === '') {
            return null;
        }
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return null;
        }
        return $value;
    }

    /**
     * Достаёт JSON-объект из текста модели: чистый JSON, JSON в ```-блоке
     * или JSON с пояснениями вокруг.
     */
    public static function extractObject(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        $unfenced = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $text) ?? $text;
        $value = self::decodeObject($unfenced);
        if ($value !== null) {
            return $value;
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        return self::decodeObject(substr($text, $start, $end - $start + 1));
    }

    /** Для показа в админке: красиво, если это JSON, иначе как есть; длинное обрезается. */
    public static function prettyOrRaw(string $raw, int $limit = 20000): string
    {
        if ($raw === '') {
            return '';
        }
        try {
            $out = self::encode(json_decode($raw, true, 512, JSON_THROW_ON_ERROR), true);
        } catch (\JsonException) {
            $out = $raw;
        }
        $len = mb_strlen($out);
        if ($len > $limit) {
            $out = mb_substr($out, 0, $limit) . "\n… обрезано, всего {$len} символов";
        }
        return $out;
    }
}
