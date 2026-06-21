<?php

namespace OneCatalog\Import;

use Bitrix\Main\Config\Option;

/**
 * Слой Settings (§4, §7): хранение/валидация настроек поверх Option.
 *
 * Инварианты:
 *  - приоритет константы/ENV над опцией токена (§7);
 *  - кламп шага импорта (минимум 10) (§6, §7);
 *  - язык — только из списка поддерживаемых (§2.1, §7).
 */
final class Settings
{
    public const MODULE_ID = 'onecatalog.import';

    public const SUPPORTED_LANGS = ['en', 'ru', 'ar', 'zh', 'kk'];
    public const MIN_STEP = 10;
    public const DEFAULT_BASE = 'https://api.onecatalog.net/wiki/v1';
    public const DEFAULT_PICKER = 'https://tools.onecatalog.net';

    public static function get(string $name, $default = null)
    {
        return Option::get(self::MODULE_ID, $name, $default);
    }

    public static function set(string $name, $value): void
    {
        Option::set(self::MODULE_ID, $name, (string) $value);
    }

    /** Базовый URL Wiki API (переопределяемо константой). */
    public static function apiBase(): string
    {
        if (defined('ONECATALOG_API_BASE') && ONECATALOG_API_BASE) {
            return rtrim((string) ONECATALOG_API_BASE, '/');
        }
        $base = (string) self::get('API_BASE_URL', self::DEFAULT_BASE);
        return rtrim($base ?: self::DEFAULT_BASE, '/');
    }

    /**
     * Токен X-API-Key. Приоритет: константа ONECATALOG_API_TOKEN (dbconn/.settings)
     * над опцией (§7). Пустой токен допустим — публичный каталог доступен (§2.1).
     */
    public static function apiToken(): string
    {
        if (defined('ONECATALOG_API_TOKEN') && ONECATALOG_API_TOKEN) {
            return (string) ONECATALOG_API_TOKEN;
        }
        return (string) self::get('API_TOKEN', '');
    }

    /** Язык запросов; только из поддерживаемого списка, иначе en. */
    public static function lang(): string
    {
        $lang = (string) self::get('LANG', 'en');
        return in_array($lang, self::SUPPORTED_LANGS, true) ? $lang : 'en';
    }

    /** Размер порции очереди, кламп ≥ 10 (§6). */
    public static function step(): int
    {
        $step = (int) self::get('STEP', self::MIN_STEP);
        return max(self::MIN_STEP, $step);
    }

    /** Статус активности новых товаров (только при создании, §5.6). */
    public static function newActive(): string
    {
        return self::get('NEW_ACTIVE', 'Y') === 'N' ? 'N' : 'Y';
    }

    public static function catalogIblockId(): int
    {
        return (int) self::get('CATALOG_IBLOCK_ID', 0);
    }

    /** Origin виджета выбора товаров (picker), фиксируется настройкой (§2.4). */
    public static function pickerBase(): string
    {
        $v = trim((string) self::get('PICKER_BASE', self::DEFAULT_PICKER));
        return rtrim($v ?: self::DEFAULT_PICKER, '/');
    }

    public static function bool(string $name, bool $default = false): bool
    {
        $v = self::get($name, $default ? 'Y' : 'N');
        return $v === 'Y' || $v === '1' || $v === 'yes';
    }

    /** Включён ли строгий ручной маппинг характеристик (§5.4). */
    public static function manualMapping(): bool
    {
        return self::bool('SPEC_MANUAL_MAPPING');
    }

    /**
     * Карта маппинга: specification_id => {prop:<propId>, true:<label>, false:<label>}.
     * Ключ — стабильный specification_id (не переводимое название, §5.4).
     */
    public static function specMap(): array
    {
        $raw = (string) self::get('SPEC_MAP', '');
        if ($raw === '') {
            return [];
        }
        $map = json_decode($raw, true);
        return is_array($map) ? $map : [];
    }

    public static function setSpecMap(array $map): void
    {
        self::set('SPEC_MAP', json_encode($map, JSON_UNESCAPED_UNICODE));
    }

    // ===================== B2B: цены и остатки (§13) =====================

    public const DEFAULT_B2B_BASE = 'https://api.onecatalog.net/b2b/v1';
    public const B2B_PAGE_DEFAULT = 200;

    public static function b2bBase(): string
    {
        if (defined('ONECATALOG_B2B_BASE') && ONECATALOG_B2B_BASE) {
            return rtrim((string) ONECATALOG_B2B_BASE, '/');
        }
        $v = (string) self::get('B2B_BASE_URL', self::DEFAULT_B2B_BASE);
        return rtrim($v ?: self::DEFAULT_B2B_BASE, '/');
    }

    public static function b2bUrlKey(): string
    {
        if (defined('ONECATALOG_B2B_KEY') && ONECATALOG_B2B_KEY) {
            return (string) ONECATALOG_B2B_KEY;
        }
        return trim((string) self::get('B2B_KEY', ''));
    }

    public static function b2bPrivateKey(): string
    {
        if (defined('ONECATALOG_B2B_PRIVATE_KEY') && ONECATALOG_B2B_PRIVATE_KEY) {
            return (string) ONECATALOG_B2B_PRIVATE_KEY;
        }
        return trim((string) self::get('B2B_PRIVATE_KEY', ''));
    }

    public static function b2bConfigured(): bool
    {
        return self::b2bUrlKey() !== '' && self::b2bPrivateKey() !== '';
    }

    public static function b2bPriceStrategy(): string
    {
        $s = (string) self::get('B2B_PRICE_STRATEGY', 'min');
        return in_array($s, ['priority', 'min', 'supplier'], true) ? $s : 'min';
    }

    /** @return int[] упорядоченный приоритет регионов */
    public static function b2bRegionPriority(): array
    {
        return self::intList(self::get('B2B_REGION_PRIORITY', ''));
    }

    /** @return int[] упорядоченный приоритет поставщиков */
    public static function b2bSupplierPriority(): array
    {
        return self::intList(self::get('B2B_SUPPLIER_PRIORITY', ''));
    }

    public static function b2bSupplierFixed(): int
    {
        return (int) self::get('B2B_SUPPLIER_FIXED', 0);
    }

    /** Тип цены (CATALOG_GROUP_ID); 0 → базовый тип цены каталога. */
    public static function b2bPriceGroupId(): int
    {
        $id = (int) self::get('B2B_PRICE_GROUP', 0);
        if ($id > 0) {
            return $id;
        }
        if (class_exists('\\CCatalogGroup')) {
            $base = \CCatalogGroup::GetBaseGroup();
            return (int) ($base['ID'] ?? 0);
        }
        return 0;
    }

    public static function b2bCurrency(): string
    {
        $c = trim((string) self::get('B2B_CURRENCY', ''));
        if ($c !== '') {
            return $c;
        }
        if (class_exists('\\Bitrix\\Currency\\CurrencyManager')) {
            $base = \Bitrix\Currency\CurrencyManager::getBaseCurrency();
            if ($base) {
                return (string) $base;
            }
        }
        return 'RUB';
    }

    public static function b2bPageSize(): int
    {
        $n = (int) self::get('B2B_PAGE_SIZE', self::B2B_PAGE_DEFAULT);
        return max(50, min(500, $n));
    }

    public static function b2bNotifyEnabled(): bool
    {
        return self::bool('B2B_NOTIFY', true);
    }

    /** Подтверждённый справочник {regions,warehouses,suppliers} id=>label. */
    public static function b2bCatalogMeta(): array
    {
        return self::jsonOpt('B2B_CATALOG_META');
    }

    /** Последний справочник, увиденный в фиде. */
    public static function b2bFeedMeta(): array
    {
        return self::jsonOpt('B2B_FEED_META');
    }

    /** Лейблы справочника по типу (последнее из фида + подтверждённое). */
    public static function b2bRef(string $type): array
    {
        $cm = (array) (self::b2bCatalogMeta()[$type] ?? []);
        $fm = (array) (self::b2bFeedMeta()[$type] ?? []);
        return $fm + $cm;
    }

    /** Новые (не подтверждённые) элементы по типам: [type => [id=>label]]. */
    public static function b2bNewItems(): array
    {
        $out = [];
        foreach (['regions', 'suppliers', 'warehouses'] as $type) {
            $ack = array_map('intval', array_keys((array) (self::b2bCatalogMeta()[$type] ?? [])));
            $cur = (array) (self::b2bFeedMeta()[$type] ?? []);
            $new = [];
            foreach ($cur as $id => $label) {
                if (!in_array((int) $id, $ack, true)) {
                    $new[(int) $id] = (string) $label;
                }
            }
            if ($new) {
                $out[$type] = $new;
            }
        }
        return $out;
    }

    /** Зафиксировать состав справочников из ответа фида (data). */
    public static function recordFeedSeen(array $data): void
    {
        $meta = ['regions' => [], 'warehouses' => [], 'suppliers' => []];
        foreach ((array) ($data['regions'] ?? []) as $id => $r) {
            $meta['regions'][(int) $id] = (string) ($r['menutitle'] ?? $r['slug'] ?? ('#' . $id));
        }
        foreach ((array) ($data['warehouses'] ?? []) as $id => $w) {
            $label = trim((string) ($w['name'] ?? '') . (empty($w['city']) ? '' : ' (' . $w['city'] . ')'));
            $meta['warehouses'][(int) $id] = $label !== '' ? $label : ('#' . $id);
        }
        foreach ((array) ($data['suppliers'] ?? []) as $id => $s) {
            $meta['suppliers'][(int) $id] = (string) ($s['name'] ?? ('#' . $id));
        }
        self::set('B2B_FEED_META', json_encode($meta, JSON_UNESCAPED_UNICODE));
    }

    /** Принять текущий состав фида как подтверждённый (после настройки). */
    public static function acknowledgeFeed(): void
    {
        $fm = self::b2bFeedMeta();
        if ($fm) {
            self::set('B2B_CATALOG_META', json_encode($fm, JSON_UNESCAPED_UNICODE));
        }
        self::set('B2B_NOTIFIED_HASH', '');
    }

    private static function jsonOpt(string $name): array
    {
        $raw = (string) self::get($name, '');
        $v = $raw !== '' ? json_decode($raw, true) : [];
        return is_array($v) ? $v : [];
    }

    /** Список целых из строки «1,2,3» или массива (порядок сохраняется). */
    private static function intList($raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $out = [];
        foreach ((array) $raw as $v) {
            $v = (int) $v;
            if ($v > 0 && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }
        return $out;
    }

    /**
     * Нормализация/валидация значений перед сохранением из формы настроек.
     * Возвращает очищенный массив (кламп шага, фильтр языка).
     */
    public static function sanitize(array $input): array
    {
        $out = $input;

        if (isset($out['STEP'])) {
            $out['STEP'] = (string) max(self::MIN_STEP, (int) $out['STEP']);
        }
        if (isset($out['LANG']) && !in_array($out['LANG'], self::SUPPORTED_LANGS, true)) {
            $out['LANG'] = 'en';
        }
        if (isset($out['API_BASE_URL'])) {
            $out['API_BASE_URL'] = rtrim(trim((string) $out['API_BASE_URL']), '/') ?: self::DEFAULT_BASE;
        }
        if (isset($out['PICKER_BASE'])) {
            $out['PICKER_BASE'] = rtrim(trim((string) $out['PICKER_BASE']), '/') ?: self::DEFAULT_PICKER;
        }
        if (isset($out['API_TOKEN'])) {
            $out['API_TOKEN'] = trim((string) $out['API_TOKEN']);
        }

        return $out;
    }
}
