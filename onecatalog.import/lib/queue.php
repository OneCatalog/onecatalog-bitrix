<?php

namespace OneCatalog\Import;

use Bitrix\Main\Application;

/**
 * Слой Queue (§4, §6): фоновая очередь, порции по шагу импорта (минимум 10).
 *
 * Два механизма (§6):
 *  1. AJAX-степпер (primary) — порция импортируется синхронно в одном запросе;
 *  2. агент CAgent (фолбэк) — одна порция за вызов, далее перезапланируется.
 *
 * Очередь хранится в таблице onecatalog_queue (не в опциях). Идемпотентность
 * очереди: повторная постановка того же public_id не плодит задачи (find-or-skip).
 *
 * TODO: enqueue(), processBatch(), лог последних N результатов.
 */
final class Queue
{
    public const TABLE = 'onecatalog_queue';

    /** Точка входа фонового агента (зарегистрирован при установке). */
    public static function agent(): string
    {
        // TODO: взять следующую порцию pending, импортировать, пометить.
        return "\\OneCatalog\\Import\\Queue::agent();";
    }
}
