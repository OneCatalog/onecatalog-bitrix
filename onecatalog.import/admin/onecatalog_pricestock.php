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
$canWrite = ($right >= 'W') || $USER->IsAdmin();
Loader::includeModule($moduleId);

use OneCatalog\Import\Settings;
use OneCatalog\Import\PriceStockSync;
use OneCatalog\Import\B2bApi;

// --- AJAX: одна страница скана (браузерный степпер, без cron) ---
if (($_REQUEST['ajax'] ?? '') === 'Y') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!check_bitrix_sessid() || !$canWrite) {
        echo Json::encode(['error' => 'forbidden']);
        die();
    }
    $act = (string) ($_REQUEST['act'] ?? '');
    if ($act === 'syncpage') {
        if (!Settings::b2bConfigured()) {
            echo Json::encode(['error' => 'not_configured']);
            die();
        }
        $start = (int) ($_REQUEST['start'] ?? 0);
        $res = PriceStockSync::processPage($start, Settings::b2bPageSize());
        $res['log'] = array_slice(PriceStockSync::getLog(), 0, 30);
        echo Json::encode($res);
    } elseif ($act === 'test') {
        $meta = (new B2bApi())->discover();
        if ($meta === null) {
            echo Json::encode(['error' => 'feed']);
        } else {
            // Зафиксировать и подтвердить состав справочников (сбросить «новое»).
            Settings::set('B2B_FEED_META', Json::encode($meta));
            Settings::set('B2B_CATALOG_META', Json::encode($meta));
            Settings::set('B2B_NOTIFIED_HASH', '');
            echo Json::encode(['ok' => true, 'regions' => $meta['regions'], 'warehouses' => $meta['warehouses'], 'suppliers' => $meta['suppliers']]);
        }
    } else {
        echo Json::encode(['error' => 'unknown']);
    }
    die();
}

// --- сохранение настроек ---
$saved = false;
if ($canWrite && $_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && isset($_POST['save'])) {
    Settings::set('B2B_BASE_URL', rtrim(trim((string) ($_POST['B2B_BASE_URL'] ?? '')), '/') ?: Settings::DEFAULT_B2B_BASE);
    Settings::set('B2B_KEY', trim((string) ($_POST['B2B_KEY'] ?? '')));
    Settings::set('B2B_PRIVATE_KEY', trim((string) ($_POST['B2B_PRIVATE_KEY'] ?? '')));
    $strat = (string) ($_POST['B2B_PRICE_STRATEGY'] ?? 'min');
    Settings::set('B2B_PRICE_STRATEGY', in_array($strat, ['priority', 'min', 'supplier'], true) ? $strat : 'min');
    Settings::set('B2B_SUPPLIER_FIXED', (int) ($_POST['B2B_SUPPLIER_FIXED'] ?? 0));
    // Приоритеты: drag-and-drop отдаёт упорядоченный массив id; иначе — текстовый фолбэк.
    if (isset($_POST['B2B_REGION_ORDER'])) {
        Settings::set('B2B_REGION_PRIORITY', implode(',', array_map('intval', (array) $_POST['B2B_REGION_ORDER'])));
    } else {
        Settings::set('B2B_REGION_PRIORITY', trim((string) ($_POST['B2B_REGION_PRIORITY'] ?? '')));
    }
    if (isset($_POST['B2B_SUPPLIER_ORDER'])) {
        Settings::set('B2B_SUPPLIER_PRIORITY', implode(',', array_map('intval', (array) $_POST['B2B_SUPPLIER_ORDER'])));
    } else {
        Settings::set('B2B_SUPPLIER_PRIORITY', trim((string) ($_POST['B2B_SUPPLIER_PRIORITY'] ?? '')));
    }
    Settings::set('B2B_PRICE_GROUP', (int) ($_POST['B2B_PRICE_GROUP'] ?? 0));
    Settings::set('B2B_PROMO_GROUP', (int) ($_POST['B2B_PROMO_GROUP'] ?? 0));
    Settings::set('B2B_USE_STORES', empty($_POST['B2B_USE_STORES']) ? 'N' : 'Y');
    Settings::set('B2B_CURRENCY', trim((string) ($_POST['B2B_CURRENCY'] ?? '')));
    Settings::set('B2B_PAGE_SIZE', max(50, min(500, (int) ($_POST['B2B_PAGE_SIZE'] ?? 200))));
    Settings::set('B2B_PROMO_AS_SALE', empty($_POST['B2B_PROMO_AS_SALE']) ? 'N' : 'Y');
    Settings::set('B2B_DECIMAL_STOCK', empty($_POST['B2B_DECIMAL_STOCK']) ? 'N' : 'Y');
    Settings::set('B2B_NOTIFY', empty($_POST['B2B_NOTIFY']) ? 'N' : 'Y');
    Settings::acknowledgeFeed(); // сохранение = подтверждение текущего состава фида
    $saved = true;
}

$APPLICATION->SetTitle(Loc::getMessage('ONECATALOG_PS_TITLE'));
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if ($saved) {
    echo CAdminMessage::ShowNote(Loc::getMessage('ONECATALOG_PS_SAVED'));
}
if (Settings::catalogIblockId() <= 0) {
    echo CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => Loc::getMessage('ONECATALOG_PS_NO_IBLOCK'), 'HTML' => true]);
}
$newItems = Settings::b2bNewItems();
if ($newItems) {
    $parts = [];
    foreach ($newItems as $type => $items) {
        $parts[] = $type . ': ' . count($items);
    }
    echo CAdminMessage::ShowMessage(['TYPE' => 'PROGRESS', 'MESSAGE' => Loc::getMessage('ONECATALOG_PS_NEW') . ' (' . htmlspecialcharsbx(implode('; ', $parts)) . ')', 'HTML' => true]);
}

// Типы цен для выбора.
$priceGroups = [];
if (Loader::includeModule('catalog')) {
    $rs = \CCatalogGroup::GetListEx([], [], false, false, ['ID', 'NAME', 'NAME_LANG']);
    while ($g = $rs->Fetch()) {
        $priceGroups[(int) $g['ID']] = ($g['NAME_LANG'] ?: $g['NAME']) . ' (#' . $g['ID'] . ')';
    }
}
$curGroup = Settings::b2bPriceGroupId();
$strategy = Settings::b2bPriceStrategy();

// Списки для drag-and-drop приоритетов (лейблы из фида/подтверждённого).
$regions   = Settings::b2bRef('regions');
$suppliers = Settings::b2bRef('suppliers');
$newItems  = Settings::b2bNewItems();
$newReg    = array_map('intval', array_keys($newItems['regions'] ?? []));
$newSup    = array_map('intval', array_keys($newItems['suppliers'] ?? []));

$orderWithRest = static function (array $order, array $all): array {
    $all = array_map('intval', $all);
    $out = [];
    foreach ($order as $id) {
        $id = (int) $id;
        if (in_array($id, $all, true) && !in_array($id, $out, true)) {
            $out[] = $id;
        }
    }
    foreach ($all as $id) {
        if (!in_array($id, $out, true)) {
            $out[] = $id;
        }
    }
    return $out;
};
$regOrder = $orderWithRest(Settings::b2bRegionPriority(), array_keys($regions));
$supOrder = $orderWithRest(Settings::b2bSupplierPriority(), array_keys($suppliers));

// Рендер сортируемого списка (HTML5 drag-and-drop; порядок hidden-инпутов = DOM).
$renderSortable = static function (string $field, array $order, array $labels, array $newIds): void {
    echo '<ul class="oc-sortable" style="margin:0;max-width:420px;padding-left:0;list-style:none">';
    foreach ($order as $id) {
        $id = (int) $id;
        if (!isset($labels[$id])) {
            continue;
        }
        $isNew = in_array($id, $newIds, true);
        echo '<li draggable="true" style="padding:6px 10px;margin:3px 0;background:' . ($isNew ? '#fcf6e1' : '#fff')
            . ';border:1px solid ' . ($isNew ? '#dba617' : '#ccc') . ';border-radius:3px;cursor:move">';
        echo '☰ ' . htmlspecialcharsbx($labels[$id] . ' (#' . $id . ')');
        if ($isNew) {
            echo ' <b style="color:#a86b00">• new</b>';
        }
        echo '<input type="hidden" name="' . $field . '[]" value="' . $id . '"></li>';
    }
    echo '</ul>';
};
?>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?lang=<?= LANGUAGE_ID ?>">
    <?= bitrix_sessid_post() ?>
    <table class="adm-detail-content-table edit-table">
        <tr class="heading"><td colspan="2"><?= Loc::getMessage('ONECATALOG_PS_CONN') ?></td></tr>
        <tr><td width="40%"><?= Loc::getMessage('ONECATALOG_PS_KEY') ?></td>
            <td><input type="text" size="50" name="B2B_KEY" value="<?= htmlspecialcharsbx(Settings::b2bUrlKey()) ?>"></td></tr>
        <tr><td><?= Loc::getMessage('ONECATALOG_PS_PRIVATE') ?></td>
            <td><input type="text" size="50" name="B2B_PRIVATE_KEY" value="<?= htmlspecialcharsbx(Settings::b2bPrivateKey()) ?>"></td></tr>
        <tr><td><?= Loc::getMessage('ONECATALOG_PS_BASE') ?></td>
            <td><input type="text" size="50" name="B2B_BASE_URL" value="<?= htmlspecialcharsbx(Settings::b2bBase()) ?>"></td></tr>

        <tr class="heading"><td colspan="2"><?= Loc::getMessage('ONECATALOG_PS_PRICE') ?></td></tr>
        <tr><td colspan="2"><span class="adm-info" style="color:#777"><?= Loc::getMessage('ONECATALOG_PS_1C_HINT') ?></span></td></tr>
        <tr><td><?= Loc::getMessage('ONECATALOG_PS_STRATEGY') ?></td>
            <td>
                <select name="B2B_PRICE_STRATEGY">
                    <option value="min"<?= $strategy === 'min' ? ' selected' : '' ?>><?= Loc::getMessage('ONECATALOG_PS_STRAT_MIN') ?></option>
                    <option value="priority"<?= $strategy === 'priority' ? ' selected' : '' ?>><?= Loc::getMessage('ONECATALOG_PS_STRAT_PRIO') ?></option>
                    <option value="supplier"<?= $strategy === 'supplier' ? ' selected' : '' ?>><?= Loc::getMessage('ONECATALOG_PS_STRAT_FIXED') ?></option>
                </select>
            </td></tr>
        <tr><td><?= Loc::getMessage('ONECATALOG_PS_REGION_PRIO') ?></td>
            <td><?php if ($regions): $renderSortable('B2B_REGION_ORDER', $regOrder, $regions, $newReg); ?>
                <span class="adm-info"><?= Loc::getMessage('ONECATALOG_PS_DRAG_HINT') ?></span>
                <?php else: ?><input type="text" size="30" name="B2B_REGION_PRIORITY" value="<?= htmlspecialcharsbx((string) Settings::get('B2B_REGION_PRIORITY', '')) ?>" placeholder="1,3,6">
                <span class="adm-info"><?= Loc::getMessage('ONECATALOG_PS_IDS_HINT') ?></span><?php endif; ?></td></tr>
        <tr><td><?= Loc::getMessage('ONECATALOG_PS_SUPPLIER_PRIO') ?></td>
            <td><?php if ($suppliers): $renderSortable('B2B_SUPPLIER_ORDER', $supOrder, $suppliers, $newSup); ?>
                <?php else: ?><input type="text" size="30" name="B2B_SUPPLIER_PRIORITY" value="<?= htmlspecialcharsbx((string) Settings::get('B2B_SUPPLIER_PRIORITY', '')) ?>" placeholder="1,2"><?php endif; ?>
                <div style="margin-top:6px"><?= Loc::getMessage('ONECATALOG_PS_SUPPLIER_FIXED') ?>:
                    <input type="number" name="B2B_SUPPLIER_FIXED" value="<?= (int) Settings::b2bSupplierFixed() ?>" style="width:80px" title="supplier_id"></div></td></tr>
        <tr><td><?= Loc::getMessage('ONECATALOG_PS_PRICE_GROUP') ?></td>
            <td><select name="B2B_PRICE_GROUP">
                <option value="0"><?= Loc::getMessage('ONECATALOG_PS_BASE_GROUP') ?></option>
                <?php foreach ($priceGroups as $gid => $gname): ?>
                    <option value="<?= $gid ?>"<?= $curGroup === $gid ? ' selected' : '' ?>><?= htmlspecialcharsbx($gname) ?></option>
                <?php endforeach; ?>
            </select></td></tr>
        <tr><td><?= Loc::getMessage('ONECATALOG_PS_PROMO_GROUP') ?></td>
            <td><select name="B2B_PROMO_GROUP">
                <option value="0"><?= Loc::getMessage('ONECATALOG_PS_PROMO_OFF') ?></option>
                <?php foreach ($priceGroups as $gid => $gname): ?>
                    <option value="<?= $gid ?>"<?= (int) Settings::b2bPromoGroupId() === $gid ? ' selected' : '' ?>><?= htmlspecialcharsbx($gname) ?></option>
                <?php endforeach; ?>
            </select></td></tr>
        <tr><td><?= Loc::getMessage('ONECATALOG_PS_CURRENCY') ?></td>
            <td><input type="text" size="8" name="B2B_CURRENCY" value="<?= htmlspecialcharsbx(Settings::b2bCurrency()) ?>"></td></tr>
        <tr><td><?= Loc::getMessage('ONECATALOG_PS_OPTS') ?></td>
            <td>
                <label><input type="checkbox" name="B2B_PROMO_AS_SALE" value="Y"<?= Settings::bool('B2B_PROMO_AS_SALE', true) ? ' checked' : '' ?>> <?= Loc::getMessage('ONECATALOG_PS_PROMO') ?></label><br>
                <label><input type="checkbox" name="B2B_USE_STORES" value="Y"<?= Settings::b2bUseStores() ? ' checked' : '' ?>> <?= Loc::getMessage('ONECATALOG_PS_USE_STORES') ?></label><br>
                <label><input type="checkbox" name="B2B_DECIMAL_STOCK" value="Y"<?= Settings::bool('B2B_DECIMAL_STOCK', true) ? ' checked' : '' ?>> <?= Loc::getMessage('ONECATALOG_PS_DECIMAL') ?></label><br>
                <label><input type="checkbox" name="B2B_NOTIFY" value="Y"<?= Settings::b2bNotifyEnabled() ? ' checked' : '' ?>> <?= Loc::getMessage('ONECATALOG_PS_NOTIFY') ?></label><br>
                <?= Loc::getMessage('ONECATALOG_PS_PAGE') ?>: <input type="number" name="B2B_PAGE_SIZE" min="50" max="500" value="<?= (int) Settings::b2bPageSize() ?>" style="width:80px">
            </td></tr>
    </table>
    <?php if ($canWrite): ?>
        <input type="submit" name="save" class="adm-btn-save" value="<?= Loc::getMessage('ONECATALOG_PS_SAVE') ?>">
    <?php endif; ?>
</form>

<hr>
<h4><?= Loc::getMessage('ONECATALOG_PS_RUN') ?></h4>
<p><button type="button" class="adm-btn adm-btn-save" id="oc-ps-sync"<?= Settings::b2bConfigured() ? '' : ' disabled' ?>><?= Loc::getMessage('ONECATALOG_PS_SYNC_NOW') ?></button></p>
<div id="oc-ps-progress" style="font-weight:bold;margin:8px 0"></div>
<pre id="oc-ps-log" style="max-height:300px;overflow:auto;background:#f7f7f7;border:1px solid #ddd;padding:8px"></pre>

<script>
(function () {
    var sessid = '<?= bitrix_sessid() ?>';
    var url = '<?= $APPLICATION->GetCurPage() ?>';
    var btn = document.getElementById('oc-ps-sync');
    var prog = document.getElementById('oc-ps-progress');
    var logEl = document.getElementById('oc-ps-log');
    var M = {
        running: '<?= CUtil::JSEscape(Loc::getMessage('ONECATALOG_PS_RUNNING')) ?>',
        done: '<?= CUtil::JSEscape(Loc::getMessage('ONECATALOG_PS_DONE')) ?>',
        err: '<?= CUtil::JSEscape(Loc::getMessage('ONECATALOG_PS_ERR')) ?>'
    };
    var totalScanned = 0, totalChanged = 0;
    function renderLog(log) {
        logEl.textContent = (log || []).map(function (e) { return '[' + e.status + '] ' + (e.key || '') + (e.message ? ' — ' + e.message : ''); }).join('\n');
    }
    function step(start) {
        var body = 'ajax=Y&act=syncpage&sessid=' + encodeURIComponent(sessid) + '&start=' + start;
        fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) { prog.textContent = M.err + ': ' + d.error; btn.disabled = false; return; }
                totalScanned += d.scanned || 0; totalChanged += d.changed || 0;
                if (d.log) renderLog(d.log);
                prog.textContent = (d.more ? M.running : M.done) + ' ' + totalScanned + (d.total ? '/' + d.total : '') + ' — ' + totalChanged;
                if (d.more) step(d.next); else btn.disabled = false;
            })
            .catch(function () { prog.textContent = M.err; btn.disabled = false; });
    }
    if (btn) btn.addEventListener('click', function () { btn.disabled = true; totalScanned = 0; totalChanged = 0; prog.textContent = M.running + ' 0'; step(0); });
})();

// Drag-and-drop приоритетов (нативный HTML5, без зависимостей).
(function () {
    var dragged = null;
    document.querySelectorAll('.oc-sortable').forEach(function (ul) {
        ul.addEventListener('dragstart', function (e) {
            if (e.target.tagName === 'LI') { dragged = e.target; e.target.style.opacity = '0.4'; }
        });
        ul.addEventListener('dragend', function (e) {
            if (e.target.tagName === 'LI') { e.target.style.opacity = ''; dragged = null; }
        });
        ul.addEventListener('dragover', function (e) {
            e.preventDefault();
            var li = e.target.closest('li');
            if (!li || li === dragged || !dragged || li.parentNode !== ul) { return; }
            var rect = li.getBoundingClientRect();
            var after = (e.clientY - rect.top) > rect.height / 2;
            ul.insertBefore(dragged, after ? li.nextSibling : li);
        });
    });
})();
</script>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
