<?php

namespace OneCatalog\Import;

/**
 * Слой Units (§4, §5.6): универсальный конвертер единиц источник → единицы платформы.
 *
 * OneCatalog отдаёт величины в НАИМЕНЬШЕЙ единице: вес — граммы, размеры —
 * миллиметры (подпись локализована: «мм»/«г»). Внутренние единицы каталога Битрикса
 * тоже г/мм → конверсия почти тождественна, НО конвертер обязателен: нормализовать
 * локализованную подпись + подстраховаться, если источник отдаст кг/см.
 *
 * Базовые единицы: вес → грамм (g), длина → миллиметр (mm).
 */
final class Units
{
    /** Коэффициенты к базовой единице (грамм). */
    private const WEIGHT = [
        'g'  => 1.0,
        'kg' => 1000.0,
        't'  => 1_000_000.0,
        'mg' => 0.001,
        'lb' => 453.59237,
        'oz' => 28.349523125,
    ];

    /** Коэффициенты к базовой единице (миллиметр). */
    private const LENGTH = [
        'mm'   => 1.0,
        'cm'   => 10.0,
        'dm'   => 100.0,
        'm'    => 1000.0,
        'inch' => 25.4,
        'ft'   => 304.8,
    ];

    /** Нормализация локализованных/вариативных подписей → канонический код. */
    private const ALIASES = [
        // вес
        'г' => 'g', 'гр' => 'g', 'g' => 'g', 'gram' => 'g', 'grams' => 'g',
        'кг' => 'kg', 'kg' => 'kg',
        'т' => 't', 't' => 't',
        'мг' => 'mg', 'mg' => 'mg',
        'lb' => 'lb', 'lbs' => 'lb', 'фунт' => 'lb',
        'oz' => 'oz', 'унц' => 'oz',
        // длина
        'мм' => 'mm', 'mm' => 'mm',
        'см' => 'cm', 'cm' => 'cm',
        'дм' => 'dm', 'dm' => 'dm',
        'м' => 'm', 'm' => 'm', 'метр' => 'm',
        'дюйм' => 'inch', 'inch' => 'inch', 'in' => 'inch', '"' => 'inch',
        'фут' => 'ft', 'ft' => 'ft',
    ];

    /**
     * Привести подпись единицы из payload к каноническому коду.
     * Принимает строку или объект {id|label|slug|name} (как приходит length_unit).
     */
    public static function normalizeUnit($unit, string $fallback): string
    {
        if (is_array($unit)) {
            $unit = $unit['slug'] ?? $unit['label'] ?? $unit['id'] ?? $unit['name'] ?? '';
        }
        $key = mb_strtolower(trim((string) $unit));
        if ($key === '') {
            return $fallback;
        }
        return self::ALIASES[$key] ?? $fallback;
    }

    /** Конверсия веса в граммы (внутренняя единица каталога Битрикса). */
    public static function toGrams($value, $sourceUnit = null): ?float
    {
        return self::convert($value, $sourceUnit, 'g', self::WEIGHT, 'g');
    }

    /** Конверсия длины в миллиметры (внутренняя единица каталога Битрикса). */
    public static function toMillimeters($value, $sourceUnit = null): ?float
    {
        return self::convert($value, $sourceUnit, 'mm', self::LENGTH, 'mm');
    }

    private static function convert($value, $sourceUnit, string $target, array $table, string $fallbackUnit): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $from = self::normalizeUnit($sourceUnit, $fallbackUnit);
        if (!isset($table[$from], $table[$target])) {
            return (float) $value; // неизвестная единица — не искажаем
        }
        $inBase = (float) $value * $table[$from];
        return $inBase / $table[$target];
    }
}
