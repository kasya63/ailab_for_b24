<?php
namespace Local\AiLab\Ai;

use Local\AiLab\Model\ConnectionTable;
use Local\AiLab\Secret;

/** Подключение с уже расшифрованным ключом. Наружу (в лог, в интерфейс) ключ не отдаётся. */
final class Connection
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $adapter,
        public readonly string $baseUrl,
        public readonly string $apiKey,
        public readonly string $model,
        public readonly int $timeout,
        public readonly int $maxTokens,
        public readonly array $params,
        public readonly bool $active,
    ) {
    }

    public static function load(int $id): self
    {
        $row = $id > 0 ? ConnectionTable::getById($id)->fetch() : false;
        if (!$row) {
            throw new \RuntimeException("Подключение #{$id} не найдено");
        }
        return self::fromRow($row);
    }

    public static function fromRow(array $row): self
    {
        $params = json_decode((string)($row['PARAMS'] ?? ''), true);

        return new self(
            (int)$row['ID'],
            (string)$row['NAME'],
            (string)$row['ADAPTER'],
            (string)$row['BASE_URL'],
            Secret::decrypt((string)($row['API_KEY_ENC'] ?? '')),
            (string)$row['MODEL'],
            max(5, (int)$row['TIMEOUT']),
            max(16, (int)$row['MAX_TOKENS']),
            is_array($params) ? $params : [],
            ($row['ACTIVE'] ?? 'Y') === 'Y',
        );
    }

    public function param(string $name, mixed $default = null): mixed
    {
        return $this->params[$name] ?? $default;
    }

    public function withTimeout(int $seconds): self
    {
        return new self(
            $this->id, $this->name, $this->adapter, $this->baseUrl, $this->apiKey,
            $this->model, max(5, $seconds), $this->maxTokens, $this->params, $this->active,
        );
    }
}
