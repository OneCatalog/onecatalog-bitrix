# OneCatalog Import — 1С-Битрикс

Модуль импорта каталога из [OneCatalog](https://docs.onecatalog.net/) (Wiki API) в
**1С-Битрикс** (инфоблоки `iblock` + торговый каталог `catalog`).

- **Стандарт интеграции (канон):** [OneCatalog/onecatalog-standard](https://github.com/OneCatalog/onecatalog-standard) — соответствует стандарту **v1.0**
- **Эталонная реализация:** [OneCatalog/onecatalog-woocommerce](https://github.com/OneCatalog/onecatalog-woocommerce)
- **План порта и ответы:** [docs/integration-plan.md](docs/integration-plan.md) · [docs/integration-answers.md](docs/integration-answers.md)
- **История изменений:** [CHANGELOG.md](CHANGELOG.md) · текущая версия — **0.1.0** (в разработке)

> ⚠️ Статус: **ранняя разработка**. Готов фундамент (манифест, установка, слои
> `Api`/`Units`/`Settings`); импортёр товаров, медиа, очередь и UI — в работе.

## Установка

Модуль — папка `onecatalog.import/`. Скопировать её в `/bitrix/modules/` целевого
сайта и установить через **Marketplace → Установленные решения** (или
`Настройки → Модули`). Требуются установленные модули **iblock** и **catalog** —
без них установка корректно сообщит об ошибке (не упадёт).

```
onecatalog.import/            ← копировать в /bitrix/modules/
├── install/                  манифест (CModule), version.php, таблицы oc_queue/oc_media
├── include.php               автозагрузка классов (Loader::registerAutoLoadClasses)
├── lib/                      слои (namespace OneCatalog\Import)
│   ├── api.php               клиент Wiki API (HttpClient, base/token/lang, обёртка)
│   ├── units.php             конвертер единиц (вес→г, размеры→мм)
│   ├── settings.php          опции + валидация/кламп (Option)
│   ├── media.php             изображения: размер/скачивание/дедуп/качество  [TODO]
│   ├── taxonomies.php        свойства-списки/HL, термины, разделы           [TODO]
│   ├── *importer.php         коллекция/бренд/страна/товар (оркестратор)     [TODO]
│   └── queue.php             фоновая очередь (AJAX-степпер + агент)         [TODO]
├── admin/                    страницы настроек/маппинга/импорта            [TODO]
├── ajax/                     контроллер приёма public_id + статус          [TODO]
└── lang/{ru,en}/             языковые файлы
```

## Ключевые решения (из [плана](docs/integration-plan.md) и ответов)

| Решение | Выбор | Почему |
|---|---|---|
| Module ID | `onecatalog.import` | vendor=onecatalog, в духе WP-namespace `OneCatalog\Import` |
| Ключ идемпотентности | свойство **`OC_PUBLIC_ID`** (не `XML_ID`) | страховка от обмена 1С (CommerceML перезаписывает `XML_ID`) |
| Артикул | свойство `ARTICLE` = `article` | ≠ public_id (§5.2); пуст — не заполняем |
| Бренд по умолчанию | свойство-список (L) | HL требует модуль `highloadblock` → opt-in |
| Коллекции | отдельный инфоблок (дефолт) / раздел / свойство | «тип объекта + цель» (§7) |
| Очередь | AJAX-степпер (primary) + агент (фолбэк) | агенты без cron непредсказуемы |
| API | D7 (`Bitrix\Main`) + `CIBlock*`/`CCatalog*` | HTTP/ORM/настройки на D7, CRUD каталога — legacy |
| Цена | не задаётся | в Wiki API цен нет (§5.6); сайт через событие |
| Габариты | `WEIGHT` (г) / `WIDTH·LENGTH·HEIGHT` (мм) | внутренние единицы Битрикса = г/мм |

## Требования

- 1С-Битрикс с модулями **iblock** и **catalog** (редакция «Малый бизнес»+).
- **PHP 8.0+** (конструкторные property promotion, типы).
- Для дерева категорий используется `parent_category` из `/categories/`.

## Лицензия

[GPLv2](LICENSE).
