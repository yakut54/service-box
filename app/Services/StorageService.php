<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class StorageService
{
    /**
     * URL картинки на диске public → относительный путь ('uploads/xxx.webp').
     * Сравнение по ПУТИ, а не по полному URL с доменом: иначе при смене APP_URL
     * все картинки со старым доменом в БД «пропадают» — storage:cleanup сочтёт
     * их осиротевшими и удалит в 03:00, а deleteByUrl молча не удалит нужное.
     *
     * Принимает абсолютный ('https://<домен>/storage/uploads/x'), относительный
     * ('/storage/uploads/x') и уже голый ('uploads/x') вид. Внешние URL и null —
     * возвращает null.
     */
    public static function relativeStoragePath(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $marker = strpos($path, '/storage/');
        if ($marker !== false) {
            return ltrim(substr($path, $marker + strlen('/storage/')), '/');
        }

        $path = ltrim($path, '/');

        return str_starts_with($path, 'uploads/') ? $path : null;
    }

    /**
     * Delete a file from public storage by its URL.
     * Safe to call with null or external URLs — those are silently ignored.
     */
    public static function deleteByUrl(?string $url): void
    {
        $path = self::relativeStoragePath($url);

        if ($path !== null) {
            Storage::disk('public')->delete($path);
        }
    }
}
