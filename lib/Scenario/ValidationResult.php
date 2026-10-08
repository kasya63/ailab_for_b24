<?php
namespace Local\AiLab\Scenario;

final class ValidationResult
{
    /**
     * @param array<string, mixed> $data   проверенные и приведённые к типам значения
     * @param string[] $errors              из-за них ответ не принят
     * @param string[] $warnings            ответ принят, но что-то поправлено
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $data,
        public readonly array $errors,
        public readonly array $warnings,
    ) {
    }
}
