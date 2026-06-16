<?php

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

/** @var CMain $APPLICATION */
/** @var CUser $USER */

$moduleId = 'onecatalog.import';
Loc::loadMessages(__FILE__);

$right = $APPLICATION->GetGroupRight($moduleId);
if ($right < 'R' && !$USER->IsAdmin()) {
    $APPLICATION->AuthForm(Loc::getMessage('ACCESS_DENIED'));
}

if (!Loader::includeModule($moduleId)) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    echo CAdminMessage::ShowMessage('Module ' . $moduleId . ' is not installed.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    die();
}

use OneCatalog\Import\Settings;
use OneCatalog\Import\Queue;

// ---------------------------------------------------------------------------
// AJAX-ветка (степпер): импорт порции / статус. Тот же URL, что и страница.
// ---------------------------------------------------------------------------
if (($_REQUEST['ajax'] ?? '') === 'Y') {
    header('Content-Type: application/json; charset=UTF-8');

    $canWrite = ($right >= 'W') || $USER->IsAdmin();
    if (!check_bitrix_sessid() || !$canWrite) {
        echo Json::encode(['error' => 'forbidden']);
        die();
    }

    $act = (string) ($_REQUEST['act'] ?? '');
    if ($act === 'import') {
        $ids = $_POST['ids'] ?? [];
        if (is_string($ids)) {
            $ids = preg_split('/[\s,;]+/', $ids, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $ids = array_values(array_filter(array_map('trim', (array) $ids)));

        if (Settings::catalogIblockId() <= 0) {
            echo Json::encode(['error' => 'no_iblock']);
            die();
        }
        $results = Queue::importBatch($ids);
        echo Json::encode(['ok' => true, 'results' => $results, 'log' => Queue::getLog()]);
    } elseif ($act === 'status') {
        echo Json::encode(['ok' => true, 'log' => Queue::getLog()]);
    } else {
        echo Json::encode(['error' => 'unknown_action']);
    }
    die();
}

// ---------------------------------------------------------------------------
// Рендер страницы
// ---------------------------------------------------------------------------
$APPLICATION->SetTitle(Loc::getMessage('ONECATALOG_IMPORT_PAGE_TITLE'));
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$iblockConfigured = Settings::catalogIblockId() > 0;
$request = \Bitrix\Main\Context::getCurrent()->getRequest();
$scheme = $request->isHttps() ? 'https://' : 'http://';

$cfg = [
    'ajaxUrl'      => $APPLICATION->GetCurPage(),
    'sessid'       => bitrix_sessid(),
    'pickerBase'   => Settings::pickerBase(),
    'token'        => Settings::apiToken(),
    'step'         => Settings::step(),
    'parentOrigin' => $scheme . $request->getHttpHost(),
    'messages'     => [
        'empty'     => Loc::getMessage('ONECATALOG_JS_EMPTY'),
        'importing' => Loc::getMessage('ONECATALOG_JS_IMPORTING'),
        'done'      => Loc::getMessage('ONECATALOG_JS_DONE'),
        'error'     => Loc::getMessage('ONECATALOG_JS_ERROR'),
    ],
];

$APPLICATION->AddHeadScript('/bitrix/js/onecatalog.import/picker-loader.js');
$APPLICATION->AddHeadScript('/bitrix/js/onecatalog.import/admin-import.js');

if (!$iblockConfigured) {
    echo CAdminMessage::ShowMessage([
        'TYPE'    => 'ERROR',
        'MESSAGE' => Loc::getMessage('ONECATALOG_NO_IBLOCK'),
        'HTML'    => true,
    ]);
}
?>
<div id="oc-import-app" style="max-width:760px">
    <p>
        <button type="button" class="adm-btn adm-btn-save" id="oc-open-picker">
            <?= Loc::getMessage('ONECATALOG_PICK_BTN') ?>
        </button>
    </p>
    <p><label for="oc-ids"><?= Loc::getMessage('ONECATALOG_OR_PASTE') ?></label></p>
    <textarea id="oc-ids" rows="4" style="width:100%" placeholder="OC.IND.1, OC.IND.2, …"></textarea>
    <p>
        <button type="button" class="adm-btn" id="oc-import-btn">
            <?= Loc::getMessage('ONECATALOG_IMPORT_BTN') ?>
        </button>
    </p>
    <div id="oc-progress" style="font-weight:bold;margin:10px 0"></div>
    <pre id="oc-log" style="max-height:340px;overflow:auto;background:#f7f7f7;border:1px solid #ddd;padding:8px"></pre>
</div>
<script>window.OneCatalogCfg = <?= Json::encode($cfg) ?>;</script>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
