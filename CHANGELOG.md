# История изменений — OneCatalog Import (1С-Битрикс)

Формат основан на [Keep a Changelog](https://keepachangelog.com/ru/1.0.0/),
нумерация версий — по [семантическому версионированию](https://semver.org/lang/ru/).

Версия задаётся в `onecatalog.import/install/version.php`. Соответствие стандарту
интеграции: **v1.0** (см. [onecatalog-standard](https://github.com/OneCatalog/onecatalog-standard)).

## [Не выпущено] — бэклог

### Реализовано на `dev` (ядро импорта одного товара, §0 шаг 1)
- **`Taxonomies`**: find-or-create свойств инфоблока (`L`/`N`/`S`) по стабильному
  коду; enum по стабильному XML_ID и по VALUE (label, регистронезависимо, §5.1);
  разделы-категории с деревом и фолбэком по имени.
- **`ProductImporter`**: импорт одного товара по `public_id` —
  идемпотентность по свойству `OC_PUBLIC_ID` (не `XML_ID`); `OC_ARTICLE`=`article`
  отдельно (§5.2); название/`CODE`/`ACTIVE` (статус и CODE только при создании, §5.6);
  категории-дерево по `parent_category`; характеристики text→список,
  boolean→Да/Нет (false по умолчанию, §5.6), numeric→свойство-число; габариты через
  `Units` → `CCatalogProduct` (вес г / размеры мм); **цена не задаётся** (§5.6);
  события `OnBeforeImportProduct` (фильтр payload) и `OnAfterProductImported`
  (полный payload — сайтовый слой §8).
- Построение цепочки категорий вынесено в чистую `buildCategoryChain()` — покрыто
  тестом на реальном fixture (`categories.json`), включая защиту от циклов.

### Реализовано на `dev` (UI: picker + импорт по списку, §2.4, §6)
- **Виджет-пикер OneCatalog** (`picker-loader.js`): iframe на `tools.onecatalog.net`,
  выбор через `postMessage` по `productPublicIds`; **origin зафиксирован настройкой**
  `PICKER_BASE` (дефолт `https://tools.onecatalog.net`) и проверяется на каждое
  сообщение (`event.origin`). Fallback — импорт по вставленному списку `public_id`.
- **AJAX-степпер** (`admin-import.js` + ветка `ajax=Y` admin-страницы): режет
  выбор на порции по «шагу импорта», шлёт последовательно с `check_bitrix_sessid`
  и проверкой прав (≥W), показывает прогресс и лог.
- **`Queue::importBatch()`**: синхронный импорт порции через `ProductImporter`,
  деградация без падений (битый id не валит порцию), лог последних 100 результатов.
- **Админка**: страница «Импорт товаров» (picker + список + прогресс), страница
  «Настройки» (токен, базовый URL, origin picker'а, язык, инфоблок, шаг, статус
  новых, флаги импорта), пункт меню; обёртки `/bitrix/admin` и JS `/bitrix/js`
  копируются при установке. Настройка `PICKER_BASE`.

### Запланировано (следующие инкременты)
- `Media`: скачивание + MIME, обложка/галерея, трекинг качества и дедуп
  (таблица `onecatalog_media`, ключ `sha1(path#size)`).
- `Queue` (агент `CAgent`): фоновый фолбэк через таблицу `onecatalog_queue`.
- Коллекция/бренд/страна: адаптеры «тип объекта + цель».
- Ручной маппинг характеристик (строгий режим) по `specification_id`.

## [0.1.0] — 2026-06-16

### Добавлено
- **Фундамент модуля** `onecatalog.import`:
  - манифест/установка (`install/index.php`): проверка зависимостей `iblock`+`catalog`,
    создание таблиц `onecatalog_queue`/`onecatalog_media`, дефолты опций, регистрация
    агента очереди.
  - автозагрузка классов (`include.php`, namespace `OneCatalog\Import`).
  - `Api` — клиент Wiki API на D7 `HttpClient`: base/token/lang, обёртка `{data,success}`,
    справочники одним запросом (`limit=1000`) с дочиткой по `meta.counts`, ошибки → `null`.
  - `Units` — конвертер единиц (вес→граммы, размеры→миллиметры) с нормализацией
    локализованных подписей; покрыт тестом.
  - `Settings` — опции поверх `Option`: приоритет константы над токеном, кламп шага ≥10,
    язык только из списка.
  - языковые файлы `lang/ru` + `lang/en` для установки.
- Каркасы слоёв `Media`/`Taxonomies`/`*Importer`/`Queue` с сигнатурами и планом.
- Документация: план порта и ответы в `docs/`.
