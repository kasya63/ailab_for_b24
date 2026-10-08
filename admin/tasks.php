<?php
/** AI Lab: журнал — очередь и история запросов. */

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Local\AiLab\Admin\View;
use Local\AiLab\Installer;
use Local\AiLab\Model\ScenarioTable;
use Local\AiLab\Model\TaskTable;
use Local\AiLab\Queue\Queue;
use Local\AiLab\Queue\TaskRepository;
use Local\AiLab\Queue\TaskStatus;

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

$APPLICATION->SetTitle('AI Lab: журнал');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if ($fatal !== '') {
    View::message('ERROR', $fatal);
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

echo View::styles();
View::workerBanner();

const AILAB_PERIODS = ['today' => 'сегодня', '7d' => '7 дней', '30d' => '30 дней', 'all' => 'всё время'];
const AILAB_PER_PAGE = 50;

$f = [
    'scenario' => max(0, (int)($_GET['scenario'] ?? 0)),
    'status'   => (string)($_GET['status'] ?? ''),
    'origin'   => (string)($_GET['origin'] ?? ''),
    'period'   => isset(AILAB_PERIODS[$_GET['period'] ?? '']) ? (string)$_GET['period'] : '7d',
    'task'     => max(0, (int)($_GET['task'] ?? 0)),
];
$pageNum = max(1, (int)($_GET['p'] ?? 1));

$filter = [];
if ($f['task'] > 0) {
    $filter['=ID'] = $f['task'];
} else {
    if ($f['scenario'] > 0) {
        $filter['=SCENARIO_ID'] = $f['scenario'];
    }
    if (isset(Queue::ORIGINS[$f['origin']])) {
        $filter['=ORIGIN'] = $f['origin'];
    }
    $from = match ($f['period']) {
        'today' => strtotime('today'),
        '7d'    => time() - 7 * 86400,
        '30d'   => time() - 30 * 86400,
        default => 0,
    };
    if ($from > 0) {
        $filter['>=CREATED_AT'] = DateTime::createFromTimestamp($from);
    }
}
$summaryFilter = $filter;
if (isset(TaskStatus::LABELS[$f['status']])) {
    $filter['=STATUS'] = $f['status'];
}

$scenarios = [];
foreach (ScenarioTable::getList(['select' => ['ID', 'CODE', 'NAME'], 'order' => ['NAME' => 'ASC']])->fetchAll() as $s) {
    $scenarios[(int)$s['ID']] = $s['NAME'] . ' (' . $s['CODE'] . ')';
}

/* Сводка за период */
$byStatus = TaskRepository::countByStatus($summaryFilter);
$totals = TaskRepository::totals($summaryFilter);
$parts = [];
foreach (TaskStatus::LABELS as $code => $label) {
    if (!empty($byStatus[$code])) {
        $parts[] = $label . ' ' . View::number($byStatus[$code]);
    }
}
echo '<p>За период: <b>' . View::number($totals['count']) . '</b> задач' . ($parts ? ' — ' . View::e(implode(', ', $parts)) : '')
    . '. Токены: вход ' . View::number($totals['in']) . ' (из кэша ' . View::number($totals['cached']) . '), выход ' . View::number($totals['out']) . '.</p>';

/* Фильтр */
?>
<form method="get" class="ailab-filter">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <select name="scenario"><?= View::options(['0' => 'все сценарии'] + array_map('strval', $scenarios), (string)$f['scenario']) ?></select>
    <select name="status"><?= View::options(['' => 'все статусы'] + TaskStatus::LABELS, $f['status']) ?></select>
    <select name="origin"><?= View::options(['' => 'откуда угодно'] + Queue::ORIGINS, $f['origin']) ?></select>
    <select name="period"><?= View::options(AILAB_PERIODS, $f['period']) ?></select>
    № <input type="text" name="task" size="6" value="<?= $f['task'] ?: '' ?>">
    <input type="submit" value="Показать">
    <a href="<?= View::e(View::url('ailab_tasks.php')) ?>">сбросить</a>
</form>
<?php

$total = (int)TaskTable::getCount($filter);
$rows = TaskTable::getList([
    'select' => ['ID', 'SCENARIO_ID', 'SCENARIO_CODE', 'ORIGIN', 'STATUS', 'ATTEMPTS', 'CALLS', 'INPUT_TOKENS',
        'OUTPUT_TOKENS', 'CACHED_TOKENS', 'DURATION_MS', 'RESULT', 'ERROR_TEXT', 'CREATED_AT', 'WORKFLOW_ID'],
    'filter' => $filter,
    'order'  => ['ID' => 'DESC'],
    'limit'  => AILAB_PER_PAGE,
    'offset' => ($pageNum - 1) * AILAB_PER_PAGE,
])->fetchAll();

if (!$rows) {
    echo '<div class="ailab-empty">Задач по этому фильтру нет. Поставить тестовую задачу можно со страницы «Тестовый прогон» сценария.</div>';
} else {
    ?>
    <table class="adm-list-table">
        <thead>
        <tr class="adm-list-table-header">
            <?php foreach (['№', 'Создана', 'Сценарий', 'Откуда', 'Статус', 'Обращений', 'Время', 'Токены вход / выход', 'Итог'] as $head): ?>
                <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner"><?= View::e($head) ?></div></td>
            <?php endforeach; ?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row):
            $url = View::url('ailab_task.php', ['id' => (int)$row['ID']]);
            if ($row['STATUS'] === TaskStatus::SUCCESS) {
                $summary = preg_replace('/\s+/u', ' ', (string)$row['RESULT']);
            } else {
                $summary = (string)$row['ERROR_TEXT'];
            }
            ?>
            <tr class="adm-list-table-row">
                <td class="adm-list-table-cell"><a href="<?= View::e($url) ?>"><?= (int)$row['ID'] ?></a></td>
                <td class="adm-list-table-cell"><?= $row['CREATED_AT'] instanceof DateTime ? $row['CREATED_AT']->format('d.m.Y H:i:s') : '' ?></td>
                <td class="adm-list-table-cell"><?= View::e($scenarios[(int)$row['SCENARIO_ID']] ?? $row['SCENARIO_CODE'] . ' (удалён)') ?></td>
                <td class="adm-list-table-cell"><?= View::e(Queue::ORIGINS[$row['ORIGIN']] ?? $row['ORIGIN']) ?></td>
                <td class="adm-list-table-cell"><?= View::taskStatus((string)$row['STATUS']) ?></td>
                <td class="adm-list-table-cell"><?= (int)$row['CALLS'] ?></td>
                <td class="adm-list-table-cell"><?= $row['DURATION_MS'] > 0 ? number_format($row['DURATION_MS'] / 1000, 1, ',', ' ') . ' с' : '' ?></td>
                <td class="adm-list-table-cell"><?= $row['INPUT_TOKENS'] > 0 ? View::number((int)$row['INPUT_TOKENS']) . ' / ' . View::number((int)$row['OUTPUT_TOKENS']) : '' ?></td>
                <td class="adm-list-table-cell"><div class="ailab-cut" title="<?= View::e(mb_substr($summary, 0, 500)) ?>"><?= View::e(mb_strimwidth($summary, 0, 140, '…')) ?></div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    $pages = (int)ceil($total / AILAB_PER_PAGE);
    if ($pages > 1) {
        echo '<div class="ailab-pager">Страницы: ';
        for ($p = 1; $p <= $pages; $p++) {
            if ($p > 3 && $p < $pages - 2 && abs($p - $pageNum) > 2) {
                if ($p === 4 || $p === $pages - 3) {
                    echo '… ';
                }
                continue;
            }
            echo $p === $pageNum
                ? '<b>' . $p . '</b>'
                : '<a href="' . View::e(View::url('ailab_tasks.php', array_filter($f) + ['p' => $p])) . '">' . $p . '</a>';
        }
        echo '</div>';
    }
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
