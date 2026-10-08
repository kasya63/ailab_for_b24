<?php
/** AI Lab: сценарий — инструкция, формат ответа, источники. */

use Bitrix\Main\Loader;
use Local\AiLab\Admin\ScenarioService;
use Local\AiLab\Admin\View;
use Local\AiLab\Data\EntityCatalog;
use Local\AiLab\Installer;
use Local\AiLab\Model\ConnectionTable;
use Local\AiLab\Model\ScenarioTable;
use Local\AiLab\Model\SourceTable;
use Local\AiLab\Scenario\OutputSchema;
use Local\AiLab\Scenario\SourceDef;
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

const AILAB_TABS = 'ailab_scenario';
$page = $APPLICATION->GetCurPage();
$id = max(0, (int)($_GET['id'] ?? 0));
$errors = [];
$posted = null;
$notice = '';
if (isset($_GET['saved'])) {
    $notice = 'Сценарий сохранён.';
} elseif (isset($_GET['source_saved'])) {
    $notice = 'Источник сохранён.';
} elseif (isset($_GET['source_deleted'])) {
    $notice = 'Источник удалён.';
}

if ($fatal === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_bitrix_sessid()) {
        $errors[] = 'Сессия устарела. Обновите страницу и повторите действие.';
    } else {
        try {
            [$savedId, $errors] = ScenarioService::save($_POST);
            if (!$errors) {
                if (($_POST['action'] ?? '') === 'apply') {
                    LocalRedirect(View::url($page, [
                        'id'                       => $savedId,
                        'saved'                    => 1,
                        AILAB_TABS . '_active_tab' => (string)($_POST[AILAB_TABS . '_active_tab'] ?? ''),
                    ]));
                }
                LocalRedirect(View::url('ailab_scenarios.php', ['saved' => 1]));
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
        $posted = $_POST;
        $id = max(0, (int)($_POST['ID'] ?? 0));
    }
}

$row = null;
if ($fatal === '' && $id > 0) {
    $row = ScenarioTable::getById($id)->fetch() ?: null;
    if ($row === null) {
        $fatal = "Сценарий #{$id} не найден.";
    }
}

$APPLICATION->SetTitle($id > 0 && $row ? 'AI Lab: ' . $row['NAME'] : 'AI Lab: новый сценарий');
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
View::errors($errors);

if ($posted !== null) {
    $v = $posted + array_fill_keys(array_keys(ScenarioService::defaults()), '');
    $v['OF'] = array_values((array)($posted['OF'] ?? []));
} elseif ($row !== null) {
    $v = ScenarioService::rowToForm($row);
} else {
    $v = ScenarioService::defaults();
}
$val = static fn(string $k): string => View::e($v[$k] ?? '');
$checked = static fn(string $k): string => ($v[$k] ?? '') === 'Y' ? ' checked' : '';

$connections = ['' => '— выберите —'];
foreach (ConnectionTable::getList(['select' => ['ID', 'NAME', 'MODEL', 'ACTIVE'], 'order' => ['ID' => 'ASC']])->fetchAll() as $c) {
    $connections[(string)$c['ID']] = $c['NAME'] . ' — ' . $c['MODEL'] . ($c['ACTIVE'] === 'Y' ? '' : ' (выключено)');
}
$refSources = ScenarioService::refSourceOptions($id);
$sourceRows = $id > 0
    ? SourceTable::getList(['filter' => ['=SCENARIO_ID' => $id], 'order' => ['SORT' => 'ASC', 'ID' => 'ASC']])->fetchAll()
    : [];

$menu = [['TEXT' => 'К списку сценариев', 'LINK' => View::url('ailab_scenarios.php'), 'ICON' => 'btn_list']];
if ($id > 0) {
    $menu[] = ['TEXT' => 'Тестовый прогон', 'LINK' => View::url('ailab_scenario_test.php', ['id' => $id])];
}
(new CAdminContextMenu($menu))->Show();

$tabs = new CAdminTabControl(AILAB_TABS, [
    ['DIV' => 'ailab_main', 'TAB' => 'Сценарий', 'TITLE' => 'Что модель должна сделать'],
    ['DIV' => 'ailab_output', 'TAB' => 'Формат ответа', 'TITLE' => 'Поля, которые получит бизнес-процесс'],
    ['DIV' => 'ailab_sources', 'TAB' => 'Источники', 'TITLE' => 'Данные, которые модель увидит в каждом запросе'],
]);
?>
<form method="post" action="<?= View::e(View::url($page, ['id' => $id])) ?>">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="ID" value="<?= $id ?>">
    <?php $tabs->Begin(); ?>

    <?php /* ===================== Сценарий ===================== */ $tabs->BeginNextTab(); ?>
    <tr class="adm-detail-required-field">
        <td width="30%">Название:</td>
        <td width="70%"><input type="text" name="NAME" size="60" maxlength="255" value="<?= $val('NAME') ?>"></td>
    </tr>
    <tr class="adm-detail-required-field">
        <td>Код:</td>
        <td>
            <input type="text" name="CODE" size="30" maxlength="64" value="<?= $val('CODE') ?>" class="ailab-mono">
            <div class="ailab-hint">По коду кубик бизнес-процесса находит сценарий. Латиница и «_», например expense_check. После настройки кубиков лучше не менять.</div>
        </td>
    </tr>
    <tr class="adm-detail-required-field">
        <td>Подключение к ИИ:</td>
        <td>
            <select name="CONNECTION_ID"><?= View::options($connections, (string)($v['CONNECTION_ID'] ?? '')) ?></select>
            <?php if (count($connections) === 1): ?>
                <div class="ailab-hint">Подключений пока нет — <a href="<?= View::e(View::url('ailab_connections.php', ['edit' => 0])) ?>">добавьте подключение</a>.</div>
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <td>Модель:</td>
        <td>
            <input type="text" name="MODEL" size="40" maxlength="255" value="<?= $val('MODEL') ?>">
            <div class="ailab-hint">Пусто — модель из подключения. Укажите, если этому сценарию нужна другая модель того же провайдера.</div>
        </td>
    </tr>
    <tr class="adm-detail-required-field">
        <td class="adm-detail-valign-top">Инструкция:</td>
        <td>
            <textarea name="INSTRUCTION" rows="14" class="ailab-wide" placeholder="Ты — финансовый контролёр. Тебе дают заявку на оплату и статью расходов, которую предложил скрипт по истории пользователя. Проверь, подходит ли статья по смыслу заявки. Если нет — выбери из справочника ближайшую. История и частота — подсказка, а не истина."><?= $val('INSTRUCTION') ?></textarea>
            <div class="ailab-hint">Роль, задача и правила. Данные источников и описание формата ответа модуль добавит после инструкции сам.</div>
        </td>
    </tr>
    <tr>
        <td>Лимит токенов ответа:</td>
        <td>
            <input type="number" name="MAX_TOKENS" min="0" max="64000" value="<?= $val('MAX_TOKENS') ?>">
            <div class="ailab-hint">0 — как в подключении.</div>
        </td>
    </tr>
    <tr>
        <td>Запросов в сутки, не больше:</td>
        <td>
            <input type="number" name="DAILY_LIMIT" min="0" max="100000" value="<?= $val('DAILY_LIMIT') ?>">
            <div class="ailab-hint">Защита от зациклившегося бизнес-процесса. 0 — без ограничения. Тестовые прогоны не считаются.</div>
        </td>
    </tr>
    <tr>
        <td>Размер промта, символов, не больше:</td>
        <td>
            <input type="number" name="MAX_PROMPT_CHARS" min="1000" max="2000000" value="<?= $val('MAX_PROMPT_CHARS') ?>">
            <div class="ailab-hint">Если источники разрастутся сверх лимита, запрос не уйдёт, а кубик вернёт ошибку.</div>
        </td>
    </tr>
    <tr>
        <td>Активен:</td>
        <td><input type="checkbox" name="ACTIVE" value="Y"<?= $checked('ACTIVE') ?>></td>
    </tr>

    <?php /* ===================== Формат ответа ===================== */ $tabs->BeginNextTab(); ?>
    <tr>
        <td colspan="2">
            <p class="ailab-hint">Каждое поле станет результатом кубика в дизайнере бизнес-процессов. Служебные результаты
                status, error_text и log_id кубик добавит сам. Модель обязана вернуть все поля; ответ с ID,
                которых не было в данных, не будет принят.</p>
            <table class="ailab-grid" id="ailab-output">
                <tr>
                    <th style="width:50px">Порядок</th>
                    <th style="width:150px">Код</th>
                    <th style="width:170px">Тип</th>
                    <th>Описание для модели</th>
                    <th style="width:50px">Обяз.</th>
                    <th style="width:240px">Настройки типа</th>
                    <th style="width:60px">Удалить</th>
                </tr>
                <?php
                $ofRows = array_values((array)$v['OF']);
                $existing = count($ofRows);
                for ($i = 0; $i < ScenarioService::BLANK_FIELD_ROWS; $i++) {
                    $ofRows[] = ['sort' => (string)(($existing + $i + 1) * 10), 'type' => 'string', 'required' => 'Y', 'blank' => true];
                }
                foreach ($ofRows as $n => $r):
                    $p = 'OF[' . $n . ']';
                    $type = (string)($r['type'] ?? 'string');
                    ?>
                    <tr class="ailab-of-row">
                        <td><input type="text" name="<?= $p ?>[sort]" value="<?= View::e($r['sort'] ?? '') ?>" size="3"></td>
                        <td><input type="text" name="<?= $p ?>[code]" value="<?= View::e($r['code'] ?? '') ?>" class="ailab-mono" placeholder="article_id"></td>
                        <td><select name="<?= $p ?>[type]" class="ailab-of-type"><?= View::options(OutputSchema::TYPES, $type) ?></select></td>
                        <td><textarea name="<?= $p ?>[description]" rows="2"><?= View::e($r['description'] ?? '') ?></textarea></td>
                        <td><input type="checkbox" name="<?= $p ?>[required]" value="Y"<?= ($r['required'] ?? '') === 'Y' ? ' checked' : '' ?>></td>
                        <td>
                            <div class="ailab-of-extra" data-types="enum">
                                <textarea name="<?= $p ?>[enum]" rows="3" placeholder="варианты, по одному в строке"><?= View::e($r['enum'] ?? '') ?></textarea>
                            </div>
                            <div class="ailab-of-extra" data-types="ref ref_list">
                                <select name="<?= $p ?>[source_id]"><?= View::options(['' => '— источник ID —'] + array_map('strval', $refSources), (string)($r['source_id'] ?? '')) ?></select>
                            </div>
                            <div class="ailab-of-extra" data-types="ref">
                                <label><input type="checkbox" name="<?= $p ?>[allow_zero]" value="Y"<?= ($r['allow_zero'] ?? '') === 'Y' ? ' checked' : '' ?>> 0 — «ничего не подошло»</label>
                            </div>
                            <div class="ailab-of-extra" data-types="ref_list">
                                не больше <input type="text" name="<?= $p ?>[max_items]" value="<?= View::e($r['max_items'] ?? '') ?>" size="3"> шт.
                            </div>
                        </td>
                        <td><?php if (empty($r['blank'])): ?><input type="checkbox" name="<?= $p ?>[delete]" value="Y"><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <p class="ailab-hint">Нужно больше строк — сохраните кнопкой «Применить», появятся новые пустые.</p>
        </td>
    </tr>

    <?php /* ===================== Источники ===================== */ $tabs->BeginNextTab(); ?>
    <tr>
        <td colspan="2">
            <?php if ($id === 0): ?>
                <p>Сохраните сценарий кнопкой «Применить» — после этого здесь можно будет добавить источники.</p>
            <?php else: ?>
                <p class="ailab-hint">Данные источников идут в промт сразу после инструкции, в этом порядке. На источники
                    с ID (элементы и SQL) могут ссылаться поля ответа типа «ID из источника».</p>
                <?php if ($sourceRows): ?>
                    <table class="ailab-grid">
                        <tr><th style="width:60px">Порядок</th><th>Заголовок для модели</th><th>Тип</th><th>Что берёт</th><th style="width:70px">Активен</th><th style="width:150px"></th></tr>
                        <?php foreach ($sourceRows as $s):
                            $def = SourceDef::fromRow($s);
                            $what = match ($def->type) {
                                'entity' => (EntityCatalog::exists((string)($def->config['entity'] ?? '')) ? EntityCatalog::title($def->config['entity']) : 'сущность не найдена')
                                    . ', полей: ' . count((array)($def->config['fields'] ?? []))
                                    . (!empty($def->config['filter']) ? ', с фильтром' : '')
                                    . ', лимит ' . (int)($def->config['limit'] ?? 500),
                                'sql'    => mb_strimwidth(preg_replace('/\s+/u', ' ', (string)($def->config['sql'] ?? '')), 0, 90, '…'),
                                'static' => View::number(mb_strlen((string)($def->config['content'] ?? ''))) . ' символов текста',
                                default  => '',
                            };
                            $sourceEdit = View::url('ailab_source_edit.php', ['scenario' => $id, 'id' => $def->id]);
                            $sourceDelete = View::url('ailab_source_edit.php', ['scenario' => $id, 'id' => $def->id, 'action' => 'delete', 'sessid' => bitrix_sessid()]);
                            ?>
                            <tr>
                                <td><?= $def->sort ?></td>
                                <td><a href="<?= View::e($sourceEdit) ?>"><?= View::e($def->title) ?></a></td>
                                <td><?= View::e(SourceDef::TYPES[$def->type] ?? $def->type) ?></td>
                                <td><?= View::e($what) ?></td>
                                <td><?= $def->active ? 'да' : 'нет' ?></td>
                                <td class="ailab-actions">
                                    <a href="<?= View::e($sourceEdit) ?>">Изменить</a>
                                    &nbsp; <a href="<?= View::e($sourceDelete) ?>" onclick="return confirm('Удалить источник?');">Удалить</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <p>Источников пока нет.</p>
                <?php endif; ?>
                <p>
                    Добавить:
                    <a href="<?= View::e(View::url('ailab_source_edit.php', ['scenario' => $id, 'type' => 'entity'])) ?>">элементы CRM, смарт-процесса, списка или HL-блока</a>
                    &nbsp;·&nbsp; <a href="<?= View::e(View::url('ailab_source_edit.php', ['scenario' => $id, 'type' => 'sql'])) ?>">SQL-запрос</a>
                    &nbsp;·&nbsp; <a href="<?= View::e(View::url('ailab_source_edit.php', ['scenario' => $id, 'type' => 'static'])) ?>">текст</a>
                </p>
                <?php if (!SqlSource::isConfigured()): ?>
                    <p class="ailab-hint">SQL-источник пока не заработает: не настроено подключение к базе только на чтение «<?= SqlSource::CONNECTION_NAME ?>». Как настроить — в README модуля.</p>
                <?php endif; ?>
            <?php endif; ?>
        </td>
    </tr>

    <?php $tabs->Buttons(); ?>
    <button type="submit" name="action" value="save" class="adm-btn-save">Сохранить</button>
    <button type="submit" name="action" value="apply">Применить</button>
    <input type="button" value="Отмена" onclick="window.location.href='<?= View::e(View::url('ailab_scenarios.php')) ?>'">
    <?php $tabs->End(); ?>
</form>

<script>
    (function () {
        function sync(row) {
            var type = row.querySelector('.ailab-of-type').value;
            row.querySelectorAll('.ailab-of-extra').forEach(function (el) {
                el.style.display = el.getAttribute('data-types').split(' ').indexOf(type) !== -1 ? '' : 'none';
            });
        }
        document.querySelectorAll('#ailab-output .ailab-of-row').forEach(function (row) {
            row.querySelector('.ailab-of-type').addEventListener('change', function () { sync(row); });
            sync(row);
        });
    })();
</script>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
