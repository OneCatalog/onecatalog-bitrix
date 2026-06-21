<?php

namespace OneCatalog\Import;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Uri;

/**
 * Клиент B2B API OneCatalog (цены и остатки ритейлера) — §13 стандарта.
 *
 * Эндпоинт: /b2b/v1/retailer-share-products/{url_key}/?private_key=…
 * Авторизация ОТЛИЧАЕТСЯ от Wiki API: ключ в пути (url_key) + private_key в query.
 * Ответ: data.products.{known:{public_id:[offer,…]}, unknown:[offer,…]},
 *        data.suppliers/warehouses/regions (верхнеуровневые справочники), meta.counts.
 * offer: { code, public_id, status, supplier_id, products_stocks[{warehouse_id,quantity}],
 *          product_prices[{region_id,base_price,promo_price,purchasing_price}] }.
 * Ошибки → null (§5.5).
 */
final class B2bApi
{
    private const TIMEOUT = 60;

    private string $base;
    private string $urlKey;
    private string $privateKey;

    public function __construct(?string $base = null, ?string $urlKey = null, ?string $privateKey = null)
    {
        $this->base = $base ?? Settings::b2bBase();
        $this->urlKey = $urlKey ?? Settings::b2bUrlKey();
        $this->privateKey = $privateKey ?? Settings::b2bPrivateKey();
    }

    public function configured(): bool
    {
        return $this->urlKey !== '' && $this->privateKey !== '';
    }

    /** Страница фида (start/limit) — полный ответ {data,meta} или null. */
    public function fetchPage(int $start = 0, int $limit = 200): ?array
    {
        if (!$this->configured()) {
            return null;
        }
        $uri = new Uri($this->base . '/retailer-share-products/' . rawurlencode($this->urlKey) . '/');
        $uri->addParams([
            'private_key' => $this->privateKey,
            'start'       => max(0, $start),
            'limit'       => max(1, $limit),
        ]);

        $http = new HttpClient(['socketTimeout' => self::TIMEOUT, 'streamTimeout' => self::TIMEOUT, 'waitResponse' => true]);
        $http->setHeader('Accept', 'application/json');

        $body = $http->get($uri->getUri());
        $status = $http->getStatus();
        if ($body === false || $status < 200 || $status >= 300) {
            return null;
        }
        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['success']) || !isset($json['data']) || !is_array($json['data'])) {
            return null;
        }
        return $json;
    }

    /** Общее число позиций в фиде (meta.counts). 0 при ошибке. */
    public function total(): int
    {
        $p = $this->fetchPage(0, 1);
        return $p ? (int) ($p['meta']['counts'] ?? 0) : 0;
    }

    /**
     * Разведка справочников для настроек: регионы, склады, поставщики (верхнеуровневые).
     * @return array{regions:array<int,string>,warehouses:array<int,string>,suppliers:array<int,string>}|null
     */
    public function discover(int $scan = 500): ?array
    {
        $page = $this->fetchPage(0, $scan);
        if ($page === null) {
            return null;
        }
        $data = $page['data'];

        $regions = [];
        foreach ((array) ($data['regions'] ?? []) as $id => $r) {
            $regions[(int) $id] = (string) ($r['menutitle'] ?? $r['slug'] ?? ('#' . $id));
        }
        $warehouses = [];
        foreach ((array) ($data['warehouses'] ?? []) as $id => $w) {
            $label = trim((string) ($w['name'] ?? '') . (empty($w['city']) ? '' : ' (' . $w['city'] . ')'));
            $warehouses[(int) $id] = $label !== '' ? $label : ('#' . $id);
        }
        $suppliers = [];
        foreach ((array) ($data['suppliers'] ?? []) as $id => $s) {
            $suppliers[(int) $id] = (string) ($s['name'] ?? ('#' . $id));
        }

        return ['regions' => $regions, 'warehouses' => $warehouses, 'suppliers' => $suppliers];
    }
}
