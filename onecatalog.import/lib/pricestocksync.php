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
    public const SIG_PROP        = 'OC_PRICESTOCK_SIG';
    public const CODE_PROP       = 'OC_SUPPLIER_CODE';
    public const LOG_OPTION      = 'B2B_LOG';
    public const PROGRESS_OPTION = 'B2B_PROGRESS';
    public const HISTORY_OPTION  = 'B2B_HISTORY';
    public const HISTORY_MAX     = 30;

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

    /** Остаток по складам: [warehouse_id => qty] (сумма по поставщикам). */
    public static function stockByWarehouse(array $offers): array
    {
        $out = [];
        foreach ($offers as $o) {
            foreach ((array) ($o['products_stocks'] ?? []) as $s) {
                $wid = (int) ($s['warehouse_id'] ?? 0);
                if ($wid > 0) {
                    $out[$wid] = ($out[$wid] ?? 0.0) + (float) ($s['quantity'] ?? 0);
                }
            }
        }
        return $out;
    }

    /** Суммарный остаток по всем складам поставщиков. */
    public static function resolveStock(array $offers): float
    {
        return array_sum(self::stockByWarehouse($offers));
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

    /** Сигнатура того, что будет записано (цена+скидка+кол-во+статус+склады). */
    public static function signature(array $r): string
    {
        $storesPart = '-';
        if (!empty($r['stores']) && is_array($r['stores'])) {
            $s = $r['stores'];
            ksort($s);
            $storesPart = md5((string) json_encode($s));
        }
        return md5(implode('|', [
            $r['regular'] === null ? '-' : (string) (float) $r['regular'],
            ($r['sale'] ?? null) === null ? '-' : (string) (float) $r['sale'],
            (string) (float) ($r['qty'] ?? 0),
            (string) ($r['status'] ?? ''),
            $storesPart,
        ]));
    }

    /** Итоговые значения по офферам + сигнатура. */
    public static function resolveRecord(array $offers, array $cfg): array
    {
        $price = self::resolvePrice($offers, $cfg['region_prio'], $cfg['supplier_prio'], $cfg['strategy'], $cfg['supplier_fix'], $cfg['promo_as_sale']);
        $byWh  = self::stockByWarehouse($offers);
        $stock = array_sum($byWh);
        $available = self::anyAvailable($offers) && $stock > 0;
        $qty = $cfg['decimal'] ? $stock : (float) floor($stock);

        $rec = [
            'regular'    => $price['regular'],
            'sale'       => $price['sale'],
            'purchasing' => $price['purchasing'],
            'qty'        => $qty,
            'status'     => $available ? 'Y' : 'N',
            // Раскладка по складам — только когда включён складской учёт (влияет на сигнатуру).
            'stores'     => !empty($cfg['use_stores']) ? ($cfg['decimal'] ? $byWh : array_map('floor', $byWh)) : null,
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
            'use_stores'    => Settings::b2bUseStores(),
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
            self::log('error', (string) $start, 'feed request failed');
            self::notifyError('feed request failed');
            self::finishProgress();
            return ['ok' => false, 'error' => 'feed', 'more' => false, 'next' => $start, 'scanned' => 0, 'changed' => 0, 'unchanged' => 0, 'total' => 0];
        }

        $data = $page['data'];
        $total = (int) ($page['meta']['counts'] ?? 0);
        $known = (array) ($data['products']['known'] ?? []);

        // На первой странице: сброс прогресса + фиксация состава фида + уведомление.
        if ($start === 0) {
            Settings::set(self::PROGRESS_OPTION, json_encode([
                'scanned' => 0, 'changed' => 0, 'unchanged' => 0,
                'total' => $total, 'started' => time(), 'finished' => false,
            ], JSON_UNESCAPED_UNICODE));
            Settings::recordFeedSeen($data);
            self::notifyChange();
        }

        $map = self::mapPublicIds($iblockId, array_keys($known)); // public_id => ['id'=>, 'sig'=>]
        $cfg = self::cfg();
        $currency   = Settings::b2bCurrency();
        $groupId    = Settings::b2bPriceGroupId();
        $promoGroup = Settings::b2bPromoGroupId();
        $useStores  = Settings::b2bUseStores();
        $warehouses = [];
        foreach ((array) ($data['warehouses'] ?? []) as $wid => $w) {
            $warehouses[(int) $wid] = (string) ($w['name'] ?? ('#' . $wid));
        }

        $scanned = 0;
        $changed = 0;
        $unchanged = 0;
        $missing = [];

        foreach ($known as $publicId => $offers) {
            $publicId = (string) $publicId;
            $offers   = (array) $offers;
            $scanned++;

            $row = $map[$publicId] ?? null;
            if ($row === null) {
                $missing[] = $publicId; // нет товара в каталоге (§13.5)
                continue;
            }
            $rec = self::resolveRecord($offers, $cfg);
            if (($row['sig'] ?? '') === $rec['sig']) {
                $unchanged++;
                continue;
            }
            self::applyResolved((int) $row['id'], $iblockId, $rec, $offers, $groupId, $currency, $promoGroup, $useStores, $warehouses);
            $changed++;
        }

        // §13.5 — ненайденные known-товары: импортировать через Wiki или пропустить.
        $imported = 0;
        if ($missing && Settings::b2bKnownMissing() === 'import') {
            $res = Queue::importBatch($missing);
            foreach ($res as $r) {
                if (in_array(($r['status'] ?? ''), ['created', 'updated'], true)) {
                    $imported++;
                }
            }
            self::log('missing', (string) $start, 'imported ' . $imported . ' of ' . count($missing));
        }

        // §13.5 — unknown-товары поставщиков: отстойник (ручной отбор) или пропуск.
        $staged = 0;
        if (Settings::b2bUnknownMode() === 'staging') {
            $unknown = (array) ($data['products']['unknown'] ?? []);
            $suppliers = (array) ($data['suppliers'] ?? []);
            foreach ($unknown as $offer) {
                if (is_array($offer)) {
                    Staging::collect($offer, $suppliers);
                    $staged++;
                }
            }
        }

        $next = $start + $size;
        $more = ($scanned > 0) && ($next < ($total ?: PHP_INT_MAX));
        self::log('page', (string) $start, "scanned $scanned, changed $changed, unchanged $unchanged");

        // Накопление прогресса; на последней странице — запись в историю запусков.
        self::accumulateProgress($scanned, $changed, $unchanged, $total);
        if (!$more) {
            self::finishProgress();
        }

        return ['ok' => true, 'more' => $more, 'next' => $next, 'scanned' => $scanned, 'changed' => $changed, 'unchanged' => $unchanged, 'total' => $total, 'missing' => count($missing), 'imported' => $imported, 'staged' => $staged];
    }

    // ===================== §13.6 Авто-расписание (CAgent) =====================

    public const MODULE_ID = 'onecatalog.import';

    /** Строка вызова агента (она же возвращается агентом для перепланирования). */
    public static function agentName(): string
    {
        return '\\OneCatalog\\Import\\PriceStockSync::agentRun();';
    }

    /** Агент: полный прогон синка (страница за страницей). Возвращает себя. */
    public static function agentRun(): string
    {
        if (Settings::b2bConfigured()) {
            $size = Settings::b2bPageSize();
            $r = self::processPage(0, $size);
            $guard = 0;
            while (!empty($r['more']) && $guard++ < 5000) {
                $r = self::processPage((int) $r['next'], $size);
            }
        }
        return self::agentName();
    }

    /** Перерегистрировать агента по текущей настройке расписания (off → снять). */
    public static function reschedule(): void
    {
        if (!class_exists('\\CAgent')) {
            return;
        }
        $name = self::agentName();
        \CAgent::RemoveAgent($name, self::MODULE_ID);
        $interval = Settings::b2bScheduleInterval();
        if ($interval > 0) {
            \CAgent::AddAgent($name, self::MODULE_ID, 'N', $interval, '', 'Y');
        }
    }

    // ===================== Прогресс / история / уведомления =====================

    private static function accumulateProgress(int $scanned, int $changed, int $unchanged, int $total): void
    {
        $p = self::getProgress();
        $p['scanned']   = (int) ($p['scanned'] ?? 0) + $scanned;
        $p['changed']   = (int) ($p['changed'] ?? 0) + $changed;
        $p['unchanged'] = (int) ($p['unchanged'] ?? 0) + $unchanged;
        $p['total']     = $total ?: (int) ($p['total'] ?? 0);
        Settings::set(self::PROGRESS_OPTION, json_encode($p, JSON_UNESCAPED_UNICODE));
    }

    private static function finishProgress(): void
    {
        $p = self::getProgress();
        if (!empty($p['finished'])) {
            return;
        }
        $p['finished'] = true;
        $p['finished_at'] = time();
        Settings::set(self::PROGRESS_OPTION, json_encode($p, JSON_UNESCAPED_UNICODE));

        $h = self::getHistory();
        array_unshift($h, [
            'started'   => (int) ($p['started'] ?? 0),
            'finished'  => (int) $p['finished_at'],
            'total'     => (int) ($p['total'] ?? 0),
            'scanned'   => (int) ($p['scanned'] ?? 0),
            'changed'   => (int) ($p['changed'] ?? 0),
            'unchanged' => (int) ($p['unchanged'] ?? 0),
        ]);
        Settings::set(self::HISTORY_OPTION, json_encode(array_slice($h, 0, self::HISTORY_MAX), JSON_UNESCAPED_UNICODE));
    }

    public static function getProgress(): array
    {
        $raw = (string) Settings::get(self::PROGRESS_OPTION, '');
        $v = $raw !== '' ? json_decode($raw, true) : [];
        return is_array($v) ? $v : [];
    }

    public static function getHistory(): array
    {
        $raw = (string) Settings::get(self::HISTORY_OPTION, '');
        $v = $raw !== '' ? json_decode($raw, true) : [];
        return is_array($v) ? $v : [];
    }

    /** Письмо при изменении состава фида (один раз на новый состав). */
    private static function notifyChange(): void
    {
        if (!Settings::b2bNotifyEnabled()) {
            return;
        }
        $new = Settings::b2bNewItems();
        if (!$new) {
            return;
        }
        $sig = [];
        foreach ($new as $t => $items) {
            $sig[$t] = array_keys($items);
        }
        $hash = md5((string) json_encode($sig));
        if ((string) Settings::get('B2B_NOTIFIED_HASH', '') === $hash) {
            return;
        }
        $lines = [];
        foreach ($new as $t => $items) {
            foreach ($items as $id => $label) {
                $lines[] = "- [$t #$id] $label";
            }
        }
        self::mail(
            'OneCatalog: B2B feed changed — review needed',
            "Состав B2B-фида изменился. Новые, ещё не настроенные элементы:\n\n"
            . implode("\n", $lines)
            . "\n\nОни не применяются автоматически. Настройте на странице «Цены и остатки»."
        );
        Settings::set('B2B_NOTIFIED_HASH', $hash);
    }

    private static function notifyError(string $message): void
    {
        if (!Settings::b2bNotifyEnabled()) {
            return;
        }
        $last = (int) Settings::get('B2B_ERR_NOTIFIED', 0);
        if ((time() - $last) < 3600) {
            return;
        }
        self::mail('OneCatalog: B2B sync failed', "Синхронизация цен/остатков завершилась с ошибкой:\n\n" . $message);
        Settings::set('B2B_ERR_NOTIFIED', (string) time());
    }

    private static function mail(string $subject, string $body): void
    {
        $to = (string) \COption::GetOptionString('main', 'email_from', '');
        if ($to === '' || !function_exists('bxmail')) {
            return;
        }
        bxmail($to, $subject, $body, 'Content-Type: text/plain; charset=utf-8');
    }

    /** Применить цену/остаток к элементу (единственное место записи). */
    private static function applyResolved(int $id, int $iblockId, array $rec, array $offers, int $groupId, string $currency, int $promoGroup = 0, bool $useStores = false, array $warehouses = []): void
    {
        // Цена → CPrice (базовый тип цены). Промо — в отдельный тип цены (если задан).
        if ($rec['regular'] !== null && $groupId > 0) {
            self::setPrice($id, $groupId, (float) $rec['regular'], $currency);
        }
        if ($rec['sale'] !== null && $promoGroup > 0) {
            self::setPrice($id, $promoGroup, (float) $rec['sale'], $currency);
        }

        // Карточка товара существует (иначе создаём без цены, §5.6).
        if (!\CCatalogProduct::GetByID($id)) {
            \CCatalogProduct::Add(['ID' => $id, 'QUANTITY' => $rec['qty']]);
        }

        // Остаток: раскладка по складам (нативно) ИЛИ суммарный QUANTITY.
        if ($useStores && !empty($rec['stores']) && is_array($rec['stores'])) {
            foreach ($rec['stores'] as $wid => $amount) {
                $storeId = self::ensureStore((int) $wid, (string) ($warehouses[(int) $wid] ?? ''));
                if ($storeId > 0) {
                    self::setStoreAmount($id, $storeId, (float) $amount);
                }
            }
        }
        // Суммарное количество выставляем всегда (типовые компоненты опираются на него).
        \CCatalogProduct::Update($id, ['QUANTITY' => $rec['qty']]);

        // Коды поставщиков + сигнатура — служебные, в Meta (не свойства товара).
        Meta::set($id, 'supplier_code', implode(',', self::extractCodes($offers)));
        Meta::set($id, 'pricestock_sig', $rec['sig']);

        // Событие для сайтового слоя (§8/§13.7): сырые офферы для раскладки по регионам/складам.
        (new \Bitrix\Main\Event('onecatalog.import', 'OnAfterPriceStockUpdated', [
            'ID' => $id, 'RECORD' => $rec, 'OFFERS' => $offers,
        ]))->send();
    }

    /** Установить цену товара в указанном типе цены (find-or-create CPrice). */
    private static function setPrice(int $productId, int $groupId, float $price, string $currency): void
    {
        $existing = \CPrice::GetList([], ['PRODUCT_ID' => $productId, 'CATALOG_GROUP_ID' => $groupId])->Fetch();
        $fields = [
            'PRODUCT_ID'       => $productId,
            'CATALOG_GROUP_ID' => $groupId,
            'PRICE'            => $price,
            'CURRENCY'         => $currency,
        ];
        if ($existing) {
            \CPrice::Update($existing['ID'], $fields);
        } else {
            \CPrice::Add($fields);
        }
    }

    /** find-or-create склад Битрикса по стабильному XML_ID = oc_wh_<id>. */
    private static function ensureStore(int $warehouseId, string $title): int
    {
        $xmlId = 'oc_wh_' . $warehouseId;
        $rs = \CCatalogStore::GetList([], ['XML_ID' => $xmlId], false, false, ['ID']);
        if ($row = $rs->Fetch()) {
            return (int) $row['ID'];
        }
        $id = \CCatalogStore::Add([
            'TITLE'  => $title !== '' ? $title : ('Warehouse #' . $warehouseId),
            'ACTIVE' => 'Y',
            'XML_ID' => $xmlId,
        ]);
        return $id ? (int) $id : 0;
    }

    /** Установить остаток товара на складе (find-or-create CCatalogStoreProduct). */
    private static function setStoreAmount(int $productId, int $storeId, float $amount): void
    {
        $rs = \CCatalogStoreProduct::GetList([], ['PRODUCT_ID' => $productId, 'STORE_ID' => $storeId], false, false, ['ID']);
        if ($row = $rs->Fetch()) {
            \CCatalogStoreProduct::Update((int) $row['ID'], ['AMOUNT' => $amount]);
        } else {
            \CCatalogStoreProduct::Add(['PRODUCT_ID' => $productId, 'STORE_ID' => $storeId, 'AMOUNT' => $amount]);
        }
    }

    /**
     * Карта public_id → ['id','sig'] для diff в памяти.
     * Элементы — одним запросом по свойству OC_PUBLIC_ID; сигнатуры цены/остатка —
     * из служебной таблицы Meta (не свойство товара), тоже одним запросом.
     */
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
            ['ID', 'PROPERTY_OC_PUBLIC_ID']
        );
        $byId = [];
        while ($row = $rs->Fetch()) {
            $pid = (string) ($row['PROPERTY_OC_PUBLIC_ID_VALUE'] ?? '');
            if ($pid !== '') {
                $byId[$pid] = (int) $row['ID'];
            }
        }
        $sigs = Meta::getMany(array_values($byId), 'pricestock_sig');
        $map = [];
        foreach ($byId as $pid => $id) {
            $map[$pid] = ['id' => $id, 'sig' => $sigs[$id] ?? ''];
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
