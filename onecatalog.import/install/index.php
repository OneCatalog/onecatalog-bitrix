<?php

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

Loc::loadMessages(__FILE__);

if (class_exists('onecatalog_import')) {
    return;
}

/**
 * Манифест модуля OneCatalog Import (1С-Битрикс).
 *
 * Слой "Plugin" стандарта (§4): сборка, жизненный цикл, миграции.
 * Зависимость от движка магазина (§10): без iblock+catalog установка не падает,
 * а корректно сообщает об ошибке.
 */
class onecatalog_import extends CModule
{
    public $MODULE_ID = 'onecatalog.import';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $MODULE_GROUP_RIGHTS = 'Y';
    public $PARTNER_NAME;
    public $PARTNER_URI = 'https://onecatalog.net';

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';

        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME = Loc::getMessage('ONECATALOG_IMPORT_MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('ONECATALOG_IMPORT_MODULE_DESC');
        $this->PARTNER_NAME = Loc::getMessage('ONECATALOG_IMPORT_PARTNER_NAME');
    }

    public function DoInstall()
    {
        global $APPLICATION;

        // Зависимость от движка магазина (§10): iblock + catalog обязательны.
        if (!$this->checkDependencies()) {
            $APPLICATION->ThrowException(Loc::getMessage('ONECATALOG_IMPORT_ERR_DEPENDENCIES'));
            return false;
        }

        ModuleManager::registerModule($this->MODULE_ID);

        $this->InstallDB();
        $this->InstallEvents();
        $this->InstallFiles();
        $this->setDefaultOptions();

        // Фоновый агент-обработчик очереди (фолбэк к AJAX-степперу, §6).
        \CAgent::AddAgent(
            "\\OneCatalog\\Import\\Queue::agent();",
            $this->MODULE_ID,
            'N',
            300, // каждые 5 минут
            '',
            'Y'
        );

        $APPLICATION->IncludeAdminFile(
            Loc::getMessage('ONECATALOG_IMPORT_INSTALL_TITLE'),
            __DIR__ . '/step.php'
        );

        return true;
    }

    public function DoUninstall()
    {
        global $APPLICATION;

        $keepData = ($_REQUEST['savedata'] ?? 'Y') === 'Y';

        \CAgent::RemoveModuleAgents($this->MODULE_ID);
        $this->UnInstallEvents();
        $this->UnInstallFiles();

        if (!$keepData) {
            $this->UnInstallDB();
            $this->deleteOptions();
        }

        ModuleManager::unRegisterModule($this->MODULE_ID);

        $APPLICATION->IncludeAdminFile(
            Loc::getMessage('ONECATALOG_IMPORT_UNINSTALL_TITLE'),
            __DIR__ . '/unstep.php'
        );

        return true;
    }

    /**
     * Таблицы трекинга очереди (oc_queue) и медиа/дедупа (oc_media) — §5.3, §6.
     * У CFile нет места под мету → своя таблица обязательна (блокер 6).
     */
    public function InstallDB()
    {
        $connection = Application::getConnection();

        if (!$connection->isTableExists('onecatalog_queue')) {
            $connection->queryExecute(
                "CREATE TABLE onecatalog_queue (
                    ID int(11) NOT NULL AUTO_INCREMENT,
                    BATCH int(11) NOT NULL DEFAULT 0,
                    PUBLIC_ID varchar(64) NOT NULL,
                    STATUS varchar(16) NOT NULL DEFAULT 'pending',
                    MESSAGE text NULL,
                    CREATED_AT datetime NOT NULL,
                    UPDATED_AT datetime NULL,
                    PRIMARY KEY (ID),
                    INDEX ix_oc_queue_status (STATUS),
                    INDEX ix_oc_queue_public (PUBLIC_ID)
                )"
            );
        }

        if (!$connection->isTableExists('onecatalog_media')) {
            $connection->queryExecute(
                "CREATE TABLE onecatalog_media (
                    ID int(11) NOT NULL AUTO_INCREMENT,
                    CONTENT_KEY varchar(64) NOT NULL,
                    SIZE varchar(8) NOT NULL DEFAULT 'min',
                    FILE_ID int(11) NOT NULL,
                    SHARED char(1) NOT NULL DEFAULT 'N',
                    CREATED_AT datetime NOT NULL,
                    PRIMARY KEY (ID),
                    UNIQUE INDEX ux_oc_media_key (CONTENT_KEY)
                )"
            );
        }

        return true;
    }

    public function UnInstallDB()
    {
        $connection = Application::getConnection();
        foreach (['onecatalog_queue', 'onecatalog_media'] as $table) {
            if ($connection->isTableExists($table)) {
                $connection->dropTable($table);
            }
        }
        return true;
    }

    public function InstallEvents()
    {
        return true;
    }

    public function UnInstallEvents()
    {
        return true;
    }

    public function InstallFiles()
    {
        // Admin-страницы (обёртки) → /bitrix/admin; JS виджета/степпера → /bitrix/js.
        CopyDirFiles(__DIR__ . '/admin', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin', true, true);
        CopyDirFiles(__DIR__ . '/js', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/js', true, true);
        return true;
    }

    public function UnInstallFiles()
    {
        DeleteDirFiles(__DIR__ . '/admin', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin');
        DeleteDirFiles(__DIR__ . '/js', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/js');
        return true;
    }

    private function checkDependencies(): bool
    {
        return ModuleManager::isModuleInstalled('iblock')
            && ModuleManager::isModuleInstalled('catalog');
    }

    /**
     * Дефолты настроек (§7). Кламп/валидация — в слое Settings.
     */
    private function setDefaultOptions(): void
    {
        $defaults = [
            'API_BASE_URL'          => 'https://api.onecatalog.net/wiki/v1',
            'API_TOKEN'             => '',
            'PICKER_BASE'           => 'https://tools.onecatalog.net',
            'LANG'                  => 'en',
            'STEP'                  => '10',
            'NEW_ACTIVE'            => 'Y',
            'IMPORT_COLLECTIONS'    => 'Y',
            'COLLECTION_TARGET_TYPE'=> 'iblock',
            'IMPORT_BRAND'          => 'Y',
            'BRAND_TARGET_TYPE'     => 'list',   // дефолт — список (L), не HL (в.10)
            'IMPORT_COUNTRY'        => 'N',
            'COUNTRY_TARGET_TYPE'   => 'list',
            'IMPORT_TAGS'           => 'N',
            'SPEC_MANUAL_MAPPING'   => 'N',
            'CATALOG_IBLOCK_ID'     => '',
        ];
        foreach ($defaults as $name => $value) {
            if (Option::get('onecatalog.import', $name, null) === null) {
                Option::set('onecatalog.import', $name, $value);
            }
        }
    }

    private function deleteOptions(): void
    {
        Option::delete('onecatalog.import');
    }
}
