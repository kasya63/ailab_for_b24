<?php
/** AI Lab: тестовый прогон сценария — без бизнес-процесса, на реальном элементе или ручных данных. */

use Bitrix\Main\Loader;
use Local\AiLab\Admin\ScenarioService;
use Local\AiLab\Admin\TestInputBuilder;
use Local\AiLab\Admin\View;
use Local\AiLab\Data\EntityCatalog;
use Local\AiLab\Installer;
use Local\AiLab\Model\ScenarioTable;
use Local\AiLab\Queue\Queue;
use Local\AiLab\Scenario\PromptBuilder;
use Local\AiLab\Scenario\RunResult;
use Local\AiLab\Scenario\Runner;
use Local\AiLab\Scenario\Scenario;

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
$id = max(0, (int)($_GET['id'] ?? 0));
$row = null;
$scenario = null;
if ($fatal === '') {
    $row = ScenarioTable::getById($id)->fetch() ?: null;
    if ($row === null) {
        $fatal = 'Сценарий не найден.';
    } else {
        try {
            $scenario = Scenario::fromRow($row);
        } catch (\Throwable $e) {
            $fatal = 'Сценарий не загрузился: ' . $e->getMessage();
        }
    }
}

$errors = [];
$state = $row ? ScenarioService::testState($row) : [];
$built = null;
$run = null;
$failure = '';

if ($fatal === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_bitrix_sessid()) {
        $errors[] = 'Сессия устарела. Обновите страницу и повторите действие.';
    } else {
        $state = TestInputBuilder::fromPost($_POST);
        ScenarioService::saveTestState($id, $state);
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'preview' || $action === 'send' || $action === 'enqueue') {
            try {
                $input = TestInputBuilder::build($state);
                if ($action === 'enqueue') {
                    $taskId = Queue::enqueue($scenario, $input, ['origin' => Queue::ORIGIN_TEST]);
                    LocalRedirect(View::url('ailab_task.php', ['id' => $taskId, 'queued' => 1]));
                } elseif ($action === 'preview') {
                    $built = PromptBuilder::build($scenario, $input);
                } else {
                    @set_time_limit(300);
                    $run = Runner::run($scenario, $input);
                    $built = $run->built;
                }
            } catch (\Throwable $e) {
                $failure = $e->getMessage();
            }
        }
    }
}

$APPLICATION->SetTitle('AI Lab: тестовый прогон' . ($row ? ' — ' . $row['NAME'] : ''));
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if ($fatal !== '') {
    View::message('ERROR', $fatal);
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

echo View::styles();
View::errors($errors, 'Ошибка');

$sourceTitles = [];
foreach ($scenario->sources as $source) {
    $sourceTitles[$source->id] = $source->title;
}

if ($failure !== '') {
    View::message('ERROR', 'Запрос не собран', View::e($failure));
}
if ($run !== null) {
    $titles = [
        RunResult::STATUS_SUCCESS => 'Ответ получен и прошёл проверку',
        RunResult::STATUS_INVALID => 'Ответ не прошёл проверку даже со второй попытки',
        RunResult::STATUS_ERROR   => 'Ошибка запроса к ИИ',
    ];
    View::message($run->isSuccess() ? 'OK' : 'ERROR', $titles[$run->status] ?? $run->status, View::runResult($run));
}
if ($built !== null) {
    View::message('OK', $run === null ? 'Промт собран — в ИИ не отправлялся' : 'Что ушло в ИИ', View::builtPrompt($built, $sourceTitles));
}

(new CAdminContextMenu([
    ['TEXT' => 'К сценарию', 'LINK' => View::url('ailab_scenario_edit.php', ['id' => $id]), 'ICON' => 'btn_list'],
]))->Show();

$element = (array)($state['element'] ?? []);
$elEntity = (string)($element['entity'] ?? '');
$elFields = [];
$elFieldsError = '';
if ($elEntity !== '') {
    try {
        $elFields = EntityCatalog::fields($elEntity);
    } catch (\Throwable $e) {
        $elFieldsError = $e->getMessage();
    }
}
$elChosen = array_flip(array_map('strval', (array)($element['fields'] ?? [])));
$params = (array)($state['params'] ?? []);
$paramNames = $scenario->params();

$grouped = [];
foreach (EntityCatalog::entities() as $key => $info) {
    $grouped[$info['group']][$key] = $info['title'];
}

$inputs = array_values((array)($state['inputs'] ?? []));
for ($i = 0; $i < TestInputBuilder::BLANK_ROWS; $i++) {
    $inputs[] = ['label' => '', 'description' => '', 'value' => ''];
}

$tabs = new CAdminTabControl('ailab_test', [
    ['DIV' => 'ailab_test_data', 'TAB' => 'Тестовые данные', 'TITLE' => 'То, что в бизнес-процессе передаст кубик'],
]);
?>
<form method="post" action="<?= View::e(View::url($page, ['id' => $id])) ?>">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="action" id="ailab-action" value="">
    <?php $tabs->Begin(); $tabs->BeginNextTab(); ?>

    <?php if (!$scenario->active): ?>
        <tr><td colspan="2"><div class="ailab-note">Сценарий выключен: из бизнес-процессов он вызываться не будет, но тестовый прогон работает.</div></td></tr>
    <?php endif; ?>

    <?php if ($paramNames): ?>
        <tr class="heading"><td colspan="2">Параметры источников</td></tr>
        <?php foreach ($paramNames as $name): ?>
            <tr>
                <td width="30%">:<?= View::e($name) ?></td>
                <td width="70%"><input type="text" name="PARAMS[<?= View::e($name) ?>]" size="30" value="<?= View::e($params[$name] ?? '') ?>"></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>

    <tr class="heading"><td colspan="2">Поля реального элемента</td></tr>
    <tr>
        <td width="30%">Сущность и ID:</td>
        <td width="70%">
            <select name="EL_ENTITY" onchange="document.getElementById('ailab-action').value='reload'; this.form.submit();">
                <option value="">— не брать —</option>
                <?= View::options($grouped, $elEntity, true) ?>
            </select>
            ID <input type="text" name="EL_ID" size="8" value="<?= (int)($element['id'] ?? 0) ?: '' ?>">
            <div class="ailab-hint">Например, заявка из СП «Заявки на оплату». Значения возьмутся в том же виде, в каком их передаст кубик: ФИО вместо ID пользователя, названия вместо кодов.</div>
            <?php if ($elFieldsError !== ''): ?>
                <div class="ailab-note"><?= View::e($elFieldsError) ?></div>
            <?php endif; ?>
        </td>
    </tr>
    <?php if ($elFields): ?>
        <tr>
            <td class="adm-detail-valign-top">Какие поля передать:</td>
            <td>
                <div class="ailab-fields">
                    <?php foreach ($elFields as $code => $meta): if ($code === 'ID') { continue; } ?>
                        <label>
                            <input type="checkbox" name="EL_FIELDS[]" value="<?= View::e($code) ?>"<?= isset($elChosen[$code]) ? ' checked' : '' ?>>
                            <?= View::e($meta->title) ?>
                            <span class="ailab-type"><?= View::e($meta->label() . ' · ' . $code) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </td>
        </tr>
        <tr>
            <td>Поля-файлы:</td>
            <td><select name="EL_FILE_MODE"><?= View::options(\Local\AiLab\Files\FileExtractor::MODES, (string)($element['file_mode'] ?? 'name')) ?></select></td>
        </tr>
    <?php endif; ?>

    <tr class="heading"><td colspan="2">Дополнительные входные данные</td></tr>
    <tr>
        <td colspan="2">
            <table class="ailab-grid">
                <tr><th style="width:220px">Название</th><th style="width:280px">Пояснение для модели</th><th>Значение</th></tr>
                <?php foreach ($inputs as $n => $in): ?>
                    <tr>
                        <td><input type="text" name="INPUTS[<?= $n ?>][label]" value="<?= View::e($in['label'] ?? '') ?>" placeholder="Предложенная статья (ID)"></td>
                        <td><textarea name="INPUTS[<?= $n ?>][description]" rows="2" placeholder="Что это и как к этому относиться"><?= View::e($in['description'] ?? '') ?></textarea></td>
                        <td><textarea name="INPUTS[<?= $n ?>][value]" rows="2"><?= View::e($in['value'] ?? '') ?></textarea></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <div class="ailab-hint">Сюда — то, чего нет в полях элемента: ID статьи от скрипта, AI_CONTEXT с историей пользователя и т.п. Пустые строки не учитываются.</div>
        </td>
    </tr>

    <tr class="heading"><td colspan="2">Кусок промта из кубика</td></tr>
    <tr>
        <td colspan="2">
            <textarea name="SNIPPET" rows="4" class="ailab-wide" placeholder="Необязательно. Например: «Заявка из филиала в Алматы, учитывай местные статьи»."><?= View::e($state['snippet'] ?? '') ?></textarea>
        </td>
    </tr>

    <?php $tabs->Buttons(); ?>
    <button type="submit" name="action" value="preview">Показать промт</button>
    <button type="submit" name="action" value="send" class="adm-btn-save">Отправить в ИИ</button>
    <button type="submit" name="action" value="enqueue">Поставить в очередь</button>
    <div class="ailab-hint">«Отправить в ИИ» ждёт ответа прямо на странице. «Поставить в очередь» проходит тот же путь, что запрос из бизнес-процесса: задачу заберёт обработчик очереди, результат будет в журнале.</div>
    <?php $tabs->End(); ?>
</form>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
