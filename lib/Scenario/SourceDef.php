<?php
namespace Local\AiLab\Scenario;

/** Источник данных сценария, как он сохранён в настройках. */
final class SourceDef
{
    public const TYPES = [
        'entity' => 'Элементы CRM, смарт-процесса, списка или HL-блока',
        'sql'    => 'SQL-запрос',
        'static' => 'Текст',
    ];

    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $title,
        public readonly string $description,
        public readonly array $config,
        public readonly int $sort = 100,
        public readonly bool $active = true,
    ) {
    }

    public static function fromRow(array $row): self
    {
        $config = json_decode((string)($row['CONFIG'] ?? ''), true);
        return new self(
            (int)$row['ID'],
            (string)$row['TYPE'],
            (string)$row['TITLE'],
            (string)($row['DESCRIPTION'] ?? ''),
            is_array($config) ? $config : [],
            (int)($row['SORT'] ?? 100),
            ($row['ACTIVE'] ?? 'Y') === 'Y',
        );
    }

    /** Источник даёт ID, на которые могут ссылаться поля ответа. */
    public function providesIds(): bool
    {
        return in_array($this->type, ['entity', 'sql'], true);
    }
}
