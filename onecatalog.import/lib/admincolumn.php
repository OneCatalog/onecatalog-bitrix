<?php

namespace OneCatalog\Import;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

/**
 * Колонка OneCatalog ID в списке товаров (WC-паритет). Свойство OC_PUBLIC_ID уже
 * нативно доступно как колонка списка (и редактируемо) — здесь мы лишь украшаем её
 * ячейку: клик копирует значение, рядом ссылка «Открыть» (если задан OPEN_BASE).
 *
 * Реализовано через событие main:OnAdminListDisplay. Действуем ТОЛЬКО на списках,
 * где присутствует наша колонка `PROPERTY_<id>` — другие списки не трогаем. Любая
 * ошибка глушится (никогда не ломаем админ-список).
 */
final class AdminColumn
{
    private static $propId = null;
    private static $iblockId = 0;

    public static function onAdminListDisplay(&$list): void
    {
        try {
            if (!is_object($list) || empty($list->aRows) || !is_array($list->aRows)) {
                return;
            }
            $iblockId = Settings::catalogIblockId();
            if ($iblockId <= 0) {
                return;
            }
            $pid = self::publicIdPropId($iblockId);
            if ($pid <= 0) {
                return;
            }
            $col = 'PROPERTY_' . $pid;
            $base = trim((string) Settings::get('OPEN_BASE', ''));
            Loc::loadMessages(__FILE__);
            $copy = Loc::getMessage('ONECATALOG_COL_COPY') ?: 'Copy';
            $open = Loc::getMessage('ONECATALOG_COL_OPEN') ?: 'Open';

            foreach ($list->aRows as $row) {
                if (!is_object($row) || !isset($row->arRes[$col])) {
                    continue;
                }
                $raw = $row->arRes[$col];
                $val = trim((string) (is_array($raw) ? reset($raw) : $raw));
                if ($val === '') {
                    continue; // пусто — оставляем нативное редактирование свойства
                }
                $esc = htmlspecialcharsbx($val);
                $html = '<span style="cursor:pointer;border-bottom:1px dashed #aaa" title="' . htmlspecialcharsbx($copy) . '"'
                    . ' onclick="var v=this.textContent;if(navigator.clipboard){navigator.clipboard.writeText(v);}'
                    . 'var o=this;o.style.opacity=.5;setTimeout(function(){o.style.opacity=1;},300);">' . $esc . '</span>';
                if ($base !== '') {
                    $html .= ' <a href="' . htmlspecialcharsbx($base . rawurlencode($val)) . '" target="_blank" rel="noopener">' . htmlspecialcharsbx($open) . '</a>';
                }
                $row->AddViewField($col, $html);
            }
        } catch (\Throwable $e) {
            // намеренно тихо — обработчик не должен ронять админ-список
        }
    }

    private static function publicIdPropId(int $iblockId): int
    {
        if (self::$propId !== null && self::$iblockId === $iblockId) {
            return (int) self::$propId;
        }
        self::$iblockId = $iblockId;
        self::$propId = 0;
        if (Loader::includeModule('iblock')) {
            $rs = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => 'OC_PUBLIC_ID']);
            if ($r = $rs->Fetch()) {
                self::$propId = (int) $r['ID'];
            }
        }
        return (int) self::$propId;
    }
}
