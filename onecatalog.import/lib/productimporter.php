<?php

namespace OneCatalog\Import;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

Loc::loadMessages(__FILE__);

/**
 * Слой ProductImporter (§4, §5.4): оркестратор импорта одного товара.
 *
 * Строгий порядок зависимостей (§5.4):
 *   payload → базовые свойства (OC_PUBLIC_ID/OC_ARTICLE) → find-by-OC_PUBLIC_ID
 *           → OnBeforeImportProduct (фильтр payload, §8)
 *           → базовые поля (название; ACTIVE и CODE только при создании, §5.6)
 *           → категории (дерево по parent_category) → характеристики
 *             (text/boolean→Да-Нет/numeric; поиск по label, §5.1)
 *           → Add/Update элемента → габариты через Units → CCatalogProduct
 *           → OnAfterProductImported (полный payload — сайтовый слой §8)
 *
 * Инварианты: цена НЕ задаётся (§5.6); SKU=article отдельно от public_id (§5.2);
 * поля «только при создании» не перезатирать при апдейте (§5.6); boolean → false
 * по умолчанию (§5.6); справочники — по имени (§5.1).
 *
 * НЕ реализовано в этом инкременте (следующие слои): бренд/страна/теги/коллекция,
 * медиа (обложка/галерея), ручной маппинг характеристик.
 */
final class ProductImporter
{
    private const EVENT_MODULE = 'onecatalog.import';
    private Taxonomies $tax;
    private ?array $catMap = null;

    public function __construct(
        private Api $api,
        private int $iblockId
    ) {
        $this->tax = new Taxonomies($iblockId);
    }

    /** @return array{status:string, id?:int, message?:string} */
    public function import(string $publicId): array
    {
        if ($this->iblockId <= 0) {
            return ['status' => 'error', 'message' => 'catalog iblock not configured'];
        }
        if (!Loader::includeModule('iblock')) {
            return ['status' => 'error', 'message' => 'iblock module unavailable'];
        }

        $payload = $this->api->product($publicId);
        if (!is_array($payload)) {
            return ['status' => 'error', 'message' => 'payload not found: ' . $publicId];
        }

        // Базовые свойства должны существовать до поиска по PROPERTY_OC_PUBLIC_ID.
        $publicProp = $this->tax->ensureProperty('OC_PUBLIC_ID', Loc::getMessage('ONECATALOG_PROP_PUBLIC_ID') ?: 'OneCatalog ID', 'S');
        $articleProp = $this->tax->ensureProperty('OC_ARTICLE', Loc::getMessage('ONECATALOG_PROP_ARTICLE') ?: 'Manufacturer article', 'S');
        if (!$publicProp) {
            return ['status' => 'error', 'message' => 'cannot create OC_PUBLIC_ID property'];
        }

        $existingId = $this->findByPublicId($publicId);

        // OnBeforeImportProduct — сайт может изменить payload (§8).
        $payload = $this->filterPayload($payload, $existingId);

        // --- базовые поля ---
        $fields = [
            'IBLOCK_ID' => $this->iblockId,
            'NAME'      => (string) ($payload['menutitle'] ?? $publicId),
        ];

        $sections = $this->resolveCategorySections($payload['categories'] ?? []);
        if ($sections) {
            $fields['IBLOCK_SECTION'] = $sections;
        }

        // --- характеристики + служебные строковые свойства ---
        $propValues = $this->resolveOptions($payload['options'] ?? []);
        $propValues[$publicProp['ID']] = $publicId;
        if ($articleProp && !empty($payload['article'])) {
            // §5.2: артикул производителя отдельно от public_id; пуст — не заполняем.
            $propValues[$articleProp['ID']] = (string) $payload['article'];
        }

        // --- сохранить элемент ---
        $el = new \CIBlockElement();
        if ($existingId) {
            // Статус (ACTIVE) и CODE при апдейте НЕ трогаем (§5.6).
            $ok = $el->Update($existingId, $fields);
            if (!$ok) {
                return ['status' => 'error', 'message' => $el->LAST_ERROR ?: 'update failed'];
            }
            $id = $existingId;
        } else {
            $fields['CODE'] = $this->makeCode((string) ($payload['slug'] ?? ''), $publicId);
            $fields['ACTIVE'] = Settings::newActive(); // только при создании (§5.6)
            $id = (int) $el->Add($fields);
            if (!$id) {
                return ['status' => 'error', 'message' => $el->LAST_ERROR ?: 'add failed'];
            }
        }

        // Свойства (характеристики + public_id/article) пишем ПОСЛЕ сохранения через
        // SetPropertyValuesEx — иначе только что созданные свойства не привязываются
        // (кэш свойств инфоблока в объекте элемента). Это и есть фикс «нет характеристик».
        if ($propValues) {
            \CIBlockElement::SetPropertyValuesEx($id, $this->iblockId, $propValues);
        }

        // --- габариты (Units → CCatalogProduct), цена НЕ задаётся (§5.6) ---
        $this->applyDimensions($id, $payload, $existingId === null);

        // --- справочные сущности: бренд → страна → теги (§5.4) ---
        $this->applyReferences($id, $payload);

        // --- изображения: обложка + галерея (дедуп + трекинг качества, §5.3) ---
        $this->applyMedia($id, $payload);

        // --- коллекции (§5.4) ---
        $this->applyCollections($id, $payload);

        // --- событие для сайтового слоя (§8), полный payload ---
        $this->fireImported($id, $payload, ['existing' => $existingId !== null]);

        return ['status' => $existingId ? 'updated' : 'created', 'id' => $id];
    }

    private function findByPublicId(string $publicId): ?int
    {
        $rs = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $this->iblockId, 'PROPERTY_OC_PUBLIC_ID' => $publicId],
            false,
            false,
            ['ID']
        );
        if ($row = $rs->Fetch()) {
            return (int) $row['ID'];
        }
        return null;
    }

    /**
     * Характеристики options[] → значения свойств (§5.4).
     * text → список (multiple); boolean → список Да/Нет (false по умолчанию, §5.6);
     * numeric → свойство-число. Возвращает [propertyId => value|enumId|array].
     */
    private function resolveOptions(array $options): array
    {
        $manual = Settings::manualMapping();
        $map = $manual ? Settings::specMap() : [];
        $values = [];

        foreach ($options as $opt) {
            $specId = (int) ($opt['specification_id'] ?? 0);
            if ($specId <= 0) {
                continue;
            }
            $label = (string) ($opt['specification_label'] ?? '');
            $type = (string) ($opt['specification_type'] ?? 'text');
            $code = 'OC_SPEC_' . $specId;

            // Резолв целевого свойства: строгий маппинг по specification_id ИЛИ
            // авто (по имени / создать). В строгом режиме несопоставленные пропускаются.
            $m = null;
            if ($manual) {
                $m = $map[(string) $specId] ?? null;
                if (!$m || empty($m['prop'])) {
                    continue; // строгий режим: импортируем только сопоставленные (§5.4)
                }
                $propId = (int) $m['prop'];
            } else {
                $prop = $this->tax->ensureProperty($code, $label, $type === 'numeric' ? 'N' : 'L', $type === 'text');
                if (!$prop) {
                    continue;
                }
                $propId = $prop['ID'];
            }

            if ($type === 'numeric') {
                $num = $opt['numeric_option'] ?? null;
                if ($num !== null && $num !== '') {
                    $this->addValue($values, $propId, $num);
                }
            } elseif ($type === 'boolean') {
                $isTrue = ($opt['bool_option'] ?? null) === true; // null/false/нет → false (§5.6)
                if ($manual && $m) {
                    $term = $isTrue
                        ? ($m['true'] ?? (Loc::getMessage('ONECATALOG_BOOL_TRUE') ?: 'Да'))
                        : ($m['false'] ?? (Loc::getMessage('ONECATALOG_BOOL_FALSE') ?: 'Нет'));
                } else {
                    $term = $isTrue
                        ? (Loc::getMessage('ONECATALOG_BOOL_TRUE') ?: 'Да')
                        : (Loc::getMessage('ONECATALOG_BOOL_FALSE') ?: 'Нет');
                }
                $enumId = $this->tax->ensureEnum($propId, $term, $code . ($isTrue ? '_TRUE' : '_FALSE'));
                if ($enumId) {
                    $this->addValue($values, $propId, $enumId);
                }
            } else { // text
                $name = $opt['specification_option_name'] ?? null;
                if ($name === null || $name === '') {
                    continue;
                }
                $optXml = isset($opt['specification_option_id'])
                    ? 'OC_OPT_' . (int) $opt['specification_option_id']
                    : null;
                $enumId = $this->tax->ensureEnum($propId, (string) $name, $optXml);
                if ($enumId) {
                    $this->addValue($values, $propId, $enumId);
                }
            }
        }
        return $values;
    }

    /** Накопить значение свойства (поддержка множественных). */
    private function addValue(array &$values, int $propId, $value): void
    {
        if (!isset($values[$propId])) {
            $values[$propId] = $value;
        } elseif (is_array($values[$propId])) {
            $values[$propId][] = $value;
        } else {
            $values[$propId] = [$values[$propId], $value];
        }
    }

    /**
     * Категории товара (плоские) → разделы-дерево по parent_category из /categories/
     * (§5.4, в.6). Возвращает массив ID листовых разделов для привязки элемента.
     */
    private function resolveCategorySections(array $productCats): array
    {
        if (!$productCats) {
            return [];
        }
        $map = $this->categoryMap();
        $sectionIds = [];

        foreach ($productCats as $pc) {
            $chain = self::buildCategoryChain($map, $pc);

            $parentSec = null;
            $leafSec = null;
            foreach ($chain as $n) {
                $leafSec = $this->tax->ensureSection(
                    (int) $n['id'],
                    (string) ($n['menutitle'] ?? $n['name'] ?? ''),
                    $n['slug'] ?? null,
                    $parentSec
                );
                if ($leafSec === null) {
                    break;
                }
                $parentSec = $leafSec;
            }
            if ($leafSec) {
                $sectionIds[$leafSec] = $leafSec;
            }
        }

        return array_values($sectionIds);
    }

    /**
     * Чистое построение цепочки категорий root..leaf по карте id => ref
     * (с parent_category). Защита от циклов. Тестируется без Битрикса.
     *
     * @param array $map   id => {id, menutitle, slug, parent_category?}
     * @param array $start стартовая категория (плоская из товара или из карты)
     * @return array<int,array> узлы от корня к листу
     */
    public static function buildCategoryChain(array $map, array $start): array
    {
        $startId = (int) ($start['id'] ?? 0);
        $node = $map[$startId] ?? $start;

        $chain = [];
        $seen = [];
        while ($node) {
            $cid = (int) ($node['id'] ?? 0);
            if ($cid === 0 || isset($seen[$cid])) {
                break;
            }
            $seen[$cid] = true;
            array_unshift($chain, $node);

            $parent = $node['parent_category'] ?? null;
            if ($parent && isset($parent['id']) && isset($map[(int) $parent['id']])) {
                $node = $map[(int) $parent['id']];
            } else {
                $node = $parent ?: null;
            }
        }

        return $chain;
    }

    /** Карта категорий id => ref (с parent_category) из справочника, лениво. */
    private function categoryMap(): array
    {
        if ($this->catMap === null) {
            $this->catMap = [];
            foreach ($this->api->categories() as $c) {
                if (isset($c['id'])) {
                    $this->catMap[(int) $c['id']] = $c;
                }
            }
        }
        return $this->catMap;
    }

    /**
     * Габариты: вес → граммы, размеры → миллиметры (внутренние единицы каталога,
     * §5.6). Пишутся в CCatalogProduct. Цена НЕ создаётся (§5.6).
     */
    private function applyDimensions(int $id, array $payload, bool $isNew): void
    {
        if (!Loader::includeModule('catalog')) {
            return;
        }

        $sizes = $payload['sizes'] ?? null;
        $fields = ['ID' => $id];

        if (is_array($sizes)) {
            $lenUnit = $sizes['length_unit'] ?? null;
            $weightUnit = $sizes['weight_unit'] ?? null;

            $weight = Units::toGrams($sizes['weight'] ?? null, $weightUnit);
            $length = Units::toMillimeters($sizes['length'] ?? null, $lenUnit);
            $width  = Units::toMillimeters($sizes['width'] ?? null, $lenUnit);
            $height = Units::toMillimeters($sizes['height'] ?? null, $lenUnit);

            if ($weight !== null) {
                $fields['WEIGHT'] = $weight;
            }
            if ($length !== null) {
                $fields['LENGTH'] = $length;
            }
            if ($width !== null) {
                $fields['WIDTH'] = $width;
            }
            if ($height !== null) {
                $fields['HEIGHT'] = $height;
            }
        }

        $exists = (bool) \CCatalogProduct::GetByID($id);

        if (count($fields) === 1) {
            // Нет габаритов: при создании всё равно завести карточку товара (без цены).
            if ($isNew && !$exists) {
                \CCatalogProduct::Add(['ID' => $id, 'QUANTITY' => 0]);
            }
            return;
        }

        if ($exists) {
            \CCatalogProduct::Update($id, $fields);
        } else {
            \CCatalogProduct::Add($fields);
        }
    }

    /**
     * Справочные сущности «тип объекта + цель» (§3, §7): бренд / страна / теги.
     * Дефолт-адаптер — свойство-список (L). Доп. поля и лого — в события (§8).
     */
    private function applyReferences(int $id, array $payload): void
    {
        if (!empty($payload['brand']) && is_array($payload['brand']) && Settings::bool('IMPORT_BRAND', false)) {
            $name = Loc::getMessage('ONECATALOG_PROP_BRAND') ?: 'Brand';
            $code = (string) (Settings::get('BRAND_PROP_CODE', '') ?: 'OC_BRAND');
            $r = (new BrandImporter($this->api, $this->tax, $this->iblockId, $name, $code))->assign($id, $payload['brand']);
            if ($r !== null) {
                $this->fireEntity('OnAfterBrandImported', $id, $payload['brand'], $r);
            }
        }

        if (!empty($payload['country']) && is_array($payload['country']) && Settings::bool('IMPORT_COUNTRY', false)) {
            $name = Loc::getMessage('ONECATALOG_PROP_COUNTRY') ?: 'Country';
            $code = (string) (Settings::get('COUNTRY_PROP_CODE', '') ?: 'OC_COUNTRY');
            $r = (new CountryImporter($this->tax, $this->iblockId, $name, $code))->assign($id, $payload['country']);
            if ($r !== null) {
                $this->fireEntity('OnAfterCountryImported', $id, $payload['country'], $r);
            }
        }

        if (!empty($payload['tags']) && is_array($payload['tags']) && Settings::bool('IMPORT_TAGS', false)) {
            $this->applyTags($id, $payload['tags']);
        }
    }

    /** Теги → НАТИВНОЕ поле элемента TAGS (строка через запятую), поиск по title (§5.1). */
    private function applyTags(int $id, array $tags): void
    {
        $titles = [];
        foreach ($tags as $t) {
            $title = trim((string) ($t['title'] ?? $t['name'] ?? ''));
            if ($title !== '') {
                $titles[$title] = $title; // дедуп по значению
            }
        }
        if (!$titles) {
            return;
        }
        (new \CIBlockElement())->Update($id, ['TAGS' => implode(', ', array_values($titles))]);
    }

    /** Коллекции (§3, §7): дефолт-адаптер — множественный список; поля → событие (§8). */
    private function applyCollections(int $id, array $payload): void
    {
        if (empty($payload['collections']) || !is_array($payload['collections'])) {
            return;
        }
        if (!Settings::bool('IMPORT_COLLECTIONS', false)) {
            return;
        }
        $name = Loc::getMessage('ONECATALOG_PROP_COLLECTION') ?: 'Collection';
        $code = (string) (Settings::get('COLLECTION_PROP_CODE', '') ?: 'OC_COLLECTION');
        $results = (new CollectionImporter($this->api, $this->tax, $this->iblockId, $name, $code))
            ->assign($id, $payload['collections']);
        foreach ($results as $i => $res) {
            $entity = $payload['collections'][$i] ?? [];
            $this->fireEntity('OnAfterCollectionImported', $id, $entity, $res);
        }
    }

    private function fireEntity(string $eventName, int $id, array $entity, array $result): void
    {
        (new Event(self::EVENT_MODULE, $eventName, [
            'ID'     => $id,
            'ENTITY' => $entity,
            'RESULT' => $result,
        ]))->send();
    }

    /**
     * Изображения (§2.3, §5.3): обложка → PREVIEW/DETAIL, галерея → OC_MORE_PHOTO.
     * Идемпотентность по подписи набора (имена файлов + размер): если ничего не
     * изменилось и качество не улучшилось — медиа пропускается (не плодим копии).
     */
    private function applyMedia(int $id, array $payload): void
    {
        $media = new Media($this->api);

        $coverUrl = $media->pickUrl($payload['images_urls'] ?? null);

        $files = [];
        foreach (($payload['files'] ?? []) as $f) {
            $cat = (string) ($f['category'] ?? '');
            if ($cat !== '' && $cat !== 'images') {
                continue;
            }
            $url = $media->pickUrl($f['urls'] ?? null);
            if ($url !== null) {
                $files[] = ['url' => $url, 'name' => $f['name'] ?? null, 'alt' => $f['alt'] ?? null];
            }
        }

        if ($coverUrl === null && !$files) {
            return;
        }

        // Подпись набора: имена файлов + выбранный размер (триггер апгрейда качества).
        // Хранится в служебной таблице Meta, а НЕ свойством товара (не засоряем форму).
        $sig = sha1((string) json_encode([
            'cover' => $coverUrl !== null ? basename((string) parse_url($coverUrl, PHP_URL_PATH)) : null,
            'size'  => $media->preferredSize(),
            'files' => array_map(static fn ($f) => $f['name'] ?: Media::extractPath($f['url']), $files),
        ], JSON_UNESCAPED_UNICODE));

        if (Meta::get($id, 'media_sig') === $sig) {
            return; // без изменений и без улучшения качества — пропускаем (§5.3)
        }

        $fields = [];
        if ($coverUrl !== null) {
            $coverId = $media->sideload($coverUrl, $payload['images_data'] ?? null);
            if ($coverId) {
                $fields['PREVIEW_PICTURE'] = \CFile::MakeFileArray($coverId);
                $fields['DETAIL_PICTURE'] = \CFile::MakeFileArray($coverId);
            }
        }

        // Галерея → НАТИВНОЕ свойство «Детальные картинки» (CODE = MORE_PHOTO):
        // если оно уже есть в инфоблоке — используем его, иначе создаём с этим кодом.
        $galleryProp = $this->tax->ensureProperty('MORE_PHOTO', Loc::getMessage('ONECATALOG_PROP_MORE_PHOTO') ?: 'More photos', 'F', true);
        $galleryValues = [];
        foreach ($files as $f) {
            $fid = $media->sideload($f['url'], ['name' => $f['name'], 'alt' => $f['alt']]);
            if ($fid) {
                $galleryValues[] = ['VALUE' => \CFile::MakeFileArray($fid)];
            }
        }

        if ($fields) {
            (new \CIBlockElement())->Update($id, $fields);
        }
        if ($galleryProp && $galleryValues) {
            \CIBlockElement::SetPropertyValuesEx($id, $this->iblockId, [$galleryProp['ID'] => $galleryValues]);
        }
        Meta::set($id, 'media_sig', $sig);
    }

    /** OnBeforeImportProduct: сайт может вернуть изменённый payload (§8). */
    private function filterPayload(array $payload, ?int $existingId): array
    {
        $event = new Event(self::EVENT_MODULE, 'OnBeforeImportProduct', [
            'PAYLOAD'     => $payload,
            'EXISTING_ID' => $existingId,
        ]);
        $event->send();

        foreach ($event->getResults() as $result) {
            if ($result->getType() === EventResult::SUCCESS) {
                $params = $result->getParameters();
                if (isset($params['PAYLOAD']) && is_array($params['PAYLOAD'])) {
                    $payload = $params['PAYLOAD'];
                }
            }
        }
        return $payload;
    }

    /** OnAfterProductImported: сайтовый слой дозаполняет поля (§8). */
    private function fireImported(int $id, array $payload, array $report): void
    {
        (new Event(self::EVENT_MODULE, 'OnAfterProductImported', [
            'ID'      => $id,
            'PAYLOAD' => $payload,
            'REPORT'  => $report,
        ]))->send();
    }

    /** CODE элемента из slug (только при создании); фолбэк — из public_id. */
    private function makeCode(string $slug, string $publicId): string
    {
        $slug = trim($slug);
        if ($slug !== '') {
            return $slug;
        }
        return mb_strtolower(str_replace(['.', ' '], '-', $publicId));
    }
}
