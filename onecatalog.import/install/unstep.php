<?php
use Bitrix\Main\Localization\Loc;
if (!check_bitrix_sessid()) return;
Loc::loadMessages(__DIR__ . '/index.php');
?>
<form action="<?= $APPLICATION->GetCurPage() ?>">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="submit" name="" value="<?= Loc::getMessage('MOD_BACK') ?>">
</form>
