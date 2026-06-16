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

$right = $APPLICATION->GetGroupRight($moduleId);
if ($right < 'R' && !$USER->IsAdmin()) {
    $APPLICATION->AuthForm(Loc::getMessage('ACCESS_DENIED'));
}
$canWrite = ($right >= 'W') || $USER->IsAdmin();

// Поля настроек, выставляемые на странице.
$fields = [
    'API_TOKEN', 'API_BASE_URL', 'PICKER_BASE', 'LANG', 'STEP',
    'NEW_ACTIVE', 'CATALOG_IBLOCK_ID',
    'IMPORT_COLLECTIONS', 'IMPORT_BRAND', 'IMPORT_COUNTRY', 'IMPORT_TAGS',
];

$message = null;
if ($canWrite && $_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $input = [];
    foreach ($fields as $f) {
        if (in_array($f, ['IMPORT_COLLECTIONS', 'IMPORT_BRAND', 'IMPORT_COUNTRY', 'IMPORT_TAGS'], true)) {
            $input[$f] = isset($_POST[$f]) ? 'Y' : 'N';
        } else {
            $input[$f] = (string) ($_POST[$f] ?? '');
        }
    }
    $clean = Settings::sanitize($input);
    foreach ($clean as $name => $value) {
        Settings::set($name, $value);
    }
    $message = new CAdminMessage([
        'TYPE'    => 'OK',
        'MESSAGE' => Loc::getMessage('ONECATALOG_SAVED'),
    ]);
}

$val = static fn(string $name, $default = '') => htmlspecialcharsbx((string) Settings::get($name, $default));

// Список инфоблоков для выбора целевого каталога.
$iblocks = [];
if (Loader::includeModule('iblock')) {
    $rs = CIBlock::GetList(['IBLOCK_TYPE' => 'ASC', 'NAME' => 'ASC'], ['CHECK_PERMISSIONS' => 'N']);
    while ($row = $rs->Fetch()) {
        $iblocks[(int) $row['ID']] = '[' . $row['ID'] . '] ' . $row['NAME'] . ' (' . $row['IBLOCK_TYPE_ID'] . ')';
    }
}

$APPLICATION->SetTitle(Loc::getMessage('ONECATALOG_SETTINGS_TITLE'));
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if ($message) {
    echo $message->Show();
}

$tabControl = new CAdminTabControl('tabControl', [
    ['DIV' => 'connect', 'TAB' => Loc::getMessage('ONECATALOG_TAB_CONNECT'), 'TITLE' => Loc::getMessage('ONECATALOG_TAB_CONNECT')],
    ['DIV' => 'import',  'TAB' => Loc::getMessage('ONECATALOG_TAB_IMPORT'),  'TITLE' => Loc::getMessage('ONECATALOG_TAB_IMPORT')],
]);

$curLang = (string) Settings::get('LANG', 'en');
$newActive = (string) Settings::get('NEW_ACTIVE', 'Y');
$curIblock = (int) Settings::get('CATALOG_IBLOCK_ID', 0);
?>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?lang=<?= LANGUAGE_ID ?>">
    <?= bitrix_sessid_post() ?>
    <?php $tabControl->Begin(); ?>

    <?php $tabControl->BeginNextTab(); ?>
    <tr>
        <td width="40%"><?= Loc::getMessage('ONECATALOG_F_TOKEN') ?></td>
        <td><input type="text" size="50" name="API_TOKEN" value="<?= $val('API_TOKEN') ?>"></td>
    </tr>
    <tr>
        <td><?= Loc::getMessage('ONECATALOG_F_BASE') ?></td>
        <td><input type="text" size="50" name="API_BASE_URL" value="<?= $val('API_BASE_URL', Settings::DEFAULT_BASE) ?>"></td>
    </tr>
    <tr>
        <td><?= Loc::getMessage('ONECATALOG_F_PICKER') ?></td>
        <td><input type="text" size="50" name="PICKER_BASE" value="<?= $val('PICKER_BASE', Settings::DEFAULT_PICKER) ?>"></td>
    </tr>
    <tr>
        <td><?= Loc::getMessage('ONECATALOG_F_LANG') ?></td>
        <td>
            <select name="LANG">
                <?php foreach (Settings::SUPPORTED_LANGS as $l): ?>
                    <option value="<?= $l ?>"<?= $l === $curLang ? ' selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>

    <?php $tabControl->BeginNextTab(); ?>
    <tr>
        <td width="40%"><?= Loc::getMessage('ONECATALOG_F_IBLOCK') ?></td>
        <td>
            <select name="CATALOG_IBLOCK_ID">
                <option value="0"><?= Loc::getMessage('ONECATALOG_NOT_SELECTED') ?></option>
                <?php foreach ($iblocks as $id => $name): ?>
                    <option value="<?= $id ?>"<?= $id === $curIblock ? ' selected' : '' ?>><?= htmlspecialcharsbx($name) ?></option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>
    <tr>
        <td><?= Loc::getMessage('ONECATALOG_F_STEP') ?></td>
        <td><input type="number" min="10" name="STEP" value="<?= (int) Settings::step() ?>"></td>
    </tr>
    <tr>
        <td><?= Loc::getMessage('ONECATALOG_F_NEW_ACTIVE') ?></td>
        <td>
            <select name="NEW_ACTIVE">
                <option value="Y"<?= $newActive !== 'N' ? ' selected' : '' ?>><?= Loc::getMessage('ONECATALOG_ACTIVE_Y') ?></option>
                <option value="N"<?= $newActive === 'N' ? ' selected' : '' ?>><?= Loc::getMessage('ONECATALOG_ACTIVE_N') ?></option>
            </select>
        </td>
    </tr>
    <tr>
        <td><?= Loc::getMessage('ONECATALOG_F_COLLECTIONS') ?></td>
        <td><input type="checkbox" name="IMPORT_COLLECTIONS" value="Y"<?= Settings::bool('IMPORT_COLLECTIONS', true) ? ' checked' : '' ?>></td>
    </tr>
    <tr>
        <td><?= Loc::getMessage('ONECATALOG_F_BRAND') ?></td>
        <td><input type="checkbox" name="IMPORT_BRAND" value="Y"<?= Settings::bool('IMPORT_BRAND', true) ? ' checked' : '' ?>></td>
    </tr>
    <tr>
        <td><?= Loc::getMessage('ONECATALOG_F_COUNTRY') ?></td>
        <td><input type="checkbox" name="IMPORT_COUNTRY" value="Y"<?= Settings::bool('IMPORT_COUNTRY', false) ? ' checked' : '' ?>></td>
    </tr>
    <tr>
        <td><?= Loc::getMessage('ONECATALOG_F_TAGS') ?></td>
        <td><input type="checkbox" name="IMPORT_TAGS" value="Y"<?= Settings::bool('IMPORT_TAGS', false) ? ' checked' : '' ?>></td>
    </tr>

    <?php
    $tabControl->Buttons(['btnApply' => false, 'btnCancel' => false]);
    $tabControl->End();
    ?>
</form>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
