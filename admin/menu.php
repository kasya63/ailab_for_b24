<?php
/** @global CUser $USER */
global $USER;

if (!is_object($USER) || !$USER->IsAdmin()) {
    return false;
}

// Новые страницы модуля появляются в /bitrix/admin без переустановки.
if (\Bitrix\Main\Loader::includeModule('local.ailab')) {
    \Local\AiLab\Installer::ensureAdminStubs();
    \Local\AiLab\Installer::ensureActivityStubs();
}

$aMenu = [
    'parent_menu' => 'global_menu_services',
    'section'     => 'local_ailab',
    'sort'        => 950,
    'text'        => 'AI Lab',
    'title'       => 'Запросы к ИИ из бизнес-процессов',
    'icon'        => 'util_menu_icon',
    'page_icon'   => 'util_page_icon',
    'items_id'    => 'menu_local_ailab',
    'items'       => [
        [
            'text'     => 'Сценарии',
            'title'    => 'Промт, источники данных и формат ответа',
            'url'      => 'ailab_scenarios.php?lang=' . LANGUAGE_ID,
            'more_url' => ['ailab_scenarios.php', 'ailab_scenario_edit.php', 'ailab_source_edit.php', 'ailab_scenario_test.php'],
        ],
        [
            'text'     => 'Журнал',
            'title'    => 'Очередь и история запросов к ИИ',
            'url'      => 'ailab_tasks.php?lang=' . LANGUAGE_ID,
            'more_url' => ['ailab_tasks.php', 'ailab_task.php'],
        ],
        [
            'text'     => 'Настройки',
            'title'    => 'Очередь, журнал, файлы, уведомления',
            'url'      => 'ailab_settings.php?lang=' . LANGUAGE_ID,
            'more_url' => ['ailab_settings.php'],
        ],
        [
            'text'     => 'Подключения',
            'title'    => 'Провайдеры ИИ, модели и API-ключи',
            'url'      => 'ailab_connections.php?lang=' . LANGUAGE_ID,
            'more_url' => ['ailab_connections.php'],
        ],
    ],
];

return $aMenu;
