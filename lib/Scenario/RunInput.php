<?php
namespace Local\AiLab\Scenario;

/**
 * Переменная часть запроса: то, что в бизнес-процессе придёт из кубика,
 * а в тестовом прогоне вводится руками.
 */
final class RunInput
{
    /** Кусок промта из кубика. */
    public string $snippet = '';

    /** @var list<array{label: string, type: string, description: string, value: string}> */
    public array $inputs = [];

    /** @var array<string, string> значения параметров источников без двоеточия */
    public array $params = [];

    /** @var list<array{label: string, file_id: int, mode: string}> файлы, содержимое которых уходит модели */
    public array $files = [];

    public function toArray(): array
    {
        return ['snippet' => $this->snippet, 'inputs' => $this->inputs, 'params' => $this->params, 'files' => $this->files];
    }

    public static function fromArray(array $data): self
    {
        $input = new self();
        $input->snippet = (string)($data['snippet'] ?? '');
        foreach ((array)($data['inputs'] ?? []) as $in) {
            $input->addInput((string)($in['label'] ?? ''), (string)($in['value'] ?? ''), (string)($in['type'] ?? ''), (string)($in['description'] ?? ''));
        }
        $input->params = array_map('strval', (array)($data['params'] ?? []));
        foreach ((array)($data['files'] ?? []) as $file) {
            $input->addFile((string)($file['label'] ?? ''), (int)($file['file_id'] ?? 0), (string)($file['mode'] ?? 'text'));
        }
        return $input;
    }

    public function addFile(string $label, int $fileId, string $mode): void
    {
        if ($fileId > 0) {
            $this->files[] = ['label' => $label, 'file_id' => $fileId, 'mode' => $mode];
        }
    }

    public function addInput(string $label, string $value, string $type = '', string $description = ''): void
    {
        $this->inputs[] = ['label' => $label, 'type' => $type, 'description' => $description, 'value' => $value];
    }
}
