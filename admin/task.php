<?php
/** AI Lab: карточка задачи — входы, промт, все обращения к провайдеру, результат. */

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Local\AiLab\Admin\View;
use Local\AiLab\Bp\BpBridge;
use Local\AiLab\Bp\DocumentMap;
use Local\AiLab\Installer;
use Local\AiLab\Json;
use Local\AiLab\Model\ConnectionTable;
use Local\AiLab\Model\ScenarioTable;
use Local\AiLab\Queue\PromptStore;
use Local\AiLab\Queue\Queue;
use Local\AiLab\Queue\TaskRepository;
use Local\AiLab\Queue\TaskStatus;
use Local\AiLab\Scenario\OutputSchema;
use Local\AiLab\Scenario\PromptBuilder;

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
$errors = [];
$notice = isset($_GET['cancelled']) ? 'Задача отменена.' : (isset($_GET['queued']) ? 'Задача поставлена в очередь. Страница обновляется сама, пока задача не завершится.' : '');

if ($fatal === '' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    if (!check_bitrix_sessid()) {
        $errors[] = 'Сессия устарела. Обновите страницу и повторите действие.';
    } elseif (Queue::cancel($id)) {
        LocalRedirect(View::url($page, ['id' => $id, 'cancelled' => 1]));
    } else {
        $errors[] = 'Отменить нельзя: задача уже взята в работу или завершена.';
    }
}

$task = $fatal === '' ? TaskRepository::get($id) : null;
if ($fatal === '' && $task === null) {
    $fatal = "Задача №{$id} не найдена.";
}

$APPLICATION->SetTitle('AI Lab: задача №' . $id);
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
View::errors($errors, 'Не получилось');

$status = (string)$task['STATUS'];
$final = TaskStatus::isFinal($status);
if (!$final) {
    View::workerBanner();
}

$menu = [['TEXT' => 'К журналу', 'LINK' => View::url('ailab_tasks.php'), 'ICON' => 'btn_list']];
if ((int)$task['SCENARIO_ID'] > 0) {
    $menu[] = ['TEXT' => 'Сценарий', 'LINK' => View::url('ailab_scenario_edit.php', ['id' => (int)$task['SCENARIO_ID']])];
    $menu[] = ['TEXT' => 'Тестовый прогон', 'LINK' => View::url('ailab_scenario_test.php', ['id' => (int)$task['SCENARIO_ID']])];
}
(new CAdminContextMenu($menu))->Show();

$fmt = static fn($dt): string => $dt instanceof DateTime ? $dt->format('d.m.Y H:i:s') : '—';
$scenario = ScenarioTable::getById((int)$task['SCENARIO_ID'])->fetch();
$connection = ConnectionTable::getById((int)$task['CONNECTION_ID'])->fetch();
$built = json_decode((string)$task['BUILT'], true);
$built = is_array($built) ? $built : null;
$input = json_decode((string)$task['INPUT'], true) ?: [];
$log = json_decode((string)$task['ATTEMPT_LOG'], true) ?: [];
$warnings = json_decode((string)$task['WARNINGS'], true) ?: [];

/* ---------- Итог ---------- */
$summary = [
    'Статус'      => View::taskStatus($status),
    'Сценарий'    => View::e($scenario ? $scenario['NAME'] . ' (' . $scenario['CODE'] . ')' : $task['SCENARIO_CODE'] . ' — удалён'),
    'Откуда'      => View::e(Queue::ORIGINS[$task['ORIGIN']] ?? $task['ORIGIN']),
    'Подключение' => View::e($connection ? $connection['NAME'] : '—') . ($task['MODEL'] !== '' ? ', модель ' . View::e($task['MODEL']) : ''),
    'Создана'     => $fmt($task['CREATED_AT']),
    'Взята в работу' => $fmt($task['STARTED_AT']) . ((int)$task['ATTEMPTS'] > 1 ? ' (попытка ' . (int)$task['ATTEMPTS'] . ')' : ''),
    'Завершена'   => $fmt($task['FINISHED_AT']),
    'Ждать до'    => $fmt($task['DEADLINE_AT']),
    'Обращений к ИИ' => (string)(int)$task['CALLS'],
    'Время ответа'   => number_format((int)$task['DURATION_MS'] / 1000, 1, ',', ' ') . ' с',
    'Токены'      => 'вход ' . View::number((int)$task['INPUT_TOKENS']) . ' (из кэша ' . View::number((int)$task['CACHED_TOKENS']) . '), выход ' . View::number((int)$task['OUTPUT_TOKENS']),
];
if ($task['WORKFLOW_ID'] !== '') {
    $notify = [
        BpBridge::NOTIFY_WAIT   => 'ждёт ответа',
        BpBridge::NOTIFY_DONE   => 'продолжен',
        BpBridge::NOTIFY_RETRY  => 'не удалось продолжить, повтор при следующем запуске обработчика',
        BpBridge::NOTIFY_FAILED => 'не удалось продолжить — процесс разбудит страховочный таймер кубика',
    ][(string)$task['NOTIFY_STATE']] ?? '';
    $summary['Бизнес-процесс'] = '<a href="' . View::e('/bitrix/admin/bizproc_log.php?ID=' . urlencode($task['WORKFLOW_ID']) . '&lang=' . LANGUAGE_ID) . '" target="_blank">журнал процесса</a>'
        . ' · кубик ' . View::e($task['ACTIVITY_NAME'])
        . ($notify !== '' ? ' · ' . View::e($notify) : '')
        . ((string)$task['NOTIFY_ERROR'] !== '' ? '<br><span class="ailab-hint">' . View::e($task['NOTIFY_ERROR']) . '</span>' : '');
}
if ($task['DOCUMENT_ID'] !== '') {
    $documentHtml = View::e($task['DOCUMENT_ID']);
    $crm = DocumentMap::crmFromString((string)$task['DOCUMENT_ID']);
    if ($crm !== null && Loader::includeModule('crm')) {
        try {
            $url = \Bitrix\Crm\Service\Container::getInstance()->getRouter()->getItemDetailUrl($crm[0], $crm[1]);
            if ($url) {
                $documentHtml = '<a href="' . View::e((string)$url) . '" target="_blank">' . $documentHtml . '</a>';
            }
        } catch (\Throwable) {
        }
    }
    $summary['Документ'] = $documentHtml;
}
if ($status === TaskStatus::PENDING && $task['NEXT_RUN_AT'] instanceof DateTime && $task['NEXT_RUN_AT']->getTimestamp() > time()) {
    $summary['Следующая попытка'] = $fmt($task['NEXT_RUN_AT']);
}

echo '<table class="ailab-kv">';
foreach ($summary as $label => $html) {
    echo '<tr><th>' . View::e($label) . '</th><td>' . $html . '</td></tr>';
}
echo '</table>';

if ((string)$task['ERROR_TEXT'] !== '') {
    echo '<p class="ailab-note">' . View::e($task['ERROR_TEXT']) . '</p>';
}
foreach ($warnings as $w) {
    echo '<p class="ailab-note">Поправлено: ' . View::e($w) . '</p>';
}
if ($status === TaskStatus::SUCCESS && (string)$task['RESULT'] !== '') {
    echo '<div class="ailab-label">Результат — эти значения получит бизнес-процесс</div>'
        . '<pre class="ailab-pre">' . View::e(Json::prettyOrRaw((string)$task['RESULT'])) . '</pre>';
}

if ($status === TaskStatus::PENDING) {
    ?>
    <form method="post" action="<?= View::e(View::url($page, ['id' => $id])) ?>" onsubmit="return confirm('Отменить задачу?');">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="action" value="cancel">
        <input type="submit" value="Отменить задачу">
    </form>
    <?php
}

/* ---------- Обращения к провайдеру ---------- */
echo '<h3>Обращения к ИИ</h3>';
if (!$log) {
    echo '<p class="ailab-hint">' . ($final ? 'Запрос к провайдеру не отправлялся.' : 'Ещё не отправлялось.') . '</p>';
} else {
    $kinds = ['send' => 'запрос', 'correction' => 'исправление по ошибкам проверки', 'watchdog' => 'сторож'];
    echo '<table class="ailab-grid"><tr><th>#</th><th>Когда</th><th>Что</th><th>HTTP</th><th>Итог</th><th>Время</th><th>Токены</th><th>Подробности</th></tr>';
    foreach ($log as $entry) {
        $problems = (array)($entry['problems'] ?? []);
        $details = (string)($entry['error'] ?? '');
        if ($problems && implode('; ', $problems) !== $details) {
            $details .= ($details !== '' ? ' — ' : '') . implode('; ', $problems);
        }
        echo '<tr>'
            . '<td>' . (int)($entry['n'] ?? 0) . '</td>'
            . '<td>' . View::e($entry['at'] ?? '') . '</td>'
            . '<td>' . View::e(($kinds[$entry['kind'] ?? ''] ?? ($entry['kind'] ?? '')) . ', попытка ' . (int)($entry['attempt'] ?? 0)) . '</td>'
            . '<td>' . ((int)($entry['http'] ?? 0) ?: '—') . '</td>'
            . '<td>' . View::e($entry['status'] ?? '') . (!empty($entry['retryable']) ? ', повтор возможен' : '') . '</td>'
            . '<td>' . number_format((int)($entry['duration_ms'] ?? 0) / 1000, 1, ',', ' ') . ' с</td>'
            . '<td>' . View::number((int)($entry['in'] ?? 0)) . ' / ' . View::number((int)($entry['out'] ?? 0)) . '</td>'
            . '<td>' . View::e($details) . '</td>'
            . '</tr>';
    }
    echo '</table>';
    foreach ($log as $entry) {
        if ((string)($entry['response'] ?? '') !== '') {
            echo View::details('Ответ провайдера, обращение ' . (int)$entry['n'], Json::prettyOrRaw((string)$entry['response']));
        }
    }
}

/* ---------- Что передал кубик ---------- */
echo '<h3>Входные данные</h3>';
$inputsText = PromptBuilder::renderInputs((array)($input['inputs'] ?? []));
$params = (array)($input['params'] ?? []);
if ($params) {
    echo '<table class="ailab-kv">';
    foreach ($params as $name => $value) {
        echo '<tr><th>:' . View::e($name) . '</th><td>' . View::e($value) . '</td></tr>';
    }
    echo '</table>';
}
if (trim((string)($input['snippet'] ?? '')) !== '') {
    echo '<div class="ailab-label">Кусок промта из кубика</div><pre class="ailab-pre">' . View::e($input['snippet']) . '</pre>';
}
echo $inputsText !== ''
    ? '<pre class="ailab-pre">' . View::e($inputsText) . '</pre>'
    : '<p class="ailab-hint">Входных данных нет.</p>';

/* ---------- Промт ---------- */
echo '<h3>Промт</h3>';
if ($built === null) {
    echo '<p class="ailab-hint">Промт собирается, когда обработчик берёт задачу в работу.</p>';
} else {
    echo '<table class="ailab-kv">';
    foreach ((array)($built['sources'] ?? []) as $src) {
        echo '<tr><th>' . View::e($src['title'] ?? '') . '</th><td>' . View::number((int)($src['rows'] ?? 0)) . ' строк, ' . View::number((int)($src['chars'] ?? 0)) . ' символов</td></tr>';
    }
    echo '<tr><th>Весь промт</th><td>' . View::number((int)($built['chars'] ?? 0)) . ' символов</td></tr></table>';
    foreach ((array)($built['warnings'] ?? []) as $w) {
        echo '<p class="ailab-note">' . View::e($w) . '</p>';
    }
    $system = PromptStore::get((string)($built['prompt_hash'] ?? ''));
    echo View::details('Постоянная часть промта (system)', $system ?? 'не найдена', true);
    echo View::details('Данные заявки (user)', (string)($built['user_text'] ?? ''), true);
    $schema = OutputSchema::fromArray((array)($built['fields'] ?? []));
    if (!$schema->isEmpty()) {
        echo View::details('JSON Schema ответа', Json::encode($schema->jsonSchema(), true));
    }
}

if (!$final) {
    echo '<script>setTimeout(function () { window.location.reload(); }, 5000);</script>';
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
