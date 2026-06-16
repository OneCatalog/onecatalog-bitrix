# История изменений — OneCatalog Import (1С-Битрикс)

Формат основан на [Keep a Changelog](https://keepachangelog.com/ru/1.0.0/),
нумерация версий — по [семантическому версионированию](https://semver.org/lang/ru/).

Версия задаётся в `onecatalog.import/install/version.php`. Соответствие стандарту
интеграции: **v1.0** (см. [onecatalog-standard](https://github.com/OneCatalog/onecatalog-standard)).

## [Не выпущено] — бэклог

### Запланировано (следующие инкременты, порядок по стандарту §0)
- `ProductImporter`: импорт одного товара по `public_id` (find-by-`OC_PUBLIC_ID`
  → Add/Update, базовые поля, габариты через `Units`).
- `Taxonomies`: свойства-списки/enum (find-or-create по label), категории-разделы
  с деревом по `parent_category`, boolean→Да/Нет, numeric→свойство N.
- `Media`: скачивание + MIME, обложка/галерея, трекинг качества и дедуп
  (таблица `onecatalog_media`, ключ `sha1(path#size)`).
- `Queue`: AJAX-степпер + агент, таблица `onecatalog_queue`, лог.
- `ajax/import.php`: приём списка `public_id` + статус (`check_bitrix_sessid`).
- `admin/`: страницы настроек, ручного маппинга характеристик, импорта/пикера.
- Коллекция/бренд/страна: адаптеры «тип объекта + цель».
- Событие `OnAfterProductImported` (полный payload — сайтовый слой §8).

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
