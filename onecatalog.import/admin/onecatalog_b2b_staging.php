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

use OneCatalog\Import\Staging;

$canWrite = ($right >= 'W') || $USER->IsAdmin();

// Действия (игнор / вернуть / удалить).
if ($canWrite && $_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $id = (int) ($_POST['id'] ?? 0);
    $act = (string) ($_POST['act'] ?? '');
    if ($id > 0) {
        if ($act === 'ignore') {
            Staging::setStatus($id, 'ignored');
        } elseif ($act === 'restore') {
            Staging::setStatus($id, 'new');
        } elseif ($act === 'delete') {
            Staging::delete($id);
        }
    }
    LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID);
}

$new = Staging::listByStatus('new', 200);
$ignored = Staging::listByStatus('ignored', 200);

$APPLICATION->SetTitle(Loc::getMessage('ONECATALOG_STG_TITLE'));
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$sessid = bitrix_sessid_post();

$renderRows = static function (array $rows, string $kind) use ($canWrite, $sessid) {
    if (!$rows) {
        echo '<tr><td colspan="5">' . Loc::getMessage('ONECATALOG_STG_EMPTY') . '</td></tr>';
        return;
    }
    foreach ($rows as $r) {
        echo '<tr>';
        echo '<td>' . (int) $r['ID'] . '</td>';
        echo '<td>' . htmlspecialcharsbx((string) ($r['NAME'] ?: '—')) . '</td>';
        echo '<td><code>' . htmlspecialcharsbx((string) $r['CODE']) . '</code></td>';
        echo '<td>' . (int) $r['SUPPLIER_ID'] . '</td>';
        echo '<td>';
        if ($canWrite) {
            $btns = $kind === 'new'
                ? [['ignore', 'ONECATALOG_STG_IGNORE'], ['delete', 'ONECATALOG_STG_DELETE']]
                : [['restore', 'ONECATALOG_STG_RESTORE'], ['delete', 'ONECATALOG_STG_DELETE']];
            foreach ($btns as $b) {
                echo '<form method="post" style="display:inline">' . $sessid
                    . '<input type="hidden" name="id" value="' . (int) $r['ID'] . '">'
                    . '<input type="hidden" name="act" value="' . $b[0] . '">'
                    . '<input type="submit" class="adm-btn" value="' . Loc::getMessage($b[1]) . '"></form> ';
            }
        }
        echo '</td></tr>';
    }
};
?>
<p>
    <a class="adm-btn" href="onecatalog_pricestock.php?lang=<?= LANGUAGE_ID ?>"><?= Loc::getMessage('ONECATALOG_STG_GO') ?></a>
</p>
<p><?= Loc::getMessage('ONECATALOG_STG_HINT') ?></p>

<h4><?= Loc::getMessage('ONECATALOG_STG_NEW') ?> (<?= count($new) ?>)</h4>
<table class="adm-list-table">
    <tr class="adm-list-table-header">
        <td>ID</td><td><?= Loc::getMessage('ONECATALOG_STG_NAME') ?></td><td><?= Loc::getMessage('ONECATALOG_STG_CODE') ?></td><td><?= Loc::getMessage('ONECATALOG_STG_SUPPLIER') ?></td><td></td>
    </tr>
    <?php $renderRows($new, 'new'); ?>
</table>

<h4 style="margin-top:18px"><?= Loc::getMessage('ONECATALOG_STG_IGNORED') ?> (<?= count($ignored) ?>)</h4>
<table class="adm-list-table">
    <tr class="adm-list-table-header">
        <td>ID</td><td><?= Loc::getMessage('ONECATALOG_STG_NAME') ?></td><td><?= Loc::getMessage('ONECATALOG_STG_CODE') ?></td><td><?= Loc::getMessage('ONECATALOG_STG_SUPPLIER') ?></td><td></td>
    </tr>
    <?php $renderRows($ignored, 'ignored'); ?>
</table>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
