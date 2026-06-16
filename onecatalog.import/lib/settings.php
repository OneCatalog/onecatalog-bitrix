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
