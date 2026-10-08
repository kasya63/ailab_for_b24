<?php
namespace Local\AiLab\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Подключения к провайдерам ИИ. DDL — в Installer::migrations().
 * API_KEY_ENC хранится только в зашифрованном виде (Local\AiLab\Secret).
 * PARAMS — JSON с настройками адаптера.
 */
class ConnectionTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'local_ailab_connection';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
            (new StringField('NAME'))->configureRequired(),
            (new StringField('ADAPTER'))->configureRequired(),
            (new StringField('BASE_URL'))->configureRequired(),
            new TextField('API_KEY_ENC'),
            (new StringField('MODEL'))->configureRequired(),
            new IntegerField('TIMEOUT'),
            new IntegerField('MAX_TOKENS'),
            new TextField('PARAMS'),
            new StringField('ACTIVE'),
            new DatetimeField('CREATED_AT'),
            new DatetimeField('UPDATED_AT'),
        ];
    }
}
