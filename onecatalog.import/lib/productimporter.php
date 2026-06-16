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
        $fields['PROPERTY_VALUES'] = $propValues;

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

        // --- габариты (Units → CCatalogProduct), цена НЕ задаётся (§5.6) ---
        $this->applyDimensions($id, $payload, $existingId === null);

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
        $values = [];
        foreach ($options as $opt) {
            $specId = (int) ($opt['specification_id'] ?? 0);
            if ($specId <= 0) {
                continue;
            }
            $label = (string) ($opt['specification_label'] ?? '');
            $type = (string) ($opt['specification_type'] ?? 'text');
            $code = 'OC_SPEC_' . $specId;

            if ($type === 'numeric') {
                $prop = $this->tax->ensureProperty($code, $label, 'N');
                $num = $opt['numeric_option'] ?? null;
                if ($prop && $num !== null && $num !== '') {
                    $values[$prop['ID']] = $num;
                }
                continue;
            }

            if ($type === 'boolean') {
                $prop = $this->tax->ensureProperty($code, $label, 'L');
                if (!$prop) {
                    continue;
                }
                $isTrue = ($opt['bool_option'] ?? null) === true; // null/false/нет → false (§5.6)
                $term = $isTrue
                    ? (Loc::getMessage('ONECATALOG_BOOL_TRUE') ?: 'Да')
                    : (Loc::getMessage('ONECATALOG_BOOL_FALSE') ?: 'Нет');
                $enumId = $this->tax->ensureEnum($prop['ID'], $term, $code . ($isTrue ? '_TRUE' : '_FALSE'));
                if ($enumId) {
                    $values[$prop['ID']] = $enumId;
                }
                continue;
            }

            // text
            $name = $opt['specification_option_name'] ?? null;
            if ($name === null || $name === '') {
                continue; // у boolean приходит null — но сюда не попадёт; текст без значения пропускаем
            }
            $prop = $this->tax->ensureProperty($code, $label, 'L', true);
            if (!$prop) {
                continue;
            }
            $optXml = isset($opt['specification_option_id'])
                ? 'OC_OPT_' . (int) $opt['specification_option_id']
                : null;
            $enumId = $this->tax->ensureEnum($prop['ID'], (string) $name, $optXml);
            if (!$enumId) {
                continue;
            }
            if (!isset($values[$prop['ID']])) {
                $values[$prop['ID']] = $enumId;
            } elseif (is_array($values[$prop['ID']])) {
                $values[$prop['ID']][] = $enumId;
            } else {
                $values[$prop['ID']] = [$values[$prop['ID']], $enumId];
            }
        }
        return $values;
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
