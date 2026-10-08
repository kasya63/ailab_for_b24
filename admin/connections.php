<?php
/**
 * AI Lab: подключения к провайдерам ИИ.
 * Адрес: /bitrix/admin/ailab_connections.php (заглушка копируется при установке модуля).
 */

use Bitrix\Main\Loader;
use Local\AiLab\Admin\ConnectionService;
use Local\AiLab\Admin\View;
use Local\AiLab\Ai\AdapterFactory;
use Local\AiLab\Ai\Connection;
use Local\AiLab\Ai\ConnectionTester;
use Local\AiLab\Installer;
use Local\AiLab\Json;
use Local\AiLab\Model\ConnectionTable;
use Local\AiLab\Secret;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

/** @global CMain $APPLICATION */
/** @global CUser $USER */
global $APPLICATION, $USER;

if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Раздел AI Lab доступен только администраторам портала.');
}

$fatal = '';
if (!Loader::includeModule('local.ailab')) {
    $fatal = 'Модуль local.ailab не установлен. Установите его: Marketplace → Установленные решения → AI Lab.';
} else {
    try {
        Installer::ensureSchema();
    } catch (\Throwable $e) {
        $fatal = 'Не удалось подготовить таблицы модуля: ' . $e->getMessage();
    }
}

$page = $APPLICATION->GetCurPage();
$listUrl = $page . '?lang=' . LANGUAGE_ID;
$editUrl = static fn(int $id): string => $page . '?lang=' . LANGUAGE_ID . '&edit=' . $id;

$errors = [];
$notice = '';
$test = null;
$testName = '';
$editId = isset($_GET['edit']) ? max(0, (int)$_GET['edit']) : null; // null — список, 0 — новое
$postedForm = null;

if ($fatal === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_bitrix_sessid()) {
        $errors[] = 'Сессия устарела. Обновите страницу и повторите действие.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            switch ($action) {
                case 'save':
                    [$savedId, $errors] = ConnectionService::save($_POST);
                    if ($errors) {
                        $editId = max(0, (int)($_POST['ID'] ?? 0));
                        $postedForm = $_POST;
                    } elseif (!empty($_POST['and_test'])) {
                        $editId = $savedId;
                        $notice = 'Подключение сохранено.';
                        $connection = Connection::load($savedId);
                        $testName = $connection->name;
                        $test = ConnectionTester::run($connection);
                    } else {
                        LocalRedirect($listUrl . '&saved=' . $savedId);
                    }
                    break;

                case 'test':
                    $connection = Connection::load((int)($_POST['ID'] ?? 0));
                    $testName = $connection->name;
                    $test = ConnectionTester::run($connection);
                    break;

                case 'delete':
                    ConnectionService::delete((int)($_POST['ID'] ?? 0));
                    LocalRedirect($listUrl . '&deleted=1');
                    break;
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

if (isset($_GET['saved'])) {
    $notice = 'Подключение сохранено.';
}
if (isset($_GET['deleted'])) {
    $notice = 'Подключение удалено.';
}

if ($editId === null) {
    $APPLICATION->SetTitle('AI Lab: подключения');
} else {
    $APPLICATION->SetTitle($editId > 0 ? 'AI Lab: подключение #' . $editId : 'AI Lab: новое подключение');
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if ($fatal !== '') {
    CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => View::e($fatal), 'HTML' => true]);
} else {
    echo View::styles();

    if ($notice !== '') {
        CAdminMessage::ShowMessage(['TYPE' => 'OK', 'MESSAGE' => View::e($notice), 'HTML' => true]);
    }
    if ($errors) {
        CAdminMessage::ShowMessage([
            'TYPE'    => 'ERROR',
            'MESSAGE' => 'Не сохранено',
            'DETAILS' => implode('<br>', array_map([View::class, 'e'], $errors)),
            'HTML'    => true,
        ]);
    }
    if ($test !== null) {
        CAdminMessage::ShowMessage([
            'TYPE'    => $test->isSuccess() ? 'OK' : 'ERROR',
            'MESSAGE' => View::e(($test->isSuccess() ? 'Подключение работает: ' : 'Проверка не прошла: ') . $testName),
            'DETAILS' => View::responseDetails($test),
            'HTML'    => true,
        ]);
    }

    if ($editId === null) {
        /* ===================== Список ===================== */
        (new CAdminContextMenu([
            ['TEXT' => 'Добавить подключение', 'LINK' => $editUrl(0), 'ICON' => 'btn_new', 'TITLE' => 'Новый провайдер, модель или ключ'],
        ]))->Show();

        $rows = ConnectionTable::getList(['order' => ['ID' => 'ASC']])->fetchAll();
        $titles = AdapterFactory::titles();

        if (!$rows) {
            echo '<div class="ailab-empty">Подключений пока нет. Добавьте первое: провайдер, модель и API-ключ.</div>';
        } else {
            ?>
            <table class="adm-list-table">
                <thead>
                <tr class="adm-list-table-header">
                    <?php foreach (['ID', 'Название', 'Провайдер', 'Модель', 'API-ключ', 'Таймаут', 'Активно', ''] as $head): ?>
                        <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner"><?= View::e($head) ?></div></td>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row):
                    $id = (int)$row['ID'];
                    try {
                        $key = Secret::decrypt((string)$row['API_KEY_ENC']);
                        $keyText = $key === '' ? 'не задан' : Secret::mask($key);
                    } catch (\Throwable $e) {
                        $keyText = 'не расшифровывается, введите заново';
                    }
                    ?>
                    <tr class="adm-list-table-row">
                        <td class="adm-list-table-cell"><?= $id ?></td>
                        <td class="adm-list-table-cell"><a href="<?= View::e($editUrl($id)) ?>"><?= View::e($row['NAME']) ?></a></td>
                        <td class="adm-list-table-cell"><?= View::e($titles[$row['ADAPTER']] ?? $row['ADAPTER']) ?></td>
                        <td class="adm-list-table-cell"><?= View::e($row['MODEL']) ?></td>
                        <td class="adm-list-table-cell"><?= View::e($keyText) ?></td>
                        <td class="adm-list-table-cell"><?= (int)$row['TIMEOUT'] ?> с</td>
                        <td class="adm-list-table-cell"><?= $row['ACTIVE'] === 'Y' ? 'да' : 'нет' ?></td>
                        <td class="adm-list-table-cell ailab-actions">
                            <a href="<?= View::e($editUrl($id)) ?>">Изменить</a>
                            <form method="post" action="<?= View::e($listUrl) ?>">
                                <?= bitrix_sessid_post() ?>
                                <input type="hidden" name="action" value="test">
                                <input type="hidden" name="ID" value="<?= $id ?>">
                                <input type="submit" value="Проверить">
                            </form>
                            <form method="post" action="<?= View::e($listUrl) ?>"
                                  onsubmit="return confirm(<?= View::e(Json::encode('Удалить подключение «' . $row['NAME'] . '»?')) ?>);">
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

        $secret = Secret::status();
        echo '<p class="ailab-hint">' . View::e($secret['text']) . '</p>';
    } else {
        /* ===================== Форма ===================== */
        $row = $editId > 0 ? (ConnectionTable::getById($editId)->fetch() ?: null) : null;

        if ($editId > 0 && $row === null) {
            CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => View::e("Подключение #{$editId} не найдено."), 'HTML' => true]);
        } else {
            if ($postedForm !== null) {
                $v = $postedForm + array_fill_keys(array_keys(ConnectionService::defaults()), '');
            } elseif ($row !== null) {
                $v = ConnectionService::rowToForm($row);
            } else {
                $v = ConnectionService::defaults();
            }

            $keyMask = '';
            if ($row !== null) {
                try {
                    $key = Secret::decrypt((string)$row['API_KEY_ENC']);
                    $keyMask = $key === '' ? '' : Secret::mask($key);
                } catch (\Throwable $e) {
                    $keyMask = 'сохранённый ключ не расшифровывается, введите его заново';
                }
            }

            $adapterDefaults = [];
            foreach (array_keys(AdapterFactory::titles()) as $code) {
                $adapterDefaults[$code] = AdapterFactory::defaultBaseUrl($code);
            }

            $val = static fn(string $k): string => View::e($v[$k] ?? '');
            $checked = static fn(string $k): string => ($v[$k] ?? '') === 'Y' ? ' checked' : '';
            $selected = static fn(string $k, string $option): string => (string)($v[$k] ?? '') === $option ? ' selected' : '';

            (new CAdminContextMenu([
                ['TEXT' => 'К списку подключений', 'LINK' => $listUrl, 'ICON' => 'btn_list'],
            ]))->Show();

            $tabs = new CAdminTabControl('ailab_connection_edit', [
                ['DIV' => 'ailab_main', 'TAB' => 'Подключение', 'TITLE' => 'Провайдер, модель и ключ'],
                ['DIV' => 'ailab_extra', 'TAB' => 'Дополнительно', 'TITLE' => 'Таймаут, лимиты и формат ответа'],
            ]);
            ?>
            <form method="post" action="<?= View::e($listUrl) ?>" autocomplete="off">
                <?= bitrix_sessid_post() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="ID" value="<?= (int)$editId ?>">
                <?php $tabs->Begin(); ?>
                <?php $tabs->BeginNextTab(); ?>
                <tr class="adm-detail-required-field">
                    <td width="40%">Название:</td>
                    <td width="60%">
                        <input type="text" name="NAME" size="50" maxlength="255" value="<?= $val('NAME') ?>">
                        <div class="ailab-hint">Для себя, например «Claude Haiku — статьи расходов».</div>
                    </td>
                </tr>
                <tr class="adm-detail-required-field">
                    <td>Провайдер:</td>
                    <td>
                        <select name="ADAPTER" id="ailab-adapter">
                            <?php foreach (AdapterFactory::titles() as $code => $title): ?>
                                <option value="<?= View::e($code) ?>"<?= $selected('ADAPTER', $code) ?>><?= View::e($title) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td>Адрес API:</td>
                    <td>
                        <input type="text" name="BASE_URL" id="ailab-base-url" size="50" maxlength="500" value="<?= $val('BASE_URL') ?>">
                        <div class="ailab-hint">Оставьте пустым, чтобы использовать адрес провайдера. Для OpenRouter: https://openrouter.ai/api/v1</div>
                    </td>
                </tr>
                <tr class="adm-detail-required-field">
                    <td>Модель:</td>
                    <td>
                        <input type="text" name="MODEL" size="50" maxlength="255" value="<?= $val('MODEL') ?>">
                        <div class="ailab-hint">Идентификатор из кабинета провайдера, например claude-haiku-4-5-20251001.</div>
                    </td>
                </tr>
                <tr>
                    <td>API-ключ:</td>
                    <td>
                        <input type="password" name="API_KEY" size="50" value="" autocomplete="new-password"
                               placeholder="<?= $keyMask !== '' ? 'оставьте пустым, чтобы не менять' : 'вставьте ключ' ?>">
                        <?php if ($keyMask !== ''): ?>
                            <div class="ailab-hint">Сохранён: <?= View::e($keyMask) ?></div>
                            <label><input type="checkbox" name="API_KEY_CLEAR" value="Y"> удалить сохранённый ключ</label>
                        <?php endif; ?>
                        <div class="ailab-hint">Хранится в базе в зашифрованном виде.</div>
                    </td>
                </tr>
                <tr>
                    <td>Активно:</td>
                    <td>
                        <input type="checkbox" name="ACTIVE" value="Y"<?= $checked('ACTIVE') ?>>
                        <div class="ailab-hint">Выключенное подключение можно проверить, но сценарии им не пользуются.</div>
                    </td>
                </tr>

                <?php $tabs->BeginNextTab(); ?>
                <tr>
                    <td width="40%">Ждать ответ, секунд:</td>
                    <td width="60%">
                        <input type="number" name="TIMEOUT" min="5" max="600" value="<?= $val('TIMEOUT') ?>">
                        <div class="ailab-hint">Если провайдер не ответил за это время, запрос обрывается и считается неудачной попыткой.</div>
                    </td>
                </tr>
                <tr>
                    <td>Лимит токенов ответа:</td>
                    <td><input type="number" name="MAX_TOKENS" min="16" max="64000" value="<?= $val('MAX_TOKENS') ?>"></td>
                </tr>
                <tr>
                    <td>Передавать temperature:</td>
                    <td>
                        <input type="checkbox" name="P_SEND_TEMPERATURE" value="Y"<?= $checked('P_SEND_TEMPERATURE') ?>>
                        <div class="ailab-hint">Снимите, если модель отвечает ошибкой про temperature (так бывает у reasoning-моделей).</div>
                    </td>
                </tr>
                <tr class="ailab-only-anthropic">
                    <td>Кэшировать начало промта:</td>
                    <td>
                        <input type="checkbox" name="P_PROMPT_CACHE" value="Y"<?= $checked('P_PROMPT_CACHE') ?>>
                        <div class="ailab-hint">Инструкция и справочники одинаковы для всех заявок: из кэша они дешевле и быстрее.</div>
                    </td>
                </tr>
                <tr class="ailab-only-openai">
                    <td>Строгий формат ответа:</td>
                    <td>
                        <select name="P_STRUCTURED_MODE">
                            <option value="json_schema"<?= $selected('P_STRUCTURED_MODE', 'json_schema') ?>>JSON Schema (strict)</option>
                            <option value="json_object"<?= $selected('P_STRUCTURED_MODE', 'json_object') ?>>JSON-объект без схемы</option>
                            <option value="prompt"<?= $selected('P_STRUCTURED_MODE', 'prompt') ?>>Только описание в промте</option>
                        </select>
                        <div class="ailab-hint">JSON Schema поддерживают OpenAI и большинство совместимых. Если провайдер ругается на response_format — выберите вариант проще.</div>
                    </td>
                </tr>
                <tr class="ailab-only-openai">
                    <td>Поле лимита токенов:</td>
                    <td>
                        <select name="P_MAX_TOKENS_FIELD">
                            <option value="max_tokens"<?= $selected('P_MAX_TOKENS_FIELD', 'max_tokens') ?>>max_tokens</option>
                            <option value="max_completion_tokens"<?= $selected('P_MAX_TOKENS_FIELD', 'max_completion_tokens') ?>>max_completion_tokens</option>
                        </select>
                        <div class="ailab-hint">Новым моделям OpenAI нужен max_completion_tokens, OpenRouter и большинству совместимых — max_tokens.</div>
                    </td>
                </tr>
                <tr>
                    <td>Дополнительные параметры запроса:</td>
                    <td>
                        <textarea name="P_EXTRA_BODY" rows="3" cols="50" placeholder='{"reasoning_effort": "low"}'><?= $val('P_EXTRA_BODY') ?></textarea>
                        <div class="ailab-hint">JSON-объект, добавляется в тело каждого запроса. Для GPT-5 nano на задачах выбора из списка подойдёт {"reasoning_effort": "low"}: меньше рассуждений — быстрее и дешевле. Модель, сообщения и формат ответа модуль задаёт сам.</div>
                    </td>
                </tr>
                <tr>
                    <td>Дополнительные заголовки:</td>
                    <td>
                        <textarea name="P_EXTRA_HEADERS" rows="3" cols="50"><?= $val('P_EXTRA_HEADERS') ?></textarea>
                        <div class="ailab-hint">По одному на строку, «Имя: значение». Ключ сюда не пишите: для него есть поле на первой вкладке.</div>
                    </td>
                </tr>

                <?php $tabs->Buttons(); ?>
                <input type="submit" value="Сохранить" class="adm-btn-save">
                <input type="submit" name="and_test" value="Сохранить и проверить">
                <input type="button" value="Отмена" onclick="window.location.href=<?= View::e(Json::encode($listUrl)) ?>">
                <?php $tabs->End(); ?>
            </form>

            <script>
                (function () {
                    var defaults = <?= Json::encode((object)$adapterDefaults) ?>;
                    var adapter = document.getElementById('ailab-adapter');
                    var baseUrl = document.getElementById('ailab-base-url');
                    var known = Object.keys(defaults).map(function (k) { return defaults[k]; });

                    function sync() {
                        var code = adapter.value;
                        baseUrl.placeholder = defaults[code] || '';
                        // Адрес по умолчанию другого провайдера при переключении сбрасываем.
                        if (known.indexOf(baseUrl.value) !== -1 && baseUrl.value !== defaults[code]) {
                            baseUrl.value = '';
                        }
                        document.querySelectorAll('.ailab-only-anthropic').forEach(function (el) {
                            el.style.display = code === 'anthropic' ? '' : 'none';
                        });
                        document.querySelectorAll('.ailab-only-openai').forEach(function (el) {
                            el.style.display = code === 'openai' ? '' : 'none';
                        });
                    }

                    adapter.addEventListener('change', sync);
                    sync();
                })();
            </script>
            <?php
        }
    }
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
