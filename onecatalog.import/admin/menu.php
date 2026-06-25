<?php

use Bitrix\Main\Localization\Loc;

if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true) {
    die();
}
Loc::loadMessages(__FILE__);

global $APPLICATION, $USER;
$moduleId = 'onecatalog.import';
if (!$USER->IsAdmin() && $APPLICATION->GetGroupRight($moduleId) < 'R') {
    return null;
}

return [
    'parent_menu' => 'global_menu_content',
    'sort'        => 500,
    'text'        => Loc::getMessage('ONECATALOG_MENU_ROOT'),
    'title'       => Loc::getMessage('ONECATALOG_MENU_ROOT'),
    'icon'        => 'iblock_menu_icon',
    'page_icon'   => 'iblock_page_icon',
    'items_id'    => 'menu_onecatalog_import',
    'items'       => [
        [
            'text'  => Loc::getMessage('ONECATALOG_MENU_IMPORT'),
            'url'   => 'onecatalog_import.php?lang=' . LANGUAGE_ID,
            'title' => Loc::getMessage('ONECATALOG_MENU_IMPORT'),
        ],
        [
            'text'  => Loc::getMessage('ONECATALOG_MENU_IMPORT_LOG'),
            'url'   => 'onecatalog_import_log.php?lang=' . LANGUAGE_ID,
            'title' => Loc::getMessage('ONECATALOG_MENU_IMPORT_LOG'),
        ],
        [
            'text'  => Loc::getMessage('ONECATALOG_MENU_PRICESTOCK'),
            'url'   => 'onecatalog_pricestock.php?lang=' . LANGUAGE_ID,
            'title' => Loc::getMessage('ONECATALOG_MENU_PRICESTOCK'),
        ],
        [
            'text'  => Loc::getMessage('ONECATALOG_MENU_B2B_LOG'),
            'url'   => 'onecatalog_b2b_log.php?lang=' . LANGUAGE_ID,
            'title' => Loc::getMessage('ONECATALOG_MENU_B2B_LOG'),
        ],
        [
            'text'  => Loc::getMessage('ONECATALOG_MENU_STAGING'),
            'url'   => 'onecatalog_b2b_staging.php?lang=' . LANGUAGE_ID,
            'title' => Loc::getMessage('ONECATALOG_MENU_STAGING'),
        ],
        [
            'text'  => Loc::getMessage('ONECATALOG_MENU_MAPPING'),
            'url'   => 'onecatalog_mapping.php?lang=' . LANGUAGE_ID,
            'title' => Loc::getMessage('ONECATALOG_MENU_MAPPING'),
        ],
        [
            'text'  => Loc::getMessage('ONECATALOG_MENU_SETTINGS'),
            'url'   => 'onecatalog_settings.php?lang=' . LANGUAGE_ID,
            'title' => Loc::getMessage('ONECATALOG_MENU_SETTINGS'),
        ],
    ],
];
