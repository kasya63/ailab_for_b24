<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}
/** @var \Bitrix\Bizproc\Activity\PropertiesDialog $dialog */
/** @var array $ailab */

$e = static fn($v): string => htmlspecialcharsbx((string)$v);
$p = $ailab['props'];
$current = (string)$p['ScenarioCode'];
$chosenFields = array_flip(array_map('strval', (array)$p['DocumentFields']));
$inputs = array_values((array)$p['Inputs']);
for ($i = 0; $i < (int)$ailab['blankRows']; $i++) {
    $inputs[] = ['label' => '', 'description' => '', 'value' => ''];
}
?>
<tr>
    <td align="right" width="40%"><span class="adm-required-field">Сценарий AI Lab:</span></td>
    <td width="60%">
        <select name="ailab_scenario" id="ailab_scenario" onchange="ailabScenarioChanged()">
            <option value="">— выберите —</option>
            <?php foreach ($ailab['scenarios'] as $s): ?>
                <option value="<?= $e($s['code']) ?>"<?= $s['code'] === $current ? ' selected' : '' ?>>
                    <?= $e($s['name'] . ' (' . $s['code'] . ')' . ($s['active'] ? '' : ' — выключен')) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <a href="<?= $e($ailab['adminUrl']) ?>" target="_blank">сценарии</a>
    </td>
</tr>

<?php foreach ($ailab['scenarios'] as $s): ?>
    <tbody class="ailab-scn" data-code="<?= $e($s['code']) ?>" style="display:none">
    <tr>
        <td align="right" valign="top">Результаты кубика:</td>
        <td>
            status, error_text, log_id<?= $s['fields'] ? ', ' . $e(implode(', ', $s['fields'])) : '' ?>
            <div style="color:#80868e;font-size:11px">Поля ответа появятся в «Дополнительных результатах» после сохранения. Изменили формат ответа сценария — откройте и сохраните кубик снова.</div>
        </td>
    </tr>
    <?php foreach ($s['params'] as $name): ?>
        <tr>
            <td align="right">Параметр :<?= $e($name) ?>:</td>
            <td><?= CBPDocument::ShowParameterField(
                    'string',
                    'ailab_param__' . (int)$s['id'] . '__' . $name,
                    $s['code'] === $current ? (string)($p['Params'][$name] ?? '') : '',
                    ['size' => 40]
                ) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
<?php endforeach; ?>

<tr>
    <td align="right" valign="top">Поля документа:</td>
    <td>
        <?php if ($ailab['documentFields']): ?>
            <div style="max-height:220px;overflow:auto;border:1px solid #dce0e3;padding:4px 8px">
                <?php foreach ($ailab['documentFields'] as $code => $title): ?>
                    <label style="display:block;padding:1px 0">
                        <input type="checkbox" class="ailab-docf" name="ailab_doc_fields[]" value="<?= $e($code) ?>"<?= isset($chosenFields[$code]) ? ' checked' : '' ?> onclick="ailabDocFieldsChanged()">
                        <?= $e($title) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="ailab_doc_fields_list" id="ailab_doc_fields_list" value="<?= $e(implode(',', array_keys($chosenFields))) ?>">
            <div style="color:#80868e;font-size:11px">Значения уйдут модели в человеческом виде, как в тестовом прогоне: ФИО вместо ID, названия вместо кодов.</div>
        <?php else: ?>
            <span style="color:#80868e">Для этого типа документа недоступно — используйте дополнительные входы.</span>
        <?php endif; ?>
    </td>
</tr>

<?php if ($ailab['documentFields']): ?>
    <tr>
        <td align="right">Поля-файлы:</td>
        <td>
            <select name="ailab_file_mode">
                <?php foreach ($ailab['fileModes'] as $mode => $title): ?>
                    <option value="<?= $e($mode) ?>"<?= $mode === (string)$p['FileMode'] ? ' selected' : '' ?>><?= $e($title) ?></option>
                <?php endforeach; ?>
            </select>
            <div style="color:#80868e;font-size:11px">Для отмеченных полей типа «файл». PDF-страница целиком стоит примерно 1–2 тыс. токенов, текстом — в разы дешевле. Word и Excel всегда уходят текстом.</div>
        </td>
    </tr>
<?php endif; ?>

<tr>
    <td align="right" valign="top">Дополнительные входы:</td>
    <td>
        <table width="100%" cellpadding="2" cellspacing="0">
            <tr style="color:#535c69">
                <td width="28%">Название — обычным текстом</td>
                <td width="30%">Пояснение для модели</td>
                <td>Значение — через «…»</td>
            </tr>
            <?php foreach ($inputs as $i => $row): ?>
                <tr>
                    <td><input type="text" name="ailab_in_label_<?= $i ?>" value="<?= $e($row['label'] ?? '') ?>" style="width:95%" placeholder="например: Статья от скрипта"></td>
                    <td><input type="text" name="ailab_in_desc_<?= $i ?>" value="<?= $e($row['description'] ?? '') ?>" style="width:95%"></td>
                    <td><?= CBPDocument::ShowParameterField('string', 'ailab_in_value_' . $i, (string)($row['value'] ?? ''), ['size' => 25]) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <input type="hidden" name="ailab_in_count" value="<?= count($inputs) ?>">
        <div style="color:#80868e;font-size:11px">Название — как модель увидит этот вход, обычный текст. Переменную, поле или результат другого действия (EXPENSE_ID, AI_CONTEXT) выбирайте кнопкой «…» в колонке «Значение». Пустое название подставится из имени переменной. Нужно больше строк — сохраните и откройте кубик снова.</div>
    </td>
</tr>

<tr>
    <td align="right" valign="top">Кусок промта:</td>
    <td><?= CBPDocument::ShowParameterField('text', 'ailab_snippet', (string)$p['Snippet'], ['rows' => 3, 'cols' => 50]) ?></td>
</tr>

<tr>
    <td align="right">Ждать ответа не дольше, мин:</td>
    <td>
        <input type="number" name="ailab_timeout" min="1" max="1440" value="<?= (int)$p['TimeoutMinutes'] ?>" style="width:70px">
        <span style="color:#80868e;font-size:11px">потом status = timeout, процесс идёт дальше</span>
    </td>
</tr>

<?php if ($ailab['isCrm']): ?>
    <tr>
        <td align="right">Комментарий в таймлайн:</td>
        <td>
            <label><input type="checkbox" name="ailab_timeline" value="Y"<?= $p['TimelineComment'] === 'Y' ? ' checked' : '' ?>> добавить итог в таймлайн элемента</label>
        </td>
    </tr>
<?php endif; ?>

<script>
    function ailabScenarioChanged() {
        var select = document.getElementById('ailab_scenario');
        if (!select) {
            return;
        }
        var nodes = document.querySelectorAll('tbody.ailab-scn');
        for (var i = 0; i < nodes.length; i++) {
            nodes[i].style.display = nodes[i].getAttribute('data-code') === select.value ? '' : 'none';
        }
    }
    function ailabDocFieldsChanged() {
        var hidden = document.getElementById('ailab_doc_fields_list');
        if (!hidden) {
            return;
        }
        var codes = [];
        var boxes = document.querySelectorAll('input.ailab-docf');
        for (var i = 0; i < boxes.length; i++) {
            if (boxes[i].checked) {
                codes.push(boxes[i].value);
            }
        }
        hidden.value = codes.join(',');
    }
    ailabScenarioChanged();
    setTimeout(ailabScenarioChanged, 0);
</script>
