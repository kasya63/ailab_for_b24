<?php
namespace Local\AiLab\Scenario\Source;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Local\AiLab\Data\TableText;
use Local\AiLab\Scenario\SourceDef;
use Local\AiLab\Scenario\SourceException;

/**
 * SELECT через отдельное подключение к БД только на чтение (ailab_readonly в /bitrix/.settings.php).
 * config: sql, id_column, limit
 */
final class SqlSource implements SourceInterface
{
    public const CONNECTION_NAME = 'ailab_readonly';
    public const DEFAULT_LIMIT = 500;
    public const MAX_LIMIT = 5000;
    private const TIMEOUT_MS = 5000;

    public function run(SourceDef $def, array $params): SourceResult
    {
        $connection = self::connection();
        $limit = max(1, min(self::MAX_LIMIT, (int)($def->config['limit'] ?? self::DEFAULT_LIMIT) ?: self::DEFAULT_LIMIT));
        $idColumn = trim((string)($def->config['id_column'] ?? 'ID'));

        $sql = SqlGuard::check((string)($def->config['sql'] ?? ''));
        $helper = $connection->getSqlHelper();
        $sql = SqlGuard::bind($sql, $params, static fn(string $v): string => $helper->convertToDbString($v));
        $wrapped = sprintf('SELECT /*+ MAX_EXECUTION_TIME(%d) */ * FROM (%s) ailab_q LIMIT %d', self::TIMEOUT_MS, $sql, $limit + 1);

        try {
            $rs = $connection->query($wrapped);
        } catch (\Throwable $e) {
            throw new SourceException('SQL-запрос не выполнился: ' . $e->getMessage());
        }

        $rows = [];
        $ids = [];
        $headers = [];
        while ($row = $rs->fetch()) {
            if (count($rows) >= $limit) {
                throw new SourceException(sprintf('Запрос вернул больше %d строк. Сузьте условие или поднимите лимит (до %d).', $limit, self::MAX_LIMIT));
            }
            if (!$headers) {
                $headers = array_combine(array_keys($row), array_keys($row));
            }
            $out = [];
            foreach ($row as $column => $value) {
                $out[$column] = self::stringify($value);
            }
            if ($idColumn !== '' && isset($row[$idColumn]) && is_numeric($row[$idColumn])) {
                $ids[] = (int)$row[$idColumn];
            }
            $rows[] = $out;
        }

        $result = new SourceResult(TableText::render($headers ?: ['—' => '—'], $rows));
        $result->rows = count($rows);
        $result->setIds($ids);
        if ($rows && $idColumn !== '' && !array_key_exists($idColumn, $rows[0])) {
            $result->warnings[] = "В результате нет колонки «{$idColumn}»: поля ответа не смогут ссылаться на эти строки";
        }
        if (!$rows) {
            $result->warnings[] = 'Запрос не вернул ни одной строки';
        }
        return $result;
    }

    public function params(SourceDef $def): array
    {
        return SqlGuard::params((string)($def->config['sql'] ?? ''));
    }

    public static function isConfigured(): bool
    {
        try {
            return self::connection() instanceof Connection;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function connection(): Connection
    {
        try {
            $connection = Application::getConnectionPool()->getConnection(self::CONNECTION_NAME);
        } catch (\Throwable) {
            $connection = null;
        }
        if (!$connection instanceof Connection) {
            throw new SourceException('Не настроено подключение к базе только на чтение «' . self::CONNECTION_NAME . '». Инструкция — в README модуля, раздел «SQL-источник».');
        }
        return $connection;
    }

    private static function stringify(mixed $value): string
    {
        if ($value instanceof \Bitrix\Main\Type\DateTime) {
            return $value->format('d.m.Y H:i');
        }
        if ($value instanceof \Bitrix\Main\Type\Date) {
            return $value->format('d.m.Y');
        }
        return $value === null ? '' : (string)$value;
    }
}
