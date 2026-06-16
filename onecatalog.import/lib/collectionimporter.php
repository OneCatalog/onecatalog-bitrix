<?php

namespace OneCatalog\Import;

/**
 * Слой CollectionImporter (§3, §7): коллекция → инфоблок | раздел | свойство (настройка).
 * Поля коллекции берутся из эмбеда collections[] товара (§2.1: /collections/{slug}/ → 500).
 * TODO: реализация трёх целевых типов + идемпотентность по id коллекции.
 */
final class CollectionImporter
{
}
