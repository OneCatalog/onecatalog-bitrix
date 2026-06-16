<?php

namespace OneCatalog\Import;

/**
 * Слой Taxonomies (§4, §5.1, §5.4): свойства-списки/HL, термины (enum), разделы.
 *
 * Ключевой инвариант (§5.1): find-or-create справочника по ЧЕЛОВЕКОЧИТАЕМОМУ имени
 * (label), без учёта регистра, а НЕ по слагу/CODE — иначе дубли при разных
 * транслитерациях. Стабильный ключ маппинга — id OneCatalog (XML_ID свойства/enum).
 *
 * TODO:
 *  - свойство-список (PROPERTY_TYPE='L') find-or-create по XML_ID='oc_spec_<id>';
 *  - enum (CIBlockPropertyEnum) find-or-create по VALUE (label) / XML_ID=option_id;
 *  - категории → разделы (CIBlockSection) с деревом по parent_category (в.6);
 *  - boolean → два enum (Да/Нет), numeric → свойство типа N.
 */
final class Taxonomies
{
    public function __construct(private int $iblockId)
    {
    }
}
