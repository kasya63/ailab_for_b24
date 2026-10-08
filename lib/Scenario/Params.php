<?php
namespace Local\AiLab\Scenario;

/**
 * Именованные параметры источников: :org_id, :user_id …
 * В фильтре значение-параметр пишется целиком (":org_id"), в SQL — как обычно (WHERE X = :org_id).
 * Подставляются только экранированными значениями, склейки строк нет.
 */
final class Params
{
    private const IN_TEXT = '/(?<![\w:]):([a-zA-Z_][a-zA-Z0-9_]*)/';

    /** @return string[] */
    public static function find(string $text): array
    {
        preg_match_all(self::IN_TEXT, $text, $m);
        return array_values(array_unique($m[1]));
    }

    /** Значение целиком является параметром — вернёт имя без двоеточия. */
    public static function nameOf(string $value): ?string
    {
        return preg_match('/^:([a-zA-Z_][a-zA-Z0-9_]*)$/', trim($value), $m) ? $m[1] : null;
    }

    public static function resolve(string $value, array $params): string
    {
        $name = self::nameOf($value);
        if ($name === null) {
            return $value;
        }
        if (!array_key_exists($name, $params)) {
            throw new SourceException('Не передано значение параметра :' . $name);
        }
        return (string)$params[$name];
    }
}
