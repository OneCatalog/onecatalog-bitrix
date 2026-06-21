<?php

namespace OneCatalog\Import;

use Bitrix\Main\Loader;

/**
 * Синхронизация цен и остатков из B2B-фида (§13) — для 1С-Битрикс.
 *
 * Принцип scan-and-diff (§13.4): фаза скана читает фид и сравнивает сигнатуры
 * цены/остатка В ПАМЯТИ (префетч public_id→элемент+сигнатура одним запросом, без
 * загрузки тяжёлых объектов); записываются только изменившиеся. Ручной запуск —
 * AJAX-степпер постранично (надёжно без cron).
 *
 * Маппинг (§13.2): known по свойству OC_PUBLIC_ID → цена в нативный CPrice (тип цены +
 * валюта), остаток — сумма по складам → CCatalogProduct.QUANTITY. Коды поставщиков —
 * свойство OC_SUPPLIER_CODE. Сигнатура — свойство OC_PRICESTOCK_SIG.
 *
 * Чистые резолверы (resolve_price/resolve_stock/signature) не зависят от Битрикса и
 * покрыты тестами (общая логика со стандартом/эталоном).
 */
final class PriceStockSync
{
    public const SIG_PROP    = 'OC_PRICESTOCK_SIG';
    public const CODE_PROP   = 'OC_SUPPLIER_CODE';
    public const LOG_OPTION  = 'B2B_LOG';

    // ===================== Чистые резолверы (тестируются без Битрикса) =====================

    public static function offerSupplierId(array $offer): int
    {
        if (isset($offer['supplier_id'])) {
            return (int) $offer['supplier_id'];
        }
        return (int) ($offer['supplier']['id'] ?? 0);
    }

    /** @return array{regular:?float,sale:?float,purchasing:?float} */
    public static function resolvePrice(array $offers, array $regionPrio, array $supplierPrio, string $strategy = 'priority', int $supplierFixed = 0, bool $promoAsSale = true): array
    {
        $none = ['regular' => null, 'sale' => null, 'purchasing' => null];

        $candidates = [];
        if ($strategy === 'supplier') {
            foreach ($offers as $o) {
                if (self::offerSupplierId($o) === $supplierFixed) {
                    $candidates[] = $o;
                }
            }
        } else {
            $candidates = $offers;
        }
        if (!$candidates) {
            return $none;
        }

        $priced = [];
        foreach ($candidates as $o) {
            $p = self::priceForOffer($o, $regionPrio);
            if ($p !== null) {
                $priced[] = ['offer' => $o, 'price' => $p];
            }
        }
        if (!$priced) {
            return $none;
        }

        if ($strategy === 'priority' && $supplierPrio) {
            foreach ($supplierPrio as $sid) {
                foreach ($priced as $row) {
                    if (self::offerSupplierId($row['offer']) === (int) $sid) {
                        return self::withSale($row['price'], $promoAsSale);
                    }
                }
            }
        }
        if ($strategy === 'supplier') {
            return self::withSale($priced[0]['price'], $promoAsSale);
        }

        usort($priced, static fn ($a, $b) => $a['price']['base'] <=> $b['price']['base']);
        return self::withSale($priced[0]['price'], $promoAsSale);
    }

    private static function priceForOffer(array $offer, array $regionPrio): ?array
    {
        $byRegion = [];
        foreach ((array) ($offer['product_prices'] ?? []) as $pr) {
            $rid = (int) ($pr['region_id'] ?? 0);
            if ($rid > 0) {
                $byRegion[$rid] = $pr;
            }
        }
        if (!$byRegion) {
            return null;
        }
        $order = $regionPrio ?: array_keys($byRegion);
        foreach ($order as $rid) {
            $pr = $byRegion[(int) $rid] ?? null;
            if (!$pr) {
                continue;
            }
            $base = (float) ($pr['base_price'] ?? 0);
            if ($base > 0) {
                return [
                    'base'       => $base,
                    'promo'      => (float) ($pr['promo_price'] ?? 0),
                    'purchasing' => isset($pr['purchasing_price']) && $pr['purchasing_price'] !== null ? (float) $pr['purchasing_price'] : null,
                ];
            }
        }
        return null;
    }

    private static function withSale(array $price, bool $promoAsSale): array
    {
        $sale = null;
        if ($promoAsSale && $price['promo'] > 0 && $price['promo'] < $price['base']) {
            $sale = $price['promo'];
        }
        return ['regular' => $price['base'], 'sale' => $sale, 'purchasing' => $price['purchasing']];
    }

    /** Суммарный остаток по всем складам поставщиков. */
    public static function resolveStock(array $offers): float
    {
        $sum = 0.0;
        foreach ($offers as $o) {
            foreach ((array) ($o['products_stocks'] ?? []) as $s) {
                $sum += (float) ($s['quantity'] ?? 0);
            }
        }
        return $sum;
    }

    public static function anyAvailable(array $offers): bool
    {
        foreach ($offers as $o) {
            if (!empty($o['status'])) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int,string> уникальные коды поставщиков */
    public static function extractCodes(array $offers): array
    {
        $out = [];
        foreach ($offers as $o) {
            $code = trim((string) ($o['code'] ?? ''));
            if ($code !== '' && !in_array($code, $out, true)) {
                $out[] = $code;
            }
        }
        return $out;
    }

    /** Сигнатура того, что будет записано (цена+скидка+кол-во+статус). */
    public static function signature(array $r): string
    {
        return md5(implode('|', [
            $r['regular'] === null ? '-' : (string) (float) $r['regular'],
            ($r['sale'] ?? null) === null ? '-' : (string) (float) $r['sale'],
            (string) (float) ($r['qty'] ?? 0),
            (string) ($r['status'] ?? ''),
        ]));
    }

    /** Итоговые значения по офферам + сигнатура. */
    public static function resolveRecord(array $offers, array $cfg): array
    {
        $price = self::resolvePrice($offers, $cfg['region_prio'], $cfg['supplier_prio'], $cfg['strategy'], $cfg['supplier_fix'], $cfg['promo_as_sale']);
        $stock = self::resolveStock($offers);
        $available = self::anyAvailable($offers) && $stock > 0;
        $qty = $cfg['decimal'] ? $stock : (float) floor($stock);

        $rec = [
            'regular'    => $price['regular'],
            'sale'       => $price['sale'],
            'purchasing' => $price['purchasing'],
            'qty'        => $qty,
            'status'     => $available ? 'Y' : 'N',
        ];
        $rec['sig'] = self::signature($rec);
        return $rec;
    }

    // ===================== Оркестрация (Битрикс) =====================

    public static function cfg(): array
    {
        return [
            'region_prio'   => Settings::b2bRegionPriority(),
            'supplier_prio' => Settings::b2bSupplierPriority(),
            'strategy'      => Settings::b2bPriceStrategy(),
            'supplier_fix'  => Settings::b2bSupplierFixed(),
            'promo_as_sale' => Settings::bool('B2B_PROMO_AS_SALE', true),
            'decimal'       => Settings::bool('B2B_DECIMAL_STOCK', true),
        ];
    }

    /**
     * Обработать одну страницу фида (scan-and-diff). Возвращает счётчики + есть ли ещё.
     * @return array{ok:bool,more:bool,next:int,scanned:int,changed:int,unchanged:int,total:int,error?:string}
     */
    public static function processPage(int $start, int $size): array
    {
        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            return ['ok' => false, 'error' => 'modules', 'more' => false, 'next' => $start, 'scanned' => 0, 'changed' => 0, 'unchanged' => 0, 'total' => 0];
        }
        $iblockId = Settings::catalogIblockId();
        if ($iblockId <= 0) {
            return ['ok' => false, 'error' => 'no_iblock', 'more' => false, 'next' => $start, 'scanned' => 0, 'changed' => 0, 'unchanged' => 0, 'total' => 0];
        }

        $page = (new B2bApi())->fetchPage($start, $size);
        if ($page === null) {
            self::log('error', __FILE__, 'feed request failed');
            return ['ok' => false, 'error' => 'feed', 'more' => false, 'next' => $start, 'scanned' => 0, 'changed' => 0, 'unchanged' => 0, 'total' => 0];
        }

        $data = $page['data'];
        $total = (int) ($page['meta']['counts'] ?? 0);
        $known = (array) ($data['products']['known'] ?? []);

        $tax = new Taxonomies($iblockId);
        $tax->ensureProperty(self::SIG_PROP, 'OneCatalog price/stock signature', 'S');
        $tax->ensureProperty(self::CODE_PROP, 'OneCatalog supplier code', 'S', true);

        $map = self::mapPublicIds($iblockId, array_keys($known)); // public_id => ['id'=>, 'sig'=>]
        $cfg = self::cfg();
        $currency = Settings::b2bCurrency();
        $groupId  = Settings::b2bPriceGroupId();

        $scanned = 0;
        $changed = 0;
        $unchanged = 0;

        foreach ($known as $publicId => $offers) {
            $publicId = (string) $publicId;
            $offers   = (array) $offers;
            $scanned++;

            $row = $map[$publicId] ?? null;
            if ($row === null) {
                continue; // нет товара в каталоге — пропускаем (импорт — отдельным механизмом)
            }
            $rec = self::resolveRecord($offers, $cfg);
            if (($row['sig'] ?? '') === $rec['sig']) {
                $unchanged++;
                continue;
            }
            self::applyResolved((int) $row['id'], $iblockId, $rec, $offers, $groupId, $currency);
            $changed++;
        }

        $next = $start + $size;
        $more = ($scanned > 0) && ($next < ($total ?: PHP_INT_MAX));
        self::log('page', (string) $start, "scanned $scanned, changed $changed, unchanged $unchanged");

        return ['ok' => true, 'more' => $more, 'next' => $next, 'scanned' => $scanned, 'changed' => $changed, 'unchanged' => $unchanged, 'total' => $total];
    }

    /** Применить цену/остаток к элементу (единственное место записи). */
    private static function applyResolved(int $id, int $iblockId, array $rec, array $offers, int $groupId, string $currency): void
    {
        // Цена → CPrice (базовый тип цены). promo как отдельный тип — следующий инкремент.
        if ($rec['regular'] !== null && $groupId > 0) {
            $existing = \CPrice::GetList([], ['PRODUCT_ID' => $id, 'CATALOG_GROUP_ID' => $groupId])->Fetch();
            $fields = [
                'PRODUCT_ID'       => $id,
                'CATALOG_GROUP_ID' => $groupId,
                'PRICE'            => $rec['regular'],
                'CURRENCY'         => $currency,
            ];
            if ($existing) {
                \CPrice::Update($existing['ID'], $fields);
            } else {
                \CPrice::Add($fields);
            }
        }

        // Остаток → CCatalogProduct.QUANTITY (сумма по складам). Цена не «выдумывается».
        if (\CCatalogProduct::GetByID($id)) {
            \CCatalogProduct::Update($id, ['QUANTITY' => $rec['qty']]);
        } else {
            \CCatalogProduct::Add(['ID' => $id, 'QUANTITY' => $rec['qty']]);
        }

        // Коды поставщиков + сигнатура.
        $values = [];
        $tax = new Taxonomies($iblockId);
        $codeProp = $tax->ensureProperty(self::CODE_PROP, 'OneCatalog supplier code', 'S', true);
        $sigProp  = $tax->ensureProperty(self::SIG_PROP, 'OneCatalog price/stock signature', 'S');
        if ($codeProp) {
            \CIBlockElement::SetPropertyValuesEx($id, $iblockId, [$codeProp['ID'] => self::extractCodes($offers)]);
        }
        if ($sigProp) {
            \CIBlockElement::SetPropertyValuesEx($id, $iblockId, [$sigProp['ID'] => $rec['sig']]);
        }

        // Событие для сайтового слоя (§8/§13.7): сырые офферы для раскладки по регионам/складам.
        (new \Bitrix\Main\Event('onecatalog.import', 'OnAfterPriceStockUpdated', [
            'ID' => $id, 'RECORD' => $rec, 'OFFERS' => $offers,
        ]))->send();
    }

    /** Карта public_id → ['id','sig'] одним запросом (для diff в памяти). */
    private static function mapPublicIds(int $iblockId, array $publicIds): array
    {
        $publicIds = array_values(array_filter(array_map('strval', $publicIds)));
        if (!$publicIds) {
            return [];
        }
        $rs = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'PROPERTY_OC_PUBLIC_ID' => $publicIds],
            false,
            false,
            ['ID', 'PROPERTY_OC_PUBLIC_ID', 'PROPERTY_' . self::SIG_PROP]
        );
        $map = [];
        while ($row = $rs->Fetch()) {
            $pid = (string) ($row['PROPERTY_OC_PUBLIC_ID_VALUE'] ?? '');
            if ($pid !== '') {
                $map[$pid] = ['id' => (int) $row['ID'], 'sig' => (string) ($row['PROPERTY_' . self::SIG_PROP . '_VALUE'] ?? '')];
            }
        }
        return $map;
    }

    private static function log(string $status, string $key, string $message): void
    {
        $raw = (string) Settings::get(self::LOG_OPTION, '');
        $log = $raw ? json_decode($raw, true) : [];
        if (!is_array($log)) {
            $log = [];
        }
        array_unshift($log, ['t' => time(), 'status' => $status, 'key' => $key, 'message' => $message]);
        Settings::set(self::LOG_OPTION, json_encode(array_slice($log, 0, 200), JSON_UNESCAPED_UNICODE));
    }

    public static function getLog(): array
    {
        $raw = (string) Settings::get(self::LOG_OPTION, '');
        $log = $raw ? json_decode($raw, true) : [];
        return is_array($log) ? $log : [];
    }
}
