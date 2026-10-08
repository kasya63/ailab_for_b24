<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$arActivityDescription = [
    'NAME'        => 'AI Lab: запрос к ИИ',
    'DESCRIPTION' => 'Отправляет данные в сценарий AI Lab и ждёт ответа модели. Результат — в «Дополнительных результатах».',
    'TYPE'        => 'activity',
    'CLASS'       => 'AilabRequestActivity',
    'JSCLASS'     => 'BizProcActivity',
    'CATEGORY'    => [
        'ID'       => 'other',
        'OWN_ID'   => 'ailab',
        'OWN_NAME' => 'AI Lab',
    ],
    'RETURN' => [
        'status' => [
            'NAME' => 'Статус: success / error / invalid / timeout',
            'TYPE' => 'string',
        ],
        'error_text' => [
            'NAME' => 'Текст ошибки',
            'TYPE' => 'string',
        ],
        'log_id' => [
            'NAME' => 'Номер задачи в журнале AI Lab',
            'TYPE' => 'int',
        ],
    ],
    'ADDITIONAL_RESULT' => ['ResultFields'],
];
