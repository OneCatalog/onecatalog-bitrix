<?php

namespace OneCatalog\Import;

use Bitrix\Main\Application;

/**
 * «Отстойник» (staging) для unknown-товаров B2B-фида (§13.5).
 *
 * При режиме unknown = «отстойник» несопоставленные товары поставщиков НЕ создаются
 * автоматически, а складываются сюда на ручной отбор. Ключ — поставщик+код оффера.
 * Уже игнорированные записи при повторной встрече не воскрешаются.
 */
final class Staging
{
    public const TABLE = 'onecatalog_b2b_staging';

    /** Положить unknown-оффер в отстойник (upsert по supplier_id+code). */
    public static function collect(array $offer, array $suppliers = []): void
    {
        $code = trim((string) ($offer['code'] ?? ''));
        if ($code === '') {
            return;
        }
        $supplierId = PriceStockSync::offerSupplierId($offer);
        $name = (string) ($offer['name'] ?? ($suppliers[$supplierId]['name'] ?? ''));

        $conn = Application::getConnection();
        $h = $conn->getSqlHelper();
        $cSql = "'" . $h->forSql($code) . "'";
        $exists = $conn->query("SELECT ID, STATUS FROM " . self::TABLE . " WHERE SUPPLIER_ID = " . $supplierId . " AND CODE = " . $cSql)->fetch();

        $nSql = "'" . $h->forSql($name) . "'";
        $dSql = "'" . $h->forSql((string) json_encode($offer, JSON_UNESCAPED_UNICODE)) . "'";

        if ($exists) {
            // Игнорированные не воскрешаем; остальным обновляем имя/данные.
            $conn->queryExecute("UPDATE " . self::TABLE . " SET NAME = " . $nSql . ", DATA = " . $dSql . ", UPDATED_AT = NOW() WHERE ID = " . (int) $exists['ID']);
        } else {
            $conn->queryExecute("INSERT INTO " . self::TABLE . " (SUPPLIER_ID, CODE, NAME, DATA, STATUS, CREATED_AT, UPDATED_AT) "
                . "VALUES (" . $supplierId . ", " . $cSql . ", " . $nSql . ", " . $dSql . ", 'new', NOW(), NOW())");
        }
    }

    /** @return array<int,array> строки по статусу */
    public static function listByStatus(string $status, int $limit = 200): array
    {
        $conn = Application::getConnection();
        $h = $conn->getSqlHelper();
        $rs = $conn->query("SELECT * FROM " . self::TABLE . " WHERE STATUS = '" . $h->forSql($status) . "' ORDER BY ID DESC LIMIT " . max(1, $limit));
        $out = [];
        while ($r = $rs->fetch()) {
            $out[] = $r;
        }
        return $out;
    }

    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['new', 'ignored'], true)) {
            return;
        }
        $conn = Application::getConnection();
        $conn->queryExecute("UPDATE " . self::TABLE . " SET STATUS = '" . $status . "', UPDATED_AT = NOW() WHERE ID = " . $id);
    }

    public static function delete(int $id): void
    {
        Application::getConnection()->queryExecute("DELETE FROM " . self::TABLE . " WHERE ID = " . $id);
    }

    public static function countNew(): int
    {
        $row = Application::getConnection()->query("SELECT COUNT(*) AS C FROM " . self::TABLE . " WHERE STATUS = 'new'")->fetch();
        return (int) ($row['C'] ?? 0);
    }
}
