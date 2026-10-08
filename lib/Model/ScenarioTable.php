<?php
namespace Local\AiLab\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Сценарии. OUTPUT_FIELDS — JSON-список полей ответа (Scenario\OutputSchema),
 * TEST_STATE — последние значения формы тестового прогона.
 */
class ScenarioTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'local_ailab_scenario';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
            (new StringField('CODE'))->configureRequired(),
            (new StringField('NAME'))->configureRequired(),
            new IntegerField('CONNECTION_ID'),
            new StringField('MODEL'),
            new IntegerField('MAX_TOKENS'),
            new TextField('INSTRUCTION'),
            new TextField('OUTPUT_FIELDS'),
            new IntegerField('DAILY_LIMIT'),
            new IntegerField('MAX_PROMPT_CHARS'),
            new TextField('TEST_STATE'),
            new StringField('ACTIVE'),
            new DatetimeField('CREATED_AT'),
            new DatetimeField('UPDATED_AT'),
        ];
    }
}
