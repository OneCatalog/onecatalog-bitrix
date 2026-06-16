<?php

namespace OneCatalog\Import;

use Bitrix\Main\Application;

/**
 * Слой Media (§4, §2.3, §5.3): изображения — выбор размера, скачивание, MIME,
 * трекинг качества и дедупликация по контент-ключу.
 *
 * Контракты §2.3:
 *  - URL вида …/media_files/<base64hash> БЕЗ расширения → скачиваем вручную и
 *    определяем MIME по содержимому;
 *  - три размера min|middle|max; с токеном доступен max, без — только min;
 *  - в base64-префиксе хеша зашит path (стабилен между запросами и сменой токена)
 *    → контент-ключ = sha1(path).
 *
 * Инварианты §5.3:
 *  - трекинг качества: рядом с файлом храним выбранный размер (таблица
 *    onecatalog_media, т.к. у CFile нет места под мету); на повторном импорте, если
 *    доступен размер ЛУЧШЕ — перекачиваем и заменяем;
 *  - дедуп по контент-ключу: один файл скачивается один раз и переиспользуется;
 *  - общие (SHARED) файлы НЕ удаляем при апгрейде качества.
 */
final class Media
{
    private const TABLE = 'onecatalog_media';
    private const SIZES = ['min' => 1, 'middle' => 2, 'max' => 3];

    private string $token;

    public function __construct(private Api $api, ?string $token = null)
    {
        $this->token = $token ?? Settings::apiToken();
    }

    /** Предпочитаемый размер: с токеном — max, без — min (§2.3). */
    public function preferredSize(): string
    {
        return $this->token !== '' ? 'max' : 'min';
    }

    /** Выбор URL нужного размера из {min,middle,max} с фолбэком. */
    public function pickUrl(?array $urls): ?string
    {
        if (!is_array($urls)) {
            return null;
        }
        $order = $this->token !== ''
            ? ['max', 'middle', 'min']
            : ['min', 'middle', 'max'];
        foreach ($order as $size) {
            if (!empty($urls[$size])) {
                return (string) $urls[$size];
            }
        }
        return null;
    }

    /**
     * Скачать (или переиспользовать) изображение, вернуть FILE_ID Битрикса.
     * Дедуп + трекинг качества по контент-ключу (§5.3).
     *
     * @param array|null $naming данные для имени/alt (images_data или files[])
     */
    public function sideload(string $url, ?array $naming = null): ?int
    {
        if ($url === '') {
            return null;
        }
        $size = $this->preferredSize();
        $key = self::contentKey($url);

        if ($key !== null) {
            $row = $this->findByKey($key);
            if ($row !== null) {
                $haveRank = self::SIZES[$row['SIZE']] ?? 0;
                $wantRank = self::SIZES[$size] ?? 0;
                if ($haveRank >= $wantRank) {
                    // Уже есть тот же или лучший размер → переиспользуем, помечаем shared.
                    $this->markShared($key);
                    return (int) $row['FILE_ID'];
                }
                // Доступен лучший размер (например, появился токен) — апгрейд.
                $newId = $this->download($url, $naming);
                if ($newId === null) {
                    return (int) $row['FILE_ID']; // апгрейд не удался — оставляем старое
                }
                if ($row['SHARED'] !== 'Y') {
                    \CFile::Delete((int) $row['FILE_ID']); // общие НЕ удаляем (§5.3)
                }
                $this->updateRecord($key, $size, $newId);
                return $newId;
            }
        }

        $fileId = $this->download($url, $naming);
        if ($fileId === null) {
            return null;
        }
        if ($key !== null) {
            $this->insertRecord($key, $size, $fileId);
        }
        return $fileId;
    }

    /**
     * Контент-ключ из URL media_files: декодируем base64-префикс, извлекаем path
     * (стабилен между запросами/токеном), ключ = sha1(path). Размер хранится
     * отдельной колонкой, поэтому в ключ не входит — это и даёт апгрейд качества.
     */
    public static function contentKey(string $url): ?string
    {
        $path = self::extractPath($url);
        return $path !== null ? sha1($path) : null;
    }

    /** Извлечь стабильный path из base64-префикса media_files URL. */
    public static function extractPath(string $url): ?string
    {
        $segment = basename((string) parse_url($url, PHP_URL_PATH));
        if ($segment === '') {
            return null;
        }
        // Реальный формат: <base64hash>.<подпись/токен>. Подпись после первой точки
        // ВОЛАТИЛЬНА (меняется между запросами/токеном) → отбрасываем её, берём base64.
        $dot = strpos($segment, '.');
        $b64 = $dot !== false ? substr($segment, 0, $dot) : $segment;

        $decoded = base64_decode(strtr($b64, '-_', '+/'), true);
        if ($decoded === false || $decoded === '') {
            return $b64; // не декодировалось — стабильная base64-часть (без токена)
        }
        if (!str_contains($decoded, '|')) {
            return $decoded;
        }
        $parts = explode('|', $decoded);
        // убрать ведущий размер (min|middle|max)
        if (isset($parts[0]) && isset(self::SIZES[strtolower($parts[0])])) {
            array_shift($parts);
        }
        // убрать хвостовой токен-подобный сегмент (длинный, без слеша)
        if (count($parts) > 1) {
            $last = end($parts);
            if (!str_contains($last, '/') && strlen($last) >= 20) {
                array_pop($parts);
            }
        }
        return implode('|', $parts);
    }

    // --- скачивание и сохранение ---

    private function download(string $url, ?array $naming): ?int
    {
        $body = $this->api->fetchBinary($url);
        if ($body === null || $body === '') {
            return null;
        }

        $mime = $this->detectMime($body);
        $ext = $this->extForMime($mime);
        $name = $this->makeName($naming, $ext);

        $tmp = tempnam(sys_get_temp_dir(), 'oc_img_');
        if ($tmp === false) {
            return null;
        }
        file_put_contents($tmp, $body);

        $fileArray = \CFile::MakeFileArray($tmp, $mime);
        if (!is_array($fileArray)) {
            @unlink($tmp);
            return null;
        }
        $fileArray['name'] = $name;

        $fileId = \CFile::SaveFile($fileArray, 'onecatalog');
        @unlink($tmp);

        return $fileId ? (int) $fileId : null;
    }

    private function detectMime(string $body): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_buffer($finfo, $body);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }
        return 'image/jpeg';
    }

    private function extForMime(string $mime): string
    {
        return [
            'image/jpeg' => 'jpg',
            'image/pjpeg'=> 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            'image/bmp'  => 'bmp',
            'image/svg+xml' => 'svg',
        ][$mime] ?? 'jpg';
    }

    private function makeName(?array $naming, string $ext): string
    {
        $raw = '';
        if (is_array($naming)) {
            $raw = (string) ($naming['name'] ?? $naming['alt'] ?? '');
        }
        $raw = trim($raw);
        if ($raw === '') {
            $raw = 'image';
        }
        // отрезаем возможное расширение и чистим имя
        $raw = preg_replace('/\.(jpe?g|png|webp|gif|bmp|svg)$/i', '', $raw) ?? $raw;
        $raw = preg_replace('/[^\w.\-]+/u', '-', $raw) ?? $raw;
        $raw = trim($raw, '-') ?: 'image';
        return mb_substr($raw, 0, 120) . '.' . $ext;
    }

    // --- таблица onecatalog_media (трекинг/дедуп) ---

    private function findByKey(string $key): ?array
    {
        $conn = Application::getConnection();
        $k = $conn->getSqlHelper()->forSql($key);
        $row = $conn->query("SELECT * FROM " . self::TABLE . " WHERE CONTENT_KEY='{$k}'")->fetch();
        return $row ?: null;
    }

    private function insertRecord(string $key, string $size, int $fileId): void
    {
        $conn = Application::getConnection();
        $h = $conn->getSqlHelper();
        $conn->queryExecute(
            "INSERT INTO " . self::TABLE . " (CONTENT_KEY, SIZE, FILE_ID, SHARED, CREATED_AT) VALUES ("
            . "'" . $h->forSql($key) . "', '" . $h->forSql($size) . "', " . $fileId . ", 'N', " . $h->getCurrentDateTimeFunction() . ")"
        );
    }

    private function updateRecord(string $key, string $size, int $fileId): void
    {
        $conn = Application::getConnection();
        $h = $conn->getSqlHelper();
        $conn->queryExecute(
            "UPDATE " . self::TABLE . " SET SIZE='" . $h->forSql($size) . "', FILE_ID=" . $fileId
            . " WHERE CONTENT_KEY='" . $h->forSql($key) . "'"
        );
    }

    private function markShared(string $key): void
    {
        $conn = Application::getConnection();
        $h = $conn->getSqlHelper();
        $conn->queryExecute(
            "UPDATE " . self::TABLE . " SET SHARED='Y' WHERE CONTENT_KEY='" . $h->forSql($key) . "'"
        );
    }
}
