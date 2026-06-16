<?php

namespace OneCatalog\Import;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Uri;

/**
 * Слой Api (§4): клиент OneCatalog Wiki API.
 *
 * Внешние контракты (§2.1):
 *  - base URL переопределяем (Settings/константа);
 *  - аутентификация заголовком X-API-Key (пустой токен → публичный каталог);
 *  - параметр lang добавляется ко ВСЕМ запросам;
 *  - ответ: { data: object|array, success: bool }, списки — + meta.counts;
 *  - справочники брать ОДНИМ запросом с высоким limit (иначе дефолтный лимит
 *    инстанса молча обрежет выдачу).
 *
 * Устойчивость (§5.5): сетевая/HTTP-ошибка одного запроса → null, не исключение.
 */
final class Api
{
    private const HIGH_LIMIT = 1000;
    private const TIMEOUT = 40;

    private string $base;
    private string $token;
    private string $lang;

    public function __construct(?string $base = null, ?string $token = null, ?string $lang = null)
    {
        $this->base = $base ?? Settings::apiBase();
        $this->token = $token ?? Settings::apiToken();
        $this->lang = $lang ?? Settings::lang();
    }

    public function getLang(): string
    {
        return $this->lang;
    }

    /**
     * GET с обёрткой ответа. Возвращает содержимое data (или весь ответ при $raw),
     * либо null при любой ошибке (§5.5).
     *
     * @param string $path  путь от base, напр. '/products/OC.IND.1/'
     * @param array  $query доп. query-параметры (lang добавляется автоматически)
     */
    public function get(string $path, array $query = [], bool $raw = false)
    {
        $query['lang'] = $this->lang;

        $uri = new Uri($this->base . '/' . ltrim($path, '/'));
        $uri->addParams($query);

        $http = new HttpClient([
            'socketTimeout' => self::TIMEOUT,
            'streamTimeout' => self::TIMEOUT,
            'waitResponse'  => true,
        ]);
        $http->setHeader('Accept', 'application/json');
        if ($this->token !== '') {
            $http->setHeader('X-API-Key', $this->token);
        }

        $body = $http->get($uri->getUri());
        $status = $http->getStatus();

        if ($body === false || $status < 200 || $status >= 300) {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || empty($decoded['success'])) {
            return null;
        }

        return $raw ? $decoded : ($decoded['data'] ?? null);
    }

    /** Один товар по public_id или slug — полный payload (§2.2). */
    public function product(string $publicIdOrSlug): ?array
    {
        $data = $this->get('/products/' . rawurlencode($publicIdOrSlug) . '/');
        return is_array($data) ? $data : null;
    }

    /** Список товаров (пагинация ?start&limit&filter_by&search). */
    public function products(int $start = 0, int $limit = 50, array $query = []): ?array
    {
        $query = array_merge($query, ['start' => $start, 'limit' => $limit]);
        $resp = $this->get('/products/', $query, true);
        return is_array($resp) ? $resp : null;
    }

    /** Лёгкий список только public_id. */
    public function productIds(): ?array
    {
        $data = $this->get('/products_ids/');
        return is_array($data) ? $data : null;
    }

    /**
     * Справочник характеристик — одним запросом с высоким limit (§2.1).
     * Отдаёт тип характеристики (text|boolean|numeric) для маппинга (§5.4).
     */
    public function specifications(): array
    {
        return $this->fetchAll('/specifications/');
    }

    /**
     * Справочник категорий — одним запросом с высоким limit.
     * Содержит parent_category для построения дерева разделов (§5.4, в.6).
     */
    public function categories(): array
    {
        return $this->fetchAll('/categories/');
    }

    public function brands(): array
    {
        return $this->fetchAll('/brands/');
    }

    public function countries(): array
    {
        return $this->fetchAll('/countries/');
    }

    public function measurementUnits(): array
    {
        return $this->fetchAll('/measurement-units/');
    }

    /**
     * Справочник одним запросом (limit=1000) + страховочная дочитка страницами,
     * сверяясь с meta.counts (§2.1). Возвращает [] при ошибке.
     */
    private function fetchAll(string $path): array
    {
        $resp = $this->get($path, ['start' => 0, 'limit' => self::HIGH_LIMIT], true);
        if (!is_array($resp) || !isset($resp['data']) || !is_array($resp['data'])) {
            return [];
        }

        $items = $resp['data'];
        $total = (int) ($resp['meta']['counts'] ?? count($items));

        // Дочитываем, если инстанс всё же обрезал по своему дефолту.
        $start = count($items);
        while ($start < $total) {
            $next = $this->get($path, ['start' => $start, 'limit' => self::HIGH_LIMIT], true);
            $page = is_array($next) ? ($next['data'] ?? []) : [];
            if (!$page) {
                break;
            }
            $items = array_merge($items, $page);
            $start += count($page);
        }

        return $items;
    }

    /** Бинарный файл изображения по URL media_files (скачивается в Media-слое). */
    public function fetchBinary(string $url): ?string
    {
        $http = new HttpClient([
            'socketTimeout' => self::TIMEOUT,
            'streamTimeout' => self::TIMEOUT,
            'waitResponse'  => true,
        ]);
        if ($this->token !== '') {
            $http->setHeader('X-API-Key', $this->token);
        }
        $body = $http->get($url);
        $status = $http->getStatus();

        if ($body === false || $status < 200 || $status >= 300 || $body === '') {
            return null;
        }
        return $body;
    }
}
