<?php
namespace Local\AiLab\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Задачи очереди и журнал запросов в одной таблице.
 * INPUT — что передал кубик (RunInput), BUILT — собранный промт без постоянной части
 * (она лежит один раз в local_ailab_prompt по PROMPT_HASH), ATTEMPT_LOG — все обращения к провайдеру.
 */
class TaskTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'local_ailab_task';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
            new IntegerField('SCENARIO_ID'),
            new StringField('SCENARIO_CODE'),
            new StringField('ORIGIN'),
            new StringField('STATUS'),
            new TextField('INPUT'),
            new TextField('BUILT'),
            new StringField('PROMPT_HASH'),
            new TextField('RESULT'),
            new TextField('ERROR_TEXT'),
            new TextField('WARNINGS'),
            new IntegerField('ATTEMPTS'),
            new TextField('ATTEMPT_LOG'),
            new IntegerField('CALLS'),
            new IntegerField('INPUT_TOKENS'),
            new IntegerField('OUTPUT_TOKENS'),
            new IntegerField('CACHED_TOKENS'),
            new IntegerField('DURATION_MS'),
            new IntegerField('CONNECTION_ID'),
            new StringField('MODEL'),
            new StringField('WORKFLOW_ID'),
            new StringField('ACTIVITY_NAME'),
            new StringField('DOCUMENT_ID'),
            new StringField('NOTIFY_STATE'),
            new IntegerField('NOTIFY_TRIES'),
            new TextField('NOTIFY_ERROR'),
            new IntegerField('CREATED_BY'),
            new StringField('WORKER_TOKEN'),
            new DatetimeField('CREATED_AT'),
            new DatetimeField('NEXT_RUN_AT'),
            new DatetimeField('DEADLINE_AT'),
            new DatetimeField('STARTED_AT'),
            new DatetimeField('FINISHED_AT'),
        ];
    }
}
