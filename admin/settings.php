<?php
/** AI Lab: настройки модуля — очередь, журнал, файлы, уведомления; проверка инструментов. */

use Bitrix\Main\Loader;
use Local\AiLab\Admin\View;
use Local\AiLab\Files\Shell;
use Local\AiLab\Installer;
use Local\AiLab\Maintenance;
use Local\AiLab\Queue\WorkerStatus;
use Local\AiLab\Settings;

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

/** Поля формы: ключ => [подпись, тип, подсказка, min, max] */
const AILAB_SETTINGS_FORM = [
    'Очередь' => [
        'queue_paused'      => ['Пауза очереди', 'flag', 'Новые задачи не отправляются, уже ждущие получат «время вышло» по своему сроку. На время проблем у провайдера или обслуживания.'],
        'queue_parallel'    => ['Запросов одновременно', 'int', 'Сколько запросов к провайдерам идёт параллельно.', 1, 10],
        'queue_timeout_min' => ['Ждать ответа по умолчанию, мин', 'int', 'Если в кубике не задано своё время.', 1, 1440],
        'queue_max_pending' => ['Задач в очереди, не больше', 'int', 'Защита от зациклившегося процесса и от умершего обработчика: сверх предела задачи не принимаются, кубик сразу получает error.', 10, 100000],
    ],
    'Журнал' => [
        'log_days' => ['Хранить задачи, дней', 'int', 'Завершённые задачи старше удаляются раз в сутки обработчиком очереди.', 1, 3650],
    ],
    'Файлы' => [
        'files_max_mb'         => ['Размер файла, МБ, не больше', 'int', '', 1, 200],
        'files_image_max_mb'   => ['Размер картинки, МБ, не больше', 'int', 'У провайдеров свои пределы: у Anthropic 5 МБ на картинку.', 1, 20],
        'files_pdf_max_pages'  => ['Страниц PDF целиком, не больше', 'int', 'Страница PDF целиком — примерно 1–2 тыс. токенов.', 1, 1000],
        'files_text_max_chars' => ['Текста из файла, символов, не больше', 'int', 'Превышение — ошибка, а не обрезка.', 1000, 2000000],
        'files_max_count'      => ['Файлов в запросе, не больше', 'int', '', 1, 50],
        'path_soffice'         => ['LibreOffice (soffice)', 'path', 'Word, Excel, PowerPoint → текст.'],
        'path_pdftotext'       => ['pdftotext', 'path', 'PDF → текст.'],
        'path_pdfinfo'         => ['pdfinfo', 'path', 'Число страниц PDF.'],
    ],
    'Уведомления' => [
        'notify_enabled' => ['Уведомлять администраторов', 'flag', 'В мессенджер администраторам портала, не чаще раза в час по каждому поводу: провайдер отклоняет ключ, обработчик молчит при ждущих задачах, суточный лимит сценария, переполнение очереди, процесс не удалось продолжить.'],
    ],
];

$page = $APPLICATION->GetCurPage();
$errors = [];
$notice = isset($_GET['saved']) ? 'Настройки сохранены.' : '';
$tools = null;
$cleaned = null;

if ($fatal === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_bitrix_sessid()) {
        $errors[] = 'Сессия устарела. Обновите страницу и повторите действие.';
    } else {
        $action = (string)($_POST['action'] ?? 'save');
        if ($action === 'save') {
            foreach (AILAB_SETTINGS_FORM as $fields) {
                foreach ($fields as $key => $def) {
                    $raw = $_POST['S'][$key] ?? '';
                    if ($def[1] === 'flag') {
                        Settings::set($key, $raw === 'Y' ? 'Y' : 'N');
                    } elseif ($def[1] === 'int') {
                        $value = (int)$raw;
                        if ((string)$raw === '' || $value < $def[3] || $value > $def[4]) {
                            $errors[] = sprintf('«%s»: число от %d до %d.', $def[0], $def[3], $def[4]);
                            continue;
                        }
                        Settings::set($key, (string)$value);
                    } else {
                        Settings::set($key, trim((string)$raw));
                    }
                }
            }
            if (!$errors) {
                LocalRedirect(View::url($page, ['saved' => 1]));
            }
        } elseif ($action === 'tools') {
            $tools = [];
            foreach (['soffice' => ['--version'], 'pdftotext' => ['-v'], 'pdfinfo' => ['-v']] as $name => $args) {
                try {
                    $r = Shell::run(array_merge([Settings::binary($name)], $args), 60);
                    $out = trim($r['stdout'] . "\n" . $r['stderr']);
                    $tools[$name] = [$r['code'] === 0 || $out !== '', strtok($out, "\n") ?: 'код ' . $r['code']];
                } catch (\Throwable $e) {
                    $tools[$name] = [false, $e->getMessage()];
                }
            }
        } elseif ($action === 'cleanup') {
            try {
                $cleaned = Maintenance::cleanup(Settings::logDays());
            } catch (\Throwable $e) {
                $errors[] = 'Чистка не удалась: ' . $e->getMessage();
            }
        }
    }
}

$APPLICATION->SetTitle('AI Lab: настройки');
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
if ($cleaned !== null) {
    View::message('OK', sprintf('Журнал почищен: удалено задач %d, промтов %d.', $cleaned['tasks'], $cleaned['prompts']));
}
if ($tools !== null) {
    $html = '<table class="ailab-kv">';
    foreach ($tools as $name => [$ok, $text]) {
        $html .= '<tr><th>' . View::e($name) . '</th><td>' . ($ok ? '✓ ' : '✗ ') . View::e($text) . '</td></tr>';
    }
    $html .= '</table><p class="ailab-hint">Проверка выполнена от пользователя веб-сервера; обработчик очереди запускается от того же пользователя bitrix.</p>';
    View::message(array_filter(array_column($tools, 0)) === array_column($tools, 0) ? 'OK' : 'ERROR', 'Инструменты для файлов', $html);
}
View::workerBanner();
$last = WorkerStatus::lastRun();
if ($last) {
    echo '<p class="ailab-hint">Последний запуск обработчика: ' . View::e(View::ago((int)$last['at'])) . ', ' . View::e(\Local\AiLab\Json::encode($last)) . '</p>';
}

$tabs = new CAdminTabControl('ailab_settings', array_map(
    static fn($title, $i) => ['DIV' => 'ailab_s' . $i, 'TAB' => $title, 'TITLE' => $title],
    array_keys(AILAB_SETTINGS_FORM),
    array_keys(array_keys(AILAB_SETTINGS_FORM))
));
?>
<form method="post" action="<?= View::e(View::url($page)) ?>">
    <?= bitrix_sessid_post() ?>
    <?php $tabs->Begin(); ?>
    <?php foreach (AILAB_SETTINGS_FORM as $section => $fields): $tabs->BeginNextTab(); ?>
        <?php foreach ($fields as $key => $def):
            $value = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['S'][$key]) ? (string)$_POST['S'][$key] : Settings::get($key); ?>
            <tr>
                <td width="40%" class="adm-detail-valign-top"><?= View::e($def[0]) ?>:</td>
                <td width="60%">
                    <?php if ($def[1] === 'flag'): ?>
                        <input type="checkbox" name="S[<?= $key ?>]" value="Y"<?= $value === 'Y' ? ' checked' : '' ?>>
                    <?php elseif ($def[1] === 'int'): ?>
                        <input type="number" name="S[<?= $key ?>]" value="<?= View::e($value) ?>" min="<?= (int)$def[3] ?>" max="<?= (int)$def[4] ?>">
                    <?php else: ?>
                        <input type="text" name="S[<?= $key ?>]" value="<?= View::e($value) ?>" size="40" class="ailab-mono">
                    <?php endif; ?>
                    <?php if ($def[2] !== ''): ?><div class="ailab-hint"><?= View::e($def[2]) ?></div><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    <?php $tabs->Buttons(); ?>
    <button type="submit" name="action" value="save" class="adm-btn-save">Сохранить</button>
    <button type="submit" name="action" value="tools">Проверить инструменты для файлов</button>
    <button type="submit" name="action" value="cleanup" onclick="return confirm('Удалить завершённые задачи старше срока хранения?');">Почистить журнал сейчас</button>
    <?php $tabs->End(); ?>
</form>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
