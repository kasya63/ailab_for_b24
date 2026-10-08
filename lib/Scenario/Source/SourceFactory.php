<?php
namespace Local\AiLab\Scenario\Source;

use Local\AiLab\Scenario\SourceException;

final class SourceFactory
{
    public static function make(string $type): SourceInterface
    {
        return match ($type) {
            'entity' => new EntitySource(),
            'sql'    => new SqlSource(),
            'static' => new StaticSource(),
            default  => throw new SourceException('Неизвестный тип источника: ' . $type),
        };
    }
}
