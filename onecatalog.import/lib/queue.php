<?php

namespace OneCatalog\Import;

/**
 * Слой Queue (§4, §6): импорт порциями + лог.
 *
 * Основной механизм для Битрикса (§6, в.11) — **AJAX-степпер**: вкладка админа
 * шлёт порции public_id (≤ «шаг импорта»), каждая импортируется синхронно в одном
 * запросе через ProductImporter; JS гонит порции и показывает прогресс.
 * Агент CAgent — фоновый фолбэк (одна порция за вызов) — следующий инкремент.
 *
 * Лог последних N результатов хранится в опции (для отображения прогресса).
 */
final class Queue
{
    public const TABLE = 'onecatalog_queue';
    private const LOG_OPTION = 'IMPORT_LOG';
    private const LOG_MAX = 100;

    /**
     * Импортировать порцию public_id синхронно (AJAX-степпер).
     *
     * @param string[] $publicIds
     * @return array<int,array{public_id:string,status:string,id?:int,message?:string}>
     */
    public static function importBatch(array $publicIds): array
    {
        $iblockId = Settings::catalogIblockId();
        $importer = new ProductImporter(new Api(), $iblockId);

        $results = [];
        foreach ($publicIds as $pid) {
            $pid = trim((string) $pid);
            if ($pid === '') {
                continue;
            }
            try {
                $r = $importer->import($pid);
            } catch (\Throwable $e) {
                // Деградация без падений (§5.5): битый id не валит порцию.
                $r = ['status' => 'error', 'message' => $e->getMessage()];
            }
            $r['public_id'] = $pid;
            $results[] = $r;
        }

        self::pushLog($results);
        return $results;
    }

    /** Лог последних результатов (для UI прогресса). */
    public static function getLog(): array
    {
        $raw = (string) Settings::get(self::LOG_OPTION, '');
        if ($raw === '') {
            return [];
        }
        $log = json_decode($raw, true);
        return is_array($log) ? $log : [];
    }

    public static function clearLog(): void
    {
        Settings::set(self::LOG_OPTION, '');
    }

    private static function pushLog(array $entries): void
    {
        $log = self::getLog();
        foreach ($entries as $e) {
            $log[] = [
                'ts'        => time(),
                'public_id' => (string) ($e['public_id'] ?? ''),
                'status'    => (string) ($e['status'] ?? ''),
                'message'   => (string) ($e['message'] ?? ''),
            ];
        }
        $log = array_slice($log, -self::LOG_MAX);
        Settings::set(self::LOG_OPTION, json_encode($log, JSON_UNESCAPED_UNICODE));
    }

    /** Точка входа фонового агента (фолбэк) — реализация в следующем инкременте. */
    public static function agent(): string
    {
        return "\\OneCatalog\\Import\\Queue::agent();";
    }
}
