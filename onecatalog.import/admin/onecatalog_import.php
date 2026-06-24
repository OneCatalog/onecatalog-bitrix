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
        'cancelled' => Loc::getMessage('ONECATALOG_JS_CANCELLED'),
        'created'   => Loc::getMessage('ONECATALOG_JS_CREATED'),
        'updated'   => Loc::getMessage('ONECATALOG_JS_UPDATED'),
        'skipped'   => Loc::getMessage('ONECATALOG_JS_SKIPPED'),
        'errors'    => Loc::getMessage('ONECATALOG_JS_ERRORS'),
        'last'      => Loc::getMessage('ONECATALOG_JS_LAST'),
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
<style>
    #oc-import-app .oc-spinner {
        display: none; width: 14px; height: 14px; margin-right: 7px;
        border: 2px solid #cfd8e3; border-top-color: #2067b0; border-radius: 50%;
        vertical-align: middle; animation: oc-spin .7s linear infinite;
    }
    @keyframes oc-spin { to { transform: rotate(360deg); } }
    #oc-import-app .oc-bar-wrap { height: 6px; background: #eee; border-radius: 3px; margin-top: 8px; overflow: hidden; }
    #oc-import-app .oc-bar { height: 100%; width: 0; background: #2067b0; transition: width .2s ease; }
    #oc-import-app .oc-bar.oc-ok  { background: #3aa76d; }
    #oc-import-app .oc-bar.oc-err { background: #d9534f; }
    #oc-import-app button[disabled] { opacity: .55; cursor: default; }
    #oc-import-app textarea[disabled] { background: #f3f3f3; }
</style>
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
        <button type="button" class="adm-btn" id="oc-cancel-btn" style="display:none">
            <?= Loc::getMessage('ONECATALOG_JS_CANCEL') ?>
        </button>
    </p>
    <div id="oc-status" style="margin:12px 0;display:none">
        <span class="oc-spinner" id="oc-spinner"></span>
        <span id="oc-progress" style="font-weight:bold"></span>
        <div class="oc-bar-wrap"><div class="oc-bar" id="oc-bar"></div></div>
        <div id="oc-summary" style="margin-top:8px;color:#333"></div>
    </div>
    <pre id="oc-log" style="max-height:340px;overflow:auto;background:#f7f7f7;border:1px solid #ddd;padding:8px"></pre>
</div>
<script>window.OneCatalogCfg = <?= Json::encode($cfg) ?>;</script>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
