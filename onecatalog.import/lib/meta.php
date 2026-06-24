<?php

namespace OneCatalog\Import;

use Bitrix\Main\Application;

/**
 * Служебное key-value хранилище по элементам каталога (таблица onecatalog_meta).
 *
 * Технические значения (сигнатуры идемпотентности медиа и цен/остатков, коды
 * поставщиков) НЕ должны быть свойствами инфоблока — иначе они засоряют форму
 * редактирования товара. Храним их здесь, рядом с элементом.
 */
final class Meta
{
    public const TABLE = 'onecatalog_meta';

    public static function get(int $elementId, string $key): ?string
    {
        $conn = Application::getConnection();
        $h = $conn->getSqlHelper();
        $row = $conn->query(
            'SELECT VALUE FROM ' . self::TABLE . ' WHERE ELEMENT_ID = ' . $elementId
            . " AND META_KEY = '" . $h->forSql($key) . "'"
        )->fetch();
        return $row ? (string) $row['VALUE'] : null;
    }

    public static function set(int $elementId, string $key, string $value): void
    {
        $conn = Application::getConnection();
        $h = $conn->getSqlHelper();
        $k = "'" . $h->forSql($key) . "'";
        $v = "'" . $h->forSql($value) . "'";
        $exists = $conn->query(
            'SELECT ID FROM ' . self::TABLE . ' WHERE ELEMENT_ID = ' . $elementId . ' AND META_KEY = ' . $k
        )->fetch();
        if ($exists) {
            $conn->queryExecute('UPDATE ' . self::TABLE . ' SET VALUE = ' . $v . ' WHERE ID = ' . (int) $exists['ID']);
        } else {
            $conn->queryExecute('INSERT INTO ' . self::TABLE . ' (ELEMENT_ID, META_KEY, VALUE) VALUES (' . $elementId . ', ' . $k . ', ' . $v . ')');
        }
    }

    /** @param int[] $elementIds @return array<int,string> element_id => value */
    public static function getMany(array $elementIds, string $key): array
    {
        $ids = array_values(array_filter(array_map('intval', $elementIds)));
        if (!$ids) {
            return [];
        }
        $conn = Application::getConnection();
        $h = $conn->getSqlHelper();
        $rs = $conn->query(
            'SELECT ELEMENT_ID, VALUE FROM ' . self::TABLE
            . " WHERE META_KEY = '" . $h->forSql($key) . "' AND ELEMENT_ID IN (" . implode(',', $ids) . ')'
        );
        $out = [];
        while ($r = $rs->fetch()) {
            $out[(int) $r['ELEMENT_ID']] = (string) $r['VALUE'];
        }
        return $out;
    }
}
