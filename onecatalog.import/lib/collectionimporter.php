<?php

namespace OneCatalog\Import;

/**
 * Слой CollectionImporter (§3, §7): коллекция → множественное свойство-список (L, дефолт).
 *
 * Поля коллекции берутся из эмбеда collections[] товара (§2.1: /collections/{slug}/
 * отдаёт 500 — НЕ дёргать). В режиме «список» доп. поля (link_3d,
 * link_official_site, лого) сохранить негде → они уходят в событие
 * OnAfterCollectionImported (сайтовый слой §8); лого кэшируется через Media.
 *
 * Отдельный инфоблок (богаче полей, дефолт стандарта) / раздел — opt-in следующего
 * инкремента. find-or-create по label (§5.1), XML_ID enum = 'OC_COLL_<id>'.
 */
final class CollectionImporter
{
    public function __construct(
        private Api $api,
        private Taxonomies $tax,
        private int $iblockId,
        private string $propName
    ) {
    }

    /**
     * @param array $collections collections[] из payload товара
     * @return array<int,array> результаты по каждой коллекции (для событий)
     */
    public function assign(int $elementId, array $collections): array
    {
        $prop = $this->tax->ensureProperty('OC_COLLECTION', $this->propName, 'L', true);
        if ($prop === null) {
            return [];
        }

        $media = new Media($this->api);
        $enumIds = [];
        $results = [];

        foreach ($collections as $c) {
            $name = trim((string) ($c['menutitle'] ?? ''));
            if ($name === '') {
                continue;
            }
            $xmlId = isset($c['id']) ? 'OC_COLL_' . (int) $c['id'] : null;
            $enumId = $this->tax->ensureEnum($prop['ID'], $name, $xmlId);
            if (!$enumId) {
                continue;
            }
            $enumIds[] = $enumId;

            $res = ['type' => 'list', 'enumId' => $enumId, 'oc_id' => $c['id'] ?? null];
            if (!empty($c['images_urls'])) {
                $logoUrl = $media->pickUrl($c['images_urls']);
                if ($logoUrl !== null) {
                    $fid = $media->sideload($logoUrl, $c['images_data'] ?? ['name' => $name]);
                    if ($fid) {
                        $res['logo_file_id'] = $fid;
                    }
                }
            }
            $results[] = $res;
        }

        if ($enumIds) {
            \CIBlockElement::SetPropertyValuesEx($elementId, $this->iblockId, [$prop['ID'] => $enumIds]);
        }
        return $results;
    }
}
