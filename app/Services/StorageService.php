<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class StorageService
{
    /**
     * Delete a file from public storage by its URL.
     * Safe to call with null or external URLs — those are silently ignored.
     */
    public static function deleteByUrl(?string $url): void
    {
        if (!$url) {
            return;
        }

        $storageBase = Storage::disk('public')->url('');

        // Only delete files hosted on our own storage
        if (!str_starts_with($url, $storageBase)) {
            return;
        }

        $path = ltrim(str_replace($storageBase, '', $url), '/');
        Storage::disk('public')->delete($path);
    }
}
