<?php
namespace Local\AiLab\Scenario\Source;

use Local\AiLab\Data\EntityCatalog;
use Local\AiLab\Data\EntityReader;
use Local\AiLab\Data\TableText;
use Local\AiLab\Scenario\Params;
use Local\AiLab\Scenario\SourceDef;
use Local\AiLab\Scenario\SourceException;

/**
 * Элементы CRM / смарт-процесса / списка / HL-блока: выбранные поля, фильтр, сортировка, лимит.
 * config: entity, fields[], filter[{field, op, value}], order_field, order_dir, limit
 */
final class EntitySource implements SourceInterface
{
    public const DEFAULT_LIMIT = 500;

    public function run(SourceDef $def, array $params): SourceResult
    {
        $c = $def->config;
        $key = (string)($c['entity'] ?? '');
        if ($key === '') {
            throw new SourceException('Не выбрана сущность');
        }
        $limit = (int)($c['limit'] ?? self::DEFAULT_LIMIT) ?: self::DEFAULT_LIMIT;

        $data = EntityReader::read(
            $key,
            (array)($c['fields'] ?? []),
            (array)($c['filter'] ?? []),
            $params,
            $limit,
            (string)($c['order_field'] ?? 'ID'),
            (string)($c['order_dir'] ?? 'ASC'),
        );

        if ($data['overflow']) {
            throw new SourceException(sprintf(
                'Источник вернул больше %d строк. Сузьте фильтр или поднимите лимит (до %d) — молча обрезать список нельзя: модель выберет из неполного.',
                $limit,
                EntityReader::MAX_LIMIT
            ));
        }

        $headers = ['ID' => 'ID'];
        foreach ($data['fields'] as $code => $meta) {
            $headers[$code] = $meta->title;
        }

        $result = new SourceResult(TableText::render($headers, $data['rows']));
        $result->rows = count($data['rows']);
        $result->setIds($data['ids']);
        if ($result->rows === 0) {
            $result->warnings[] = 'Источник «' . EntityCatalog::title($key) . '» не вернул ни одной строки';
        }
        return $result;
    }

    public function params(SourceDef $def): array
    {
        $names = [];
        foreach ((array)($def->config['filter'] ?? []) as $row) {
            $name = Params::nameOf((string)($row['value'] ?? ''));
            if ($name !== null) {
                $names[] = $name;
            }
        }
        return array_values(array_unique($names));
    }
}
