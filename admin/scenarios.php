<?php
/** AI Lab: список сценариев. */

use Bitrix\Main\Loader;
use Local\AiLab\Admin\ScenarioService;
use Local\AiLab\Admin\View;
use Local\AiLab\Installer;
use Local\AiLab\Json;
use Local\AiLab\Model\ConnectionTable;
use Local\AiLab\Model\ScenarioTable;
use Local\AiLab\Model\SourceTable;
use Local\AiLab\Scenario\OutputSchema;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

/** @global CMain $APPLICATION */
/** @global CUser $USER */
global $APPLICATION, $USER;

if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Раздел AI Lab доступен только администраторам портала.');
}
$fatal = '';
if (!Loader::includeModule('local.ailab')) {
    $fatal = 'Модуль local.ailab не установлен.';
} else {
    try {
        Installer::ensureSchema();
    } catch (\Throwable $e) {
        $fatal = 'Не удалось подготовить таблицы модуля: ' . $e->getMessage();
    }
}

$page = $APPLICATION->GetCurPage();
$errors = [];
$notice = isset($_GET['deleted']) ? 'Сценарий удалён.' : (isset($_GET['saved']) ? 'Сценарий сохранён.' : '');

if ($fatal === '' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!check_bitrix_sessid()) {
        $errors[] = 'Сессия устарела. Обновите страницу и повторите действие.';
    } else {
        try {
            ScenarioService::delete((int)($_POST['ID'] ?? 0));
            LocalRedirect(View::url($page, ['deleted' => 1]));
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$APPLICATION->SetTitle('AI Lab: сценарии');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if ($fatal !== '') {
    View::message('ERROR', $fatal);
} else {
    echo View::styles();
    if ($notice !== '') {
        View::message('OK', $notice);
    }
    View::errors($errors, 'Не удалось удалить');

    (new CAdminContextMenu([
        ['TEXT' => 'Добавить сценарий', 'LINK' => View::url('ailab_scenario_edit.php', ['id' => 0]), 'ICON' => 'btn_new'],
    ]))->Show();

    $rows = ScenarioTable::getList([
        'select' => ['ID', 'CODE', 'NAME', 'CONNECTION_ID', 'OUTPUT_FIELDS', 'ACTIVE'],
        'order'  => ['ID' => 'ASC'],
    ])->fetchAll();

    $connections = [];
    foreach (ConnectionTable::getList(['select' => ['ID', 'NAME', 'MODEL']])->fetchAll() as $c) {
        $connections[(int)$c['ID']] = $c['NAME'] . ' (' . $c['MODEL'] . ')';
    }
    $sourceCounts = [];
    foreach (SourceTable::getList(['select' => ['SCENARIO_ID']])->fetchAll() as $s) {
        $sourceCounts[(int)$s['SCENARIO_ID']] = ($sourceCounts[(int)$s['SCENARIO_ID']] ?? 0) + 1;
    }

    if (!$rows) {
        echo '<div class="ailab-empty">Сценариев пока нет. Сценарий — это инструкция для модели, данные, которые она видит, и формат ответа, который получит бизнес-процесс.</div>';
    } else {
        ?>
        <table class="adm-list-table">
            <thead>
            <tr class="adm-list-table-header">
                <?php foreach (['ID', 'Код', 'Название', 'Подключение', 'Источников', 'Полей ответа', 'Активен', ''] as $head): ?>
                    <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner"><?= View::e($head) ?></div></td>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row):
                $id = (int)$row['ID'];
                $editUrl = View::url('ailab_scenario_edit.php', ['id' => $id]);
                ?>
                <tr class="adm-list-table-row">
                    <td class="adm-list-table-cell"><?= $id ?></td>
                    <td class="adm-list-table-cell ailab-mono"><?= View::e($row['CODE']) ?></td>
                    <td class="adm-list-table-cell"><a href="<?= View::e($editUrl) ?>"><?= View::e($row['NAME']) ?></a></td>
                    <td class="adm-list-table-cell"><?= View::e($connections[(int)$row['CONNECTION_ID']] ?? 'не найдено') ?></td>
                    <td class="adm-list-table-cell"><?= (int)($sourceCounts[$id] ?? 0) ?></td>
                    <td class="adm-list-table-cell"><?= count(OutputSchema::fromJson($row['OUTPUT_FIELDS'])->fields()) ?></td>
                    <td class="adm-list-table-cell"><?= $row['ACTIVE'] === 'Y' ? 'да' : 'нет' ?></td>
                    <td class="adm-list-table-cell ailab-actions">
                        <a href="<?= View::e($editUrl) ?>">Изменить</a>
                        &nbsp; <a href="<?= View::e(View::url('ailab_scenario_test.php', ['id' => $id])) ?>">Тестовый прогон</a>
                        <form method="post" action="<?= View::e(View::url($page)) ?>"
                              onsubmit="return confirm(<?= View::e(Json::encode('Удалить сценарий «' . $row['NAME'] . '» вместе с источниками?')) ?>);">
                            <?= bitrix_sessid_post() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="ID" value="<?= $id ?>">
                            <input type="submit" value="Удалить">
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
