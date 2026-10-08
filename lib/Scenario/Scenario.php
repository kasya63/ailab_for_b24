<?php
namespace Local\AiLab\Scenario;

use Local\AiLab\Model\ScenarioTable;
use Local\AiLab\Model\SourceTable;

final class Scenario
{
    /** @param SourceDef[] $sources */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $name,
        public readonly int $connectionId,
        public readonly string $model,
        public readonly int $maxTokens,
        public readonly string $instruction,
        public readonly OutputSchema $schema,
        public readonly array $sources,
        public readonly int $dailyLimit = 500,
        public readonly int $maxPromptChars = 300000,
        public readonly bool $active = true,
    ) {
    }

    public static function load(int $id): self
    {
        $row = $id > 0 ? ScenarioTable::getById($id)->fetch() : false;
        if (!$row) {
            throw new \RuntimeException("Сценарий #{$id} не найден");
        }
        return self::fromRow($row);
    }

    public static function loadByCode(string $code): self
    {
        $row = ScenarioTable::getList(['filter' => ['=CODE' => $code], 'limit' => 1])->fetch();
        if (!$row) {
            throw new \RuntimeException("Сценарий «{$code}» не найден");
        }
        return self::fromRow($row);
    }

    public static function fromRow(array $row): self
    {
        $sources = [];
        $rs = SourceTable::getList([
            'filter' => ['=SCENARIO_ID' => (int)$row['ID']],
            'order'  => ['SORT' => 'ASC', 'ID' => 'ASC'],
        ]);
        while ($sourceRow = $rs->fetch()) {
            $sources[] = SourceDef::fromRow($sourceRow);
        }

        return new self(
            (int)$row['ID'],
            (string)$row['CODE'],
            (string)$row['NAME'],
            (int)$row['CONNECTION_ID'],
            (string)$row['MODEL'],
            (int)$row['MAX_TOKENS'],
            (string)$row['INSTRUCTION'],
            OutputSchema::fromJson($row['OUTPUT_FIELDS']),
            $sources,
            (int)$row['DAILY_LIMIT'],
            max(1000, (int)$row['MAX_PROMPT_CHARS']),
            $row['ACTIVE'] === 'Y',
        );
    }

    /** @return string[] имена параметров всех активных источников */
    public function params(): array
    {
        $names = [];
        foreach ($this->sources as $source) {
            if ($source->active) {
                $names = array_merge($names, Source\SourceFactory::make($source->type)->params($source));
            }
        }
        return array_values(array_unique($names));
    }
}
