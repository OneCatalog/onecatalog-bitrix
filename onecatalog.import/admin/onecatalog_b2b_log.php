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

use OneCatalog\Import\PriceStockSync;

$progress = PriceStockSync::getProgress();
$history  = PriceStockSync::getHistory();
$log      = array_slice(PriceStockSync::getLog(), 0, 60);

$APPLICATION->SetTitle(Loc::getMessage('ONECATALOG_BLOG_TITLE'));
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>
<p>
    <a class="adm-btn" href="onecatalog_pricestock.php?lang=<?= LANGUAGE_ID ?>"><?= Loc::getMessage('ONECATALOG_BLOG_GO') ?></a>
    <a class="adm-btn" href="onecatalog_b2b_log.php?lang=<?= LANGUAGE_ID ?>"><?= Loc::getMessage('ONECATALOG_BLOG_REFRESH') ?></a>
</p>

<?php if ($progress): $running = empty($progress['finished']); ?>
    <p><strong><?= $running ? Loc::getMessage('ONECATALOG_BLOG_CURRENT') : Loc::getMessage('ONECATALOG_BLOG_LAST') ?>:</strong>
        <?php
        printf(
            /* translators: scanned/total/changed/unchanged */
            Loc::getMessage('ONECATALOG_BLOG_SUMMARY'),
            (int) ($progress['scanned'] ?? 0),
            (int) ($progress['total'] ?? 0),
            (int) ($progress['changed'] ?? 0),
            (int) ($progress['unchanged'] ?? 0)
        );
        echo ' — ' . ($running ? Loc::getMessage('ONECATALOG_BLOG_RUNNING') : Loc::getMessage('ONECATALOG_BLOG_FINISHED'));
        ?>
    </p>
<?php endif; ?>

<h4><?= Loc::getMessage('ONECATALOG_BLOG_HISTORY') ?></h4>
<table class="adm-list-table" style="width:auto">
    <thead><tr class="adm-list-table-header">
        <td class="adm-list-table-cell"><?= Loc::getMessage('ONECATALOG_BLOG_STARTED') ?></td>
        <td class="adm-list-table-cell"><?= Loc::getMessage('ONECATALOG_BLOG_FINISHED_AT') ?></td>
        <td class="adm-list-table-cell"><?= Loc::getMessage('ONECATALOG_BLOG_SCANNED') ?></td>
        <td class="adm-list-table-cell"><?= Loc::getMessage('ONECATALOG_BLOG_CHANGED') ?></td>
        <td class="adm-list-table-cell"><?= Loc::getMessage('ONECATALOG_BLOG_UNCHANGED') ?></td>
    </tr></thead>
    <tbody>
    <?php if (!$history): ?>
        <tr><td class="adm-list-table-cell" colspan="5"><?= Loc::getMessage('ONECATALOG_BLOG_EMPTY') ?></td></tr>
    <?php else: foreach ($history as $h): ?>
        <tr class="adm-list-table-row">
            <td class="adm-list-table-cell"><?= !empty($h['started']) ? date('Y-m-d H:i:s', (int) $h['started']) : '—' ?></td>
            <td class="adm-list-table-cell"><?= !empty($h['finished']) ? date('Y-m-d H:i:s', (int) $h['finished']) : '—' ?></td>
            <td class="adm-list-table-cell"><?= (int) ($h['scanned'] ?? 0) ?> / <?= (int) ($h['total'] ?? 0) ?></td>
            <td class="adm-list-table-cell"><b><?= (int) ($h['changed'] ?? 0) ?></b></td>
            <td class="adm-list-table-cell"><?= (int) ($h['unchanged'] ?? 0) ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>

<h4><?= Loc::getMessage('ONECATALOG_BLOG_DETAILS') ?></h4>
<pre style="max-height:300px;overflow:auto;background:#f7f7f7;border:1px solid #ddd;padding:8px"><?php
foreach ($log as $e) {
    echo htmlspecialcharsbx('[' . ($e['status'] ?? '') . '] ' . ($e['key'] ?? '') . (($e['message'] ?? '') !== '' ? ' — ' . $e['message'] : '')) . "\n";
}
?></pre>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
