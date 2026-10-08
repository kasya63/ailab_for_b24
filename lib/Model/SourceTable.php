<?php
namespace Local\AiLab\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/** Источники данных сценария. CONFIG — JSON с настройками конкретного типа. */
class SourceTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'local_ailab_source';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
            (new IntegerField('SCENARIO_ID'))->configureRequired(),
            new IntegerField('SORT'),
            (new StringField('TYPE'))->configureRequired(),
            (new StringField('TITLE'))->configureRequired(),
            new TextField('DESCRIPTION'),
            new TextField('CONFIG'),
            new StringField('ACTIVE'),
            new DatetimeField('CREATED_AT'),
            new DatetimeField('UPDATED_AT'),
        ];
    }
}
