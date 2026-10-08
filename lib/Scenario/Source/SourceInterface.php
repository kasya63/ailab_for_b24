<?php
namespace Local\AiLab\Scenario\Source;

use Local\AiLab\Scenario\SourceDef;

interface SourceInterface
{
    /** @param array<string, string> $params */
    public function run(SourceDef $def, array $params): SourceResult;

    /** @return string[] имена параметров без двоеточия */
    public function params(SourceDef $def): array;
}
