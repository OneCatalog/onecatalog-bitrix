<?php
/**
 * Автозагрузка классов модуля onecatalog.import.
 *
 * Namespace: OneCatalog\Import — зеркало слоёв эталона (см. INTEGRATION-STANDARD.md §4):
 *   Api, Units, Media, Taxonomies, *Importer, Queue, Settings.
 */

use Bitrix\Main\Loader;

Loader::registerAutoLoadClasses('onecatalog.import', [
    'OneCatalog\\Import\\Api'               => 'lib/api.php',
    'OneCatalog\\Import\\Units'             => 'lib/units.php',
    'OneCatalog\\Import\\Settings'          => 'lib/settings.php',
    'OneCatalog\\Import\\Media'             => 'lib/media.php',
    'OneCatalog\\Import\\Taxonomies'        => 'lib/taxonomies.php',
    'OneCatalog\\Import\\CollectionImporter'=> 'lib/collectionimporter.php',
    'OneCatalog\\Import\\BrandImporter'     => 'lib/brandimporter.php',
    'OneCatalog\\Import\\CountryImporter'   => 'lib/countryimporter.php',
    'OneCatalog\\Import\\ProductImporter'   => 'lib/productimporter.php',
    'OneCatalog\\Import\\Queue'             => 'lib/queue.php',
    'OneCatalog\\Import\\B2bApi'            => 'lib/b2bapi.php',
    'OneCatalog\\Import\\PriceStockSync'    => 'lib/pricestocksync.php',
]);
