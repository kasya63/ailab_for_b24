<?php
/** AI Lab: источник данных сценария. */

use Bitrix\Main\Loader;
use Local\AiLab\Admin\SourceService;
use Local\AiLab\Admin\View;
use Local\AiLab\Data\EntityCatalog;
use Local\AiLab\Data\EntityReader;
use Local\AiLab\Data\FilterBuilder;
use Local\AiLab\Installer;
use Local\AiLab\Model\ScenarioTable;
use Local\AiLab\Model\SourceTable;
use Local\AiLab\Scenario\Params;
use Local\AiLab\Scenario\SourceDef;
use Local\AiLab\Scenario\Source\SourceFactory;
use Local\AiLab\Scenario\Source\SqlGuard;
use Local\AiLab\Scenario\Source\SqlSource;

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
$scenarioId = max(0, (int)($_GET['scenario'] ?? 0));
$id = max(0, (int)($_GET['id'] ?? 0));
$type = (string)($_GET['type'] ?? '');
$backUrl = View::url('ailab_scenario_edit.php', ['id' => $scenarioId, 'ailab_scenario_active_tab' => 'ailab_sources']);
$errors = [];

/* Удаление по ссылке из списка источников сценария. */
if ($fatal === '' && ($_GET['action'] ?? '') === 'delete') {
    if (!check_bitrix_sessid()) {
        $errors[] = 'Сессия устарела. Вернитесь к сценарию и повторите.';
    } else {
        try {
            $ownerId = SourceService::delete($id);
            LocalRedirect(View::url('ailab_scenario_edit.php', ['id' => $ownerId, 'ailab_scenario_active_tab' => 'ailab_sources', 'source_deleted' => 1]));
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$scenarioRow = null;
$row = null;
if ($fatal === '') {
    $scenarioRow = ScenarioTable::getById($scenarioId)->fetch() ?: null;
    if ($scenarioRow === null) {
        $fatal = 'Сценарий не найден.';
    } elseif ($id > 0) {
        $row = SourceTable::getById($id)->fetch() ?: null;
        if ($row === null || (int)$row['SCENARIO_ID'] !== $scenarioId) {
            $fatal = 'Источник не найден.';
        } else {
            $type = (string)$row['TYPE'];
        }
    }
    if ($fatal === '' && !isset(SourceDef::TYPES[$type])) {
        $fatal = 'Не выбран тип источника.';
    }
}

$posted = null;
$preview = null;
$previewError = '';
$notice = isset($_GET['saved']) ? 'Источник сохранён.' : '';

if ($fatal === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_bitrix_sessid()) {
        $errors[] = 'Сессия устарела. Обновите страницу и повторите действие.';
    } else {
        $posted = $_POST;
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'save' || $action === 'apply') {
                [$savedId, $errors] = SourceService::save($scenarioId, $id, $type, $_POST);
                if (!$errors) {
                    if ($action === 'apply') {
                        LocalRedirect(View::url($page, ['scenario' => $scenarioId, 'id' => $savedId, 'saved' => 1]));
                    }
                    LocalRedirect(View::url('ailab_scenario_edit.php', ['id' => $scenarioId, 'ailab_scenario_active_tab' => 'ailab_sources', 'source_saved' => 1]));
                }
            } elseif ($action === 'preview') {
                $def = SourceService::buildDef($id, $type, $_POST, $errors);
                if ($def !== null) {
                    @set_time_limit(120);
                    try {
                        $preview = SourceFactory::make($type)->run($def, array_map('strval', (array)($_POST['PREVIEW_PARAMS'] ?? [])));
                    } catch (\Throwable $e) {
                        $previewError = $e->getMessage();
                    }
                }
            }
            // action=reload: сменили сущность — просто перерисовываем форму с новыми полями
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$APPLICATION->SetTitle('AI Lab: источник «' . ($row['TITLE'] ?? 'новый') . '»' . ($scenarioRow ? ' — ' . $scenarioRow['NAME'] : ''));
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if ($fatal !== '') {
    View::message('ERROR', $fatal);
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

echo View::styles();
if ($notice !== '') {
    View::message('OK', $notice);
}
View::errors($errors, 'Проверьте источник');
if ($previewError !== '') {
    View::message('ERROR', 'Источник не отработал', View::e($previewError));
}
if ($preview !== null) {
    View::message('OK', 'Источник отработал — так его увидит модель', View::sourcePreview($preview));
}

if ($posted !== null) {
    $v = $posted + array_fill_keys(array_keys(SourceService::defaults()), '');
    foreach (['FIELDS', 'FILTER'] as $listKey) {
        $v[$listKey] = array_values((array)($posted[$listKey] ?? []));
    }
} elseif ($row !== null) {
    $v = SourceService::rowToForm($row);
} else {
    $v = SourceService::defaults();
}
$val = static fn(string $k): string => View::e($v[$k] ?? '');

/* Поля выбранной сущности и параметры для предпросмотра. */
$entityKey = (string)($v['ENTITY'] ?? '');
$fields = [];
$fieldsError = '';
$paramNames = [];
if ($type === 'entity') {
    if ($entityKey !== '') {
        try {
            $fields = EntityCatalog::fields($entityKey);
        } catch (\Throwable $e) {
            $fieldsError = $e->getMessage();
        }
    }
    foreach ((array)$v['FILTER'] as $f) {
        $name = Params::nameOf((string)($f['value'] ?? ''));
        if ($name !== null) {
            $paramNames[] = $name;
        }
    }
} elseif ($type === 'sql') {
    $paramNames = SqlGuard::params((string)$v['SQL']);
}
$paramNames = array_values(array_unique($paramNames));
$previewParams = (array)($posted['PREVIEW_PARAMS'] ?? []);

(new CAdminContextMenu([
    ['TEXT' => 'К сценарию', 'LINK' => $backUrl, 'ICON' => 'btn_list'],
]))->Show();

$tabs = new CAdminTabControl('ailab_source', [
    ['DIV' => 'ailab_src', 'TAB' => SourceDef::TYPES[$type], 'TITLE' => 'Какие данные увидит модель'],
]);
?>
<form method="post" action="<?= View::e(View::url($page, ['scenario' => $scenarioId, 'id' => $id, 'type' => $type])) ?>" id="ailab-source-form">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="action" id="ailab-action" value="">
    <?php $tabs->Begin(); $tabs->BeginNextTab(); ?>

    <tr class="adm-detail-required-field">
        <td width="30%">Заголовок для модели:</td>
        <td width="70%">
            <input type="text" name="TITLE" size="50" maxlength="255" value="<?= $val('TITLE') ?>" placeholder="Статьи расходов">
            <div class="ailab-hint">Под этим заголовком данные будут в промте, на него же ссылаются поля ответа.</div>
        </td>
    </tr>
    <tr>
        <td class="adm-detail-valign-top">Пояснение для модели:</td>
        <td>
            <textarea name="DESCRIPTION" rows="3" class="ailab-wide" placeholder="Справочник статей бюджета. Выбирать можно только из этих строк."><?= $val('DESCRIPTION') ?></textarea>
        </td>
    </tr>

    <?php if ($type === 'entity'): ?>
        <tr class="heading"><td colspan="2">Откуда и что брать</td></tr>
        <tr class="adm-detail-required-field">
            <td>Сущность:</td>
            <td>
                <?php
                $grouped = [];
                foreach (EntityCatalog::entities() as $key => $info) {
                    $grouped[$info['group']][$key] = $info['title'];
                }
                ?>
                <select name="ENTITY" onchange="document.getElementById('ailab-action').value='reload'; this.form.submit();">
                    <option value="">— выберите —</option>
                    <?= View::options($grouped, $entityKey, true) ?>
                </select>
                <?php if ($fieldsError !== ''): ?>
                    <div class="ailab-note"><?= View::e($fieldsError) ?></div>
                <?php endif; ?>
            </td>
        </tr>
        <?php if ($fields): ?>
            <tr>
                <td class="adm-detail-valign-top">Поля:</td>
                <td>
                    <div class="ailab-hint">ID элемента добавляется всегда. Отмечайте только то, что помогает модели решить задачу — лишние поля стоят денег и сбивают с толку.</div>
                    <div class="ailab-fields">
                        <?php $chosen = array_flip(array_map('strval', (array)$v['FIELDS'])); ?>
                        <?php foreach ($fields as $code => $meta): if ($code === 'ID') { continue; } ?>
                            <label>
                                <input type="checkbox" name="FIELDS[]" value="<?= View::e($code) ?>"<?= isset($chosen[$code]) ? ' checked' : '' ?>>
                                <?= View::e($meta->title) ?>
                                <span class="ailab-type"><?= View::e($meta->label() . ($meta->multiple ? ', несколько' : '') . ' · ' . $code) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="adm-detail-valign-top">Фильтр:</td>
                <td>
                    <?php
                    $fieldOptions = ['' => '—'];
                    foreach ($fields as $code => $meta) {
                        $fieldOptions[$code] = $meta->title . ' (' . $code . ')';
                    }
                    $filterRows = array_values((array)$v['FILTER']);
                    for ($i = 0; $i < SourceService::BLANK_FILTER_ROWS; $i++) {
                        $filterRows[] = ['field' => '', 'op' => '=', 'value' => ''];
                    }
                    ?>
                    <table class="ailab-grid">
                        <tr><th>Поле</th><th style="width:190px">Условие</th><th>Значение</th></tr>
                        <?php foreach ($filterRows as $n => $f): ?>
                            <tr>
                                <td><select name="FILTER[<?= $n ?>][field]"><?= View::options($fieldOptions, (string)($f['field'] ?? '')) ?></select></td>
                                <td><select name="FILTER[<?= $n ?>][op]"><?= View::options(FilterBuilder::OPS, (string)($f['op'] ?? '=')) ?></select></td>
                                <td><input type="text" name="FILTER[<?= $n ?>][value]" value="<?= View::e($f['value'] ?? '') ?>" placeholder="значение или :параметр"></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    <div class="ailab-hint">Все условия выполняются одновременно. Значение «:org_id» — параметр: в бизнес-процессе его заполнит кубик.
                        Для списков и привязок указывайте ID, для «одно из» — значения через запятую. Пустые строки не учитываются.</div>
                </td>
            </tr>
            <tr>
                <td>Сортировка:</td>
                <td>
                    <select name="ORDER_FIELD"><?= View::options(array_slice($fieldOptions, 1, null, true), (string)$v['ORDER_FIELD']) ?></select>
                    <select name="ORDER_DIR"><?= View::options(['ASC' => 'по возрастанию', 'DESC' => 'по убыванию'], (string)$v['ORDER_DIR']) ?></select>
                </td>
            </tr>
            <tr>
                <td>Строк, не больше:</td>
                <td>
                    <input type="number" name="LIMIT" min="1" max="<?= EntityReader::MAX_LIMIT ?>" value="<?= $val('LIMIT') ?>">
                    <div class="ailab-hint">Если элементов окажется больше, запрос не уйдёт: модель не должна выбирать из обрезанного списка.</div>
                </td>
            </tr>
        <?php endif; ?>

    <?php elseif ($type === 'sql'): ?>
        <tr class="heading"><td colspan="2">Запрос</td></tr>
        <?php if (!SqlSource::isConfigured()): ?>
            <tr><td colspan="2"><div class="ailab-note">Подключение «<?= SqlSource::CONNECTION_NAME ?>» не настроено: сохранить источник можно, но выполняться он не будет. Как настроить — в README модуля, раздел «SQL-источник».</div></td></tr>
        <?php endif; ?>
        <tr class="adm-detail-required-field">
            <td class="adm-detail-valign-top">SQL:</td>
            <td>
                <textarea name="SQL" rows="10" class="ailab-wide ailab-mono" placeholder="SELECT ID, TITLE FROM b_crm_dynamic_items_150 WHERE ORGANIZATION_ID = :org_id"><?= $val('SQL') ?></textarea>
                <div class="ailab-hint">Один SELECT или WITH, без комментариев и «;». Параметры — :имя, подставляются экранированными. Выполняется не дольше 5 секунд.</div>
            </td>
        </tr>
        <tr>
            <td>Колонка с ID:</td>
            <td>
                <input type="text" name="ID_COLUMN" size="20" value="<?= $val('ID_COLUMN') ?>" class="ailab-mono">
                <div class="ailab-hint">По ней проверяются ID в ответе модели.</div>
            </td>
        </tr>
        <tr>
            <td>Строк, не больше:</td>
            <td><input type="number" name="LIMIT" min="1" max="<?= SqlSource::MAX_LIMIT ?>" value="<?= $val('LIMIT') ?>"></td>
        </tr>

    <?php else: ?>
        <tr class="heading"><td colspan="2">Текст</td></tr>
        <tr class="adm-detail-required-field">
            <td class="adm-detail-valign-top">Содержимое:</td>
            <td>
                <textarea name="CONTENT" rows="14" class="ailab-wide"><?= $val('CONTENT') ?></textarea>
                <div class="ailab-hint">Правила, памятка, небольшой справочник в любом виде, хоть JSON. Уходит в промт как есть.</div>
            </td>
        </tr>
    <?php endif; ?>

    <tr class="heading"><td colspan="2">Порядок</td></tr>
    <tr>
        <td>Сортировка в промте:</td>
        <td><input type="number" name="SORT" value="<?= $val('SORT') ?>"></td>
    </tr>
    <tr>
        <td>Активен:</td>
        <td><input type="checkbox" name="ACTIVE" value="Y"<?= ($v['ACTIVE'] ?? '') === 'Y' ? ' checked' : '' ?>></td>
    </tr>

    <?php if ($paramNames): ?>
        <tr class="heading"><td colspan="2">Значения параметров для проверки</td></tr>
        <?php foreach ($paramNames as $name): ?>
            <tr>
                <td>:<?= View::e($name) ?></td>
                <td><input type="text" name="PREVIEW_PARAMS[<?= View::e($name) ?>]" size="30" value="<?= View::e($previewParams[$name] ?? '') ?>"></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php $tabs->Buttons(); ?>
    <button type="submit" name="action" value="save" class="adm-btn-save">Сохранить</button>
    <button type="submit" name="action" value="apply">Применить</button>
    <button type="submit" name="action" value="preview">Проверить источник</button>
    <input type="button" value="Отмена" onclick="window.location.href='<?= View::e($backUrl) ?>'">
    <?php $tabs->End(); ?>
</form>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
