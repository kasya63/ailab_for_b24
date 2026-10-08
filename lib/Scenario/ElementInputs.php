<?php
namespace Local\AiLab\Scenario;

use Local\AiLab\Data\EntityCatalog;
use Local\AiLab\Data\EntityReader;
use Local\AiLab\Files\FileExtractor;

/**
 * Поля элемента → входы запроса. Общее для кубика и тестового прогона, поэтому модель
 * видит в тесте ровно то же, что получит из бизнес-процесса.
 * Поля-файлы: имя уходит всегда, содержимое — если выбран режим «текстом» или «целиком».
 */
final class ElementInputs
{
    /** @param string[] $codes */
    public static function add(RunInput $input, string $entityKey, int $elementId, array $codes, string $fileMode = FileExtractor::MODE_NAME): void
    {
        $data = EntityReader::read($entityKey, $codes, [['field' => 'ID', 'op' => '=', 'value' => (string)$elementId]], [], 1);
        if (!$data['rows']) {
            throw new SourceException(sprintf('Элемент #%d не найден в «%s»', $elementId, EntityCatalog::title($entityKey)));
        }
        $row = $data['rows'][0];
        $raw = $data['raw'][0] ?? [];

        foreach ($data['fields'] as $code => $meta) {
            $value = (string)($row[$code] ?? '');
            if ($meta->isFile() && $fileMode !== FileExtractor::MODE_NAME) {
                $ids = array_values(array_filter(array_map('intval', is_array($raw[$code] ?? null) ? $raw[$code] : [$raw[$code] ?? 0])));
                foreach ($ids as $fileId) {
                    $input->addFile($meta->title, $fileId, $fileMode);
                }
                if ($ids) {
                    $value .= ' — содержимое в разделе «Файлы»';
                }
            }
            $input->addInput($meta->title, $value, $meta->label());
        }
    }
}
