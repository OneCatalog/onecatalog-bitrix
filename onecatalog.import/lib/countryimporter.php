<?php

namespace OneCatalog\Import;

/**
 * Слой CountryImporter (§3, §7): страна происхождения → свойство-список (L, дефолт).
 * По умолчанию импорт страны выключен (§7). find-or-create по label (§5.1),
 * стабильный XML_ID enum = 'OC_COUNTRY_<id>'. HL — opt-in следующего инкремента.
 */
final class CountryImporter
{
    public function __construct(
        private Taxonomies $tax,
        private int $iblockId,
        private string $propName
    ) {
    }

    /** @return array{type:string,enumId:int}|null */
    public function assign(int $elementId, array $country): ?array
    {
        $name = trim((string) ($country['menutitle'] ?? ''));
        if ($name === '') {
            return null;
        }
        $xmlId = isset($country['id']) ? 'OC_COUNTRY_' . (int) $country['id'] : null;
        $v = $this->tax->ensureListValue('OC_COUNTRY', $this->propName, $name, $xmlId);
        if ($v === null) {
            return null;
        }
        \CIBlockElement::SetPropertyValuesEx($elementId, $this->iblockId, [$v['propId'] => $v['enumId']]);
        return ['type' => 'list', 'enumId' => $v['enumId']];
    }
}
