<?php
/** @global CMain $APPLICATION */
if (!check_bitrix_sessid()) {
    return;
}

$ex = $APPLICATION->GetException();
if ($ex) {
    CAdminMessage::ShowMessage([
        'TYPE'    => 'ERROR',
        'MESSAGE' => 'Модуль AI Lab не установлен',
        'DETAILS' => htmlspecialcharsbx($ex->GetString()),
        'HTML'    => true,
    ]);
} else {
    CAdminMessage::ShowMessage([
        'TYPE'    => 'OK',
        'MESSAGE' => 'Модуль AI Lab установлен',
        'DETAILS' => 'Подключения к провайдерам: Сервисы → AI Lab → Подключения.',
        'HTML'    => true,
    ]);
}
?>
<form action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="submit" value="Вернуться к списку модулей">
</form>
