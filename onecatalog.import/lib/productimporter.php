<?php

namespace OneCatalog\Import;

/**
 * Слой ProductImporter (§4, §5.4): оркестратор импорта одного товара.
 *
 * Строгий порядок зависимостей (§5.4):
 *   payload → базовые поля (название, ACTIVE[только при создании], габариты через Units)
 *           → категории (дерево) → характеристики (маппинг/по имени; boolean→Да/Нет)
 *           → сохранить элемент (получить ID) → OC_PUBLIC_ID (идемпотентность, §5.1)
 *           → бренд → страна → теги → обложка+галерея (Media) → коллекция
 *           → событие OnAfterProductImported (полный payload — сайтовый слой §8)
 *
 * Инварианты: цена НЕ задаётся (§5.6); SKU=article отдельно от public_id (§5.2);
 * поля «только при создании» не перезатирать при апдейте (§5.6).
 *
 * TODO: реализация после Media/Taxonomies.
 */
final class ProductImporter
{
    public function __construct(
        private Api $api,
        private int $iblockId
    ) {
    }

    /** @return array{status:string, id?:int, message?:string} */
    public function import(string $publicId): array
    {
        // TODO: find-by-OC_PUBLIC_ID → Add/Update + зависимости.
        return ['status' => 'skipped', 'message' => 'not implemented yet'];
    }
}
