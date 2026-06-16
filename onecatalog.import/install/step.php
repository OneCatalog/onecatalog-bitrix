<?php
use Bitrix\Main\Localization\Loc;
if (!check_bitrix_sessid()) return;
Loc::loadMessages(__DIR__ . '/index.php');
?>
<div class="adm-info-message">
    <?= Loc::getMessage('ONECATALOG_IMPORT_INSTALL_OK') ?>
</div>
<form action="<?= $APPLICATION->GetCurPage() ?>">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="submit" name="" value="<?= Loc::getMessage('MOD_BACK') ?>">
</form>
