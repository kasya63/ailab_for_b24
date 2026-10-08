<?php
namespace Local\AiLab\Scenario\Source;

use Local\AiLab\Scenario\Params;
use Local\AiLab\Scenario\SourceException;

/**
 * Проверка SQL-источника: один SELECT/WITH, без комментариев, INTO, блокировок и функций-«тормозов».
 * Это второй рубеж: первый — пользователь БД только с правом SELECT.
 */
final class SqlGuard
{
    private const LITERALS = '/(\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*")/s';

    private const FORBIDDEN = [
        '/(--|#|\/\*)/'                                   => 'комментарии в запросе не поддерживаются',
        '/;/'                                             => 'можно только один запрос, без «;»',
        '/\bINTO\b/i'                                     => 'INTO запрещён',
        '/\bFOR\s+(UPDATE|SHARE)\b/i'                     => 'блокирующие чтения запрещены',
        '/\bLOCK\s+IN\s+SHARE\s+MODE\b/i'                 => 'блокирующие чтения запрещены',
        '/\b(SLEEP|BENCHMARK|GET_LOCK|LOAD_FILE)\s*\(/i'  => 'эта функция запрещена',
    ];

    /** @return string запрос без завершающей «;» */
    public static function check(string $sql): string
    {
        $sql = trim($sql);
        $sql = trim((string)preg_replace('/;\s*$/', '', $sql));
        if ($sql === '') {
            throw new SourceException('Пустой SQL-запрос');
        }
        if (!preg_match('/^(SELECT|WITH)\b/i', $sql)) {
            throw new SourceException('Запрос должен начинаться с SELECT или WITH');
        }

        $outsideLiterals = preg_replace(self::LITERALS, "''", $sql) ?? $sql;
        foreach (self::FORBIDDEN as $pattern => $message) {
            if (preg_match($pattern, $outsideLiterals)) {
                throw new SourceException('SQL: ' . $message);
            }
        }
        return $sql;
    }

    /**
     * Подставляет :параметры экранированными строками вне строковых литералов.
     * @param callable(string): string $quote
     */
    public static function bind(string $sql, array $params, callable $quote): string
    {
        $parts = preg_split(self::LITERALS, $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue; // строковый литерал — не трогаем
            }
            $parts[$i] = preg_replace_callback('/(?<![\w:]):([a-zA-Z_][a-zA-Z0-9_]*)/', static function (array $m) use ($params, $quote): string {
                if (!array_key_exists($m[1], $params)) {
                    throw new SourceException('Не передано значение параметра :' . $m[1]);
                }
                return $quote((string)$params[$m[1]]);
            }, $part);
        }
        return implode('', $parts);
    }

    /** @return string[] */
    public static function params(string $sql): array
    {
        $outsideLiterals = preg_replace(self::LITERALS, "''", $sql) ?? $sql;
        return Params::find($outsideLiterals);
    }
}
