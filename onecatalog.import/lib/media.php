<?php

namespace OneCatalog\Import;

/**
 * Слой Media (§4, §2.3, §5.3): изображения.
 *
 * TODO (следующий инкремент):
 *  - выбор размера (min|middle|max; с токеном → max, без → min);
 *  - ручное скачивание (Api::fetchBinary) + MIME по содержимому (URL без расширения);
 *  - CFile::MakeFileArray с корректным расширением в имени;
 *  - трекинг качества + авто-апгрейд (таблица onecatalog_media);
 *  - дедуп по контент-ключу sha1(path#size) из base64-префикса URL;
 *    общие (shared) файлы НЕ удалять при апгрейде качества;
 *  - галерея идемпотентна по имени файла, не по URL.
 */
final class Media
{
    public function __construct(private Api $api)
    {
    }

    // public function sideload(string $url, string $size, bool $shared = false): ?int {}
    // public static function contentKey(string $url, string $size): string {}
}
