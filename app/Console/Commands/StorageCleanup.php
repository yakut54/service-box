<?php

namespace App\Console\Commands;

use App\Services\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StorageCleanup extends Command
{
    protected $signature   = 'storage:cleanup {--dry-run : List orphaned files without deleting them}';
    protected $description = 'Delete uploaded images not referenced anywhere in the database';

    public function handle(): int
    {
        $disk  = Storage::disk('public');
        $files = $disk->files('uploads');

        if (empty($files)) {
            $this->info('uploads/ is empty — nothing to clean.');
            return self::SUCCESS;
        }

        $usedPaths = $this->collectUsedPaths();
        $dry       = $this->option('dry-run');
        $deleted   = 0;
        $skipped   = 0;

        foreach ($files as $file) {
            // Skip files younger than 1 hour — might be a mid-session upload
            if (time() - $disk->lastModified($file) < 3600) {
                $skipped++;
                continue;
            }

            if (!isset($usedPaths[$file])) {
                if ($dry) {
                    $this->line("[dry-run] {$file}");
                } else {
                    $disk->delete($file);
                    Log::info('[StorageCleanup] deleted orphaned file', ['path' => $file]);
                    $this->line("Deleted: {$file}");
                }
                $deleted++;
            }
        }

        $label = $dry ? 'Would delete' : 'Deleted';
        $this->info("{$label}: {$deleted} file(s). Skipped (< 1h old): {$skipped}.");

        return self::SUCCESS;
    }

    /** @return array<string, true> hash-set of relative storage paths referenced in DB */
    private function collectUsedPaths(): array
    {
        $urls = [];

        // Shop widget logos (stored as JSON)
        DB::table('shops')
            ->whereNotNull('widget_config')
            ->whereRaw("widget_config->>'logo_url' IS NOT NULL")
            ->selectRaw("widget_config->>'logo_url' as logo_url")
            ->pluck('logo_url')
            ->each(function (string $url) use (&$urls) { $urls[] = $url; });

        // shop_staff живёт в public-схеме, не в тенантной — отдельный запрос
        DB::table('shop_staff')
            ->whereNotNull('avatar_url')
            ->pluck('avatar_url')
            ->each(function (string $url) use (&$urls) { $urls[] = $url; });

        // Per-shop tenant schemas
        $schemas = DB::table('shops')->pluck('schema_name');

        // ВНИМАНИЕ: любая колонка с URL картинки на диске public, которой нет
        // в этом списке, будет считаться «осиротевшей» и удалена через час —
        // отсюда пропали доп. фото товаров (product_images.url) и аватары
        // покупателей (customers.avatar_url), их тут не было (баг найден
        // 2026-08-22, удалялись каждую ночь по расписанию 03:00).
        $columns = [
            'products'       => 'image_url',
            'product_images' => 'url',
            'masters'        => 'avatar_url',
            'categories'     => 'image_url',
            'customers'      => 'avatar_url',
            'chat_messages'  => 'image_url',
        ];

        foreach ($schemas as $schema) {
            foreach ($columns as $table => $col) {
                try {
                    $rows = DB::select(
                        "SELECT {$col} FROM \"{$schema}\".{$table} WHERE {$col} IS NOT NULL"
                    );
                    foreach ($rows as $row) {
                        $urls[] = $row->$col;
                    }
                } catch (\Throwable) {
                    // Schema or table may not exist yet — skip
                }
            }
        }

        // Build a hash-set keyed by relative storage path for O(1) lookup.
        // Сопоставление по пути (StorageService::relativeStoragePath), не по
        // полному URL с доменом — иначе смена APP_URL = все картинки «осиротели».
        $paths = [];
        foreach ($urls as $url) {
            $path = StorageService::relativeStoragePath((string) $url);
            if ($path !== null) {
                $paths[$path] = true;
            }
        }

        return $paths;
    }
}
