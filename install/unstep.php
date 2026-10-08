<?php
/** @global CMain $APPLICATION */
if (!check_bitrix_sessid()) {
    return;
}

CAdminMessage::ShowMessage([
    'TYPE'    => 'OK',
    'MESSAGE' => 'Модуль AI Lab удалён',
    'DETAILS' => 'Таблицы local_ailab_* и ключ шифрования оставлены на месте: при повторной установке подключения сохранятся.',
    'HTML'    => true,
]);
?>
<form action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="submit" value="Вернуться к списку модулей">
</form>
