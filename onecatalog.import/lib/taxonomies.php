<?php

namespace OneCatalog\Import;

/**
 * Слой Taxonomies (§4, §5.1, §5.4): свойства инфоблока (списки/число/строка),
 * значения-перечисления (enum) и разделы (категории-дерево).
 *
 * Ключевой инвариант (§5.1): find-or-create справочника по ЧЕЛОВЕКОЧИТАЕМОМУ имени
 * (label) без учёта регистра, а НЕ по слагу/CODE — иначе дубли при разных
 * транслитерациях. Стабильный ключ — id OneCatalog (в XML_ID/CODE свойства и enum).
 *
 * Зависимости: модуль iblock (CIBlock*). Все find/create идемпотентны.
 */
final class Taxonomies
{
    public function __construct(private int $iblockId)
    {
    }

    /**
     * find-or-create свойство инфоблока по стабильному коду (CODE=XML_ID).
     *
     * @param string $code стабильный код, напр. 'OC_SPEC_7' или 'OC_PUBLIC_ID'
     * @param string $type 'L' список | 'N' число | 'S' строка
     * @return array{ID:int,CODE:string}|null
     */
    public function ensureProperty(string $code, string $name, string $type = 'L', bool $multiple = false): ?array
    {
        $rs = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $this->iblockId, 'CODE' => $code]);
        if ($row = $rs->Fetch()) {
            return ['ID' => (int) $row['ID'], 'CODE' => (string) $row['CODE']];
        }

        $prop = new \CIBlockProperty();
        $id = $prop->Add([
            'IBLOCK_ID'     => $this->iblockId,
            'NAME'          => $name !== '' ? $name : $code,
            'CODE'          => $code,
            'XML_ID'        => $code,
            'PROPERTY_TYPE' => $type,
            'MULTIPLE'      => $multiple ? 'Y' : 'N',
            'IS_REQUIRED'   => 'N',
            'ACTIVE'        => 'Y',
        ]);

        return $id ? ['ID' => (int) $id, 'CODE' => $code] : null;
    }

    /**
     * find-or-create значение списка (enum). Поиск: сначала по стабильному XML_ID
     * (specification_option_id), затем по VALUE (label) без учёта регистра (§5.1).
     * Слаг/XML_ID — только для создания нового.
     */
    public function ensureEnum(int $propertyId, string $label, ?string $xmlId = null): ?int
    {
        if ($xmlId !== null && $xmlId !== '') {
            $rs = \CIBlockPropertyEnum::GetList([], ['PROPERTY_ID' => $propertyId, 'XML_ID' => $xmlId]);
            if ($row = $rs->Fetch()) {
                return (int) $row['ID'];
            }
        }

        // Матч по человекочитаемому имени, регистронезависимо (защита от дублей).
        $needle = $this->fold($label);
        $rs = \CIBlockPropertyEnum::GetList([], ['PROPERTY_ID' => $propertyId]);
        while ($row = $rs->Fetch()) {
            if ($this->fold((string) $row['VALUE']) === $needle) {
                return (int) $row['ID'];
            }
        }

        $enum = new \CIBlockPropertyEnum();
        $id = $enum->Add([
            'PROPERTY_ID' => $propertyId,
            'VALUE'       => $label,
            'XML_ID'      => ($xmlId !== null && $xmlId !== '') ? $xmlId : md5($needle),
            'DEF'         => 'N',
        ]);

        return $id ? (int) $id : null;
    }

    /**
     * find-or-create раздел (категория). Поиск по стабильному XML_ID='oc_cat_<id>',
     * фолбэк — по NAME (label) под тем же родителем (§5.1). Возвращает ID раздела.
     */
    public function ensureSection(int $ocId, string $name, ?string $slug, ?int $parentSectionId): ?int
    {
        $xmlId = 'oc_cat_' . $ocId;

        $rs = \CIBlockSection::GetList([], ['IBLOCK_ID' => $this->iblockId, 'XML_ID' => $xmlId], false, ['ID']);
        if ($row = $rs->Fetch()) {
            return (int) $row['ID'];
        }

        // Фолбэк: по имени под тем же родителем (разные транслиты — один раздел).
        $rs = \CIBlockSection::GetList([], [
            'IBLOCK_ID'  => $this->iblockId,
            '=NAME'      => $name,
            'SECTION_ID' => $parentSectionId ?: false,
        ], false, ['ID']);
        if ($row = $rs->Fetch()) {
            return (int) $row['ID'];
        }

        $section = new \CIBlockSection();
        $id = $section->Add([
            'IBLOCK_ID'         => $this->iblockId,
            'NAME'              => $name,
            'CODE'              => $slug ?: null,
            'XML_ID'            => $xmlId,
            'IBLOCK_SECTION_ID' => $parentSectionId,
            'ACTIVE'            => 'Y',
        ]);

        return $id ? (int) $id : null;
    }

    private function fold(string $s): string
    {
        return mb_strtolower(trim($s));
    }
}
