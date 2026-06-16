<?php

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

/** @var CMain $APPLICATION */
/** @var CUser $USER */

$moduleId = 'onecatalog.import';
Loc::loadMessages(__FILE__);
Loader::includeModule($moduleId);

use OneCatalog\Import\Settings;
use OneCatalog\Import\Api;

$right = $APPLICATION->GetGroupRight($moduleId);
if ($right < 'R' && !$USER->IsAdmin()) {
    $APPLICATION->AuthForm(Loc::getMessage('ACCESS_DENIED'));
}
$canWrite = ($right >= 'W') || $USER->IsAdmin();

$iblockId = Settings::catalogIblockId();

// Список характеристик OneCatalog (одним запросом, limit=1000 — §2.1).
$specs = [];
foreach ((new Api())->specifications() as $s) {
    $id = (int) ($s['id'] ?? 0);
    if ($id <= 0) {
        continue;
    }
    $specs[$id] = [
        'id'    => $id,
        'label' => (string) ($s['label'] ?? $s['menutitle'] ?? $s['name'] ?? $s['title'] ?? ('#' . $id)),
        'type'  => (string) ($s['type'] ?? $s['specification_type'] ?? 'text'),
    ];
}

// Свойства целевого инфоблока (цели маппинга).
$props = [];
if ($iblockId > 0 && Loader::includeModule('iblock')) {
    $rs = CIBlockProperty::GetList(['SORT' => 'ASC', 'NAME' => 'ASC'], ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y']);
    while ($row = $rs->Fetch()) {
        $props[(int) $row['ID']] = '[' . $row['ID'] . '] ' . $row['NAME'] . ' (' . $row['PROPERTY_TYPE'] . ')';
    }
}

$message = null;
if ($canWrite && $_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    Settings::set('SPEC_MANUAL_MAPPING', isset($_POST['SPEC_MANUAL_MAPPING']) ? 'Y' : 'N');

    $map = [];
    foreach ($specs as $id => $spec) {
        $propId = (int) ($_POST['map'][$id]['prop'] ?? 0);
        if ($propId <= 0) {
            continue; // не сопоставлено
        }
        $entry = ['prop' => $propId];
        if ($spec['type'] === 'boolean') {
            $entry['true'] = trim((string) ($_POST['map'][$id]['true'] ?? ''));
            $entry['false'] = trim((string) ($_POST['map'][$id]['false'] ?? ''));
        }
        $map[(string) $id] = $entry;
    }
    Settings::setSpecMap($map);
    $message = new CAdminMessage(['TYPE' => 'OK', 'MESSAGE' => Loc::getMessage('ONECATALOG_MAP_SAVED')]);
}

$manualOn = Settings::manualMapping();
$map = Settings::specMap();

$APPLICATION->SetTitle(Loc::getMessage('ONECATALOG_MAP_TITLE'));
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if ($message) {
    echo $message->Show();
}
if ($iblockId <= 0) {
    echo CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => Loc::getMessage('ONECATALOG_NO_IBLOCK'), 'HTML' => true]);
}
?>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?lang=<?= LANGUAGE_ID ?>">
    <?= bitrix_sessid_post() ?>
    <p>
        <label>
            <input type="checkbox" name="SPEC_MANUAL_MAPPING" value="Y"<?= $manualOn ? ' checked' : '' ?>>
            <?= Loc::getMessage('ONECATALOG_MAP_ENABLE') ?>
        </label>
    </p>
    <p class="adm-info-message"><?= Loc::getMessage('ONECATALOG_MAP_HINT') ?></p>

    <table class="adm-list-table" style="width:auto">
        <thead>
            <tr class="adm-list-table-header">
                <td class="adm-list-table-cell"><?= Loc::getMessage('ONECATALOG_MAP_SPEC') ?></td>
                <td class="adm-list-table-cell"><?= Loc::getMessage('ONECATALOG_MAP_TYPE') ?></td>
                <td class="adm-list-table-cell"><?= Loc::getMessage('ONECATALOG_MAP_PROP') ?></td>
                <td class="adm-list-table-cell"><?= Loc::getMessage('ONECATALOG_MAP_BOOL') ?></td>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($specs as $id => $spec): $cur = $map[(string) $id] ?? []; ?>
            <tr class="adm-list-table-row">
                <td class="adm-list-table-cell"><?= htmlspecialcharsbx($spec['label']) ?> <span style="color:#888">#<?= $id ?></span></td>
                <td class="adm-list-table-cell"><?= htmlspecialcharsbx($spec['type']) ?></td>
                <td class="adm-list-table-cell">
                    <select name="map[<?= $id ?>][prop]">
                        <option value="0"><?= Loc::getMessage('ONECATALOG_MAP_NONE') ?></option>
                        <?php foreach ($props as $pid => $pname): ?>
                            <option value="<?= $pid ?>"<?= (int) ($cur['prop'] ?? 0) === $pid ? ' selected' : '' ?>><?= htmlspecialcharsbx($pname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td class="adm-list-table-cell">
                    <?php if ($spec['type'] === 'boolean'): ?>
                        <?= Loc::getMessage('ONECATALOG_MAP_TRUE') ?>:
                        <input type="text" size="10" name="map[<?= $id ?>][true]" value="<?= htmlspecialcharsbx((string) ($cur['true'] ?? '')) ?>">
                        <?= Loc::getMessage('ONECATALOG_MAP_FALSE') ?>:
                        <input type="text" size="10" name="map[<?= $id ?>][false]" value="<?= htmlspecialcharsbx((string) ($cur['false'] ?? '')) ?>">
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$specs): ?>
            <tr><td class="adm-list-table-cell" colspan="4"><?= Loc::getMessage('ONECATALOG_MAP_NO_SPECS') ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($canWrite): ?>
        <p><input type="submit" class="adm-btn-save" value="<?= Loc::getMessage('ONECATALOG_MAP_SAVE') ?>"></p>
    <?php endif; ?>
</form>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
