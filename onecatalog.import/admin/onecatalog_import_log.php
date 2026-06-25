<?php

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

/** @var CMain $APPLICATION */
/** @var CUser $USER */

$moduleId = 'onecatalog.import';
Loc::loadMessages(__FILE__);

$right = $APPLICATION->GetGroupRight($moduleId);
if ($right < 'R' && !$USER->IsAdmin()) {
    $APPLICATION->AuthForm(Loc::getMessage('ACCESS_DENIED'));
}
Loader::includeModule($moduleId);

use OneCatalog\Import\Queue;

$canWrite = ($right >= 'W') || $USER->IsAdmin();

if ($canWrite && $_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && isset($_POST['clear'])) {
    Queue::clearLog();
    LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID);
}

$log = array_reverse(Queue::getLog()); // новые сверху

$APPLICATION->SetTitle(Loc::getMessage('ONECATALOG_ILOG_TITLE'));
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>
<p>
    <a class="adm-btn" href="onecatalog_import.php?lang=<?= LANGUAGE_ID ?>"><?= Loc::getMessage('ONECATALOG_ILOG_GO') ?></a>
    <?php if ($canWrite && $log): ?>
    <form method="post" style="display:inline" onsubmit="return confirm('?');"><?= bitrix_sessid_post() ?>
        <input type="hidden" name="clear" value="Y">
        <input type="submit" class="adm-btn" value="<?= Loc::getMessage('ONECATALOG_ILOG_CLEAR') ?>">
    </form>
    <?php endif; ?>
</p>
<p><?= Loc::getMessage('ONECATALOG_ILOG_HINT') ?></p>
<table class="adm-list-table">
    <tr class="adm-list-table-header">
        <td><?= Loc::getMessage('ONECATALOG_ILOG_TIME') ?></td>
        <td><?= Loc::getMessage('ONECATALOG_ILOG_KEY') ?></td>
        <td><?= Loc::getMessage('ONECATALOG_ILOG_STATUS') ?></td>
        <td><?= Loc::getMessage('ONECATALOG_ILOG_MESSAGE') ?></td>
    </tr>
    <?php if ($log): ?>
        <?php foreach ($log as $e): ?>
        <tr>
            <td><?= !empty($e['ts']) ? date('Y-m-d H:i:s', (int) $e['ts']) : '' ?></td>
            <td><?= htmlspecialcharsbx((string) ($e['public_id'] ?? '')) ?></td>
            <td><?= htmlspecialcharsbx((string) ($e['status'] ?? '')) ?></td>
            <td><?= htmlspecialcharsbx((string) ($e['message'] ?? '')) ?></td>
        </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="4"><?= Loc::getMessage('ONECATALOG_ILOG_EMPTY') ?></td></tr>
    <?php endif; ?>
</table>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
