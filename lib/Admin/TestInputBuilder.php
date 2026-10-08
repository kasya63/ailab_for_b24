<?php
namespace Local\AiLab\Admin;

use Local\AiLab\Files\FileExtractor;
use Local\AiLab\Scenario\ElementInputs;
use Local\AiLab\Scenario\RunInput;

/** Входные данные тестового прогона: поля выбранного элемента + строки, введённые руками. */
final class TestInputBuilder
{
    public const BLANK_ROWS = 3;

    public static function fromPost(array $post): array
    {
        $inputs = [];
        foreach ((array)($post['INPUTS'] ?? []) as $row) {
            $item = [
                'label'       => trim((string)($row['label'] ?? '')),
                'description' => trim((string)($row['description'] ?? '')),
                'value'       => (string)($row['value'] ?? ''),
            ];
            if ($item['label'] !== '' || trim($item['value']) !== '') {
                $inputs[] = $item;
            }
        }
        return [
            'snippet' => (string)($post['SNIPPET'] ?? ''),
            'params'  => array_map('strval', (array)($post['PARAMS'] ?? [])),
            'inputs'  => $inputs,
            'element' => [
                'entity' => (string)($post['EL_ENTITY'] ?? ''),
                'id'     => max(0, (int)($post['EL_ID'] ?? 0)),
                'fields' => array_values(array_map('strval', (array)($post['EL_FIELDS'] ?? []))),
                'file_mode' => isset(FileExtractor::MODES[$post['EL_FILE_MODE'] ?? '']) ? (string)$post['EL_FILE_MODE'] : FileExtractor::MODE_NAME,
            ],
        ];
    }

    public static function build(array $state): RunInput
    {
        $input = new RunInput();
        $input->snippet = (string)($state['snippet'] ?? '');
        $input->params = array_map('strval', (array)($state['params'] ?? []));

        $element = (array)($state['element'] ?? []);
        $key = (string)($element['entity'] ?? '');
        $id = (int)($element['id'] ?? 0);
        $fields = (array)($element['fields'] ?? []);
        if ($key !== '' && $id > 0 && $fields) {
            ElementInputs::add($input, $key, $id, $fields, (string)($element['file_mode'] ?? FileExtractor::MODE_NAME));
        }

        foreach ((array)($state['inputs'] ?? []) as $row) {
            $input->addInput((string)($row['label'] ?? ''), (string)($row['value'] ?? ''), '', (string)($row['description'] ?? ''));
        }
        return $input;
    }
}
