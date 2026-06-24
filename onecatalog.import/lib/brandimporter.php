<?php

namespace OneCatalog\Import;

/**
 * Слой BrandImporter (§3, §7): бренд → свойство-список (L, дефолт).
 *
 * HL-справочник / раздел — opt-in следующего инкремента (требуют модуль
 * highloadblock / отдельный инфоблок). Пока реализован безопасный дефолт «список»,
 * работающий на всех редакциях (в.10 ответов).
 *
 * find-or-create по человекочитаемому имени (§5.1), стабильный XML_ID enum =
 * 'OC_BRAND_<id>'. Доп. поля бренда (страна, год основания, сайт) и лого — в
 * событие OnAfterBrandImported (сайтовый слой §8); лого кэшируется через Media
 * (дедуп: один логотип на многих товарах скачивается один раз).
 */
final class BrandImporter
{
    public function __construct(
        private Api $api,
        private Taxonomies $tax,
        private int $iblockId,
        private string $propName,
        private string $propCode = 'OC_BRAND'
    ) {
    }

    /**
     * @return array{type:string,enumId:int,logo_file_id?:int}|null
     */
    public function assign(int $elementId, array $brand): ?array
    {
        $name = trim((string) ($brand['menutitle'] ?? ''));
        if ($name === '') {
            return null;
        }

        $xmlId = isset($brand['id']) ? 'OC_BRAND_' . (int) $brand['id'] : null;
        $v = $this->tax->ensureListValue($this->propCode, $this->propName, $name, $xmlId);
        if ($v === null) {
            return null;
        }
        \CIBlockElement::SetPropertyValuesEx($elementId, $this->iblockId, [$v['propId'] => $v['enumId']]);

        $result = ['type' => 'list', 'enumId' => $v['enumId']];

        // Лого бренда — кэшируем через Media (дедуп общих файлов, §5.3); id отдаём
        // в событие, чтобы сайт мог привязать его к своей структуре бренда.
        if (!empty($brand['images_urls'])) {
            $media = new Media($this->api);
            $logoUrl = $media->pickUrl($brand['images_urls']);
            if ($logoUrl !== null) {
                $fid = $media->sideload($logoUrl, $brand['images_data'] ?? ['name' => $name]);
                if ($fid) {
                    $result['logo_file_id'] = $fid;
                }
            }
        }

        return $result;
    }
}
