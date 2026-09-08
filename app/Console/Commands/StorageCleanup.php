<?php

namespace App\Console\Commands;

use App\Services\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Удаляет файлы из storage/app/public/uploads, на которые нигде в БД нет
 * ссылки. Раньше список «где искать ссылки» был захардкожен по таблицам —
 * и каждый раз, когда добавляли новую колонку с картинкой, забывали её сюда
 * дописать, а команда по ночам удаляла живые файлы:
 *   - 2026-08-22: product_images.url, customers.avatar_url
 *   - 2026-09-08: users.avatar_url (аватар в админке), product_variants.image_url
 *
 * Теперь колонки НЕ хардкодятся: пробегаем по всем текстовым и JSON-колонкам
 * во всех схемах (public + shop_*), достаём из значений любые пути вида
 * `uploads/<...>` регуляркой (работает и для JSON, и для составных строк).
 * Плюс защита: 7 дней «карантина» для свежих файлов, стоп-кран на массовое
 * удаление и отказ работать, если скан ссылок прошёл не полностью.
 */
class StorageCleanup extends Command
{
    protected $signature   = 'storage:cleanup {--dry-run : List orphaned files without deleting them}';
    protected $description = 'Delete uploaded images not referenced anywhere in the database';

    /** Свежие файлы не трогаем неделю — запас на рассинхрон БД/диска. */
    private const GRACE_SECONDS = 7 * 24 * 3600;

    /** Стоп-кран: не удаляем разом, если это похоже на сломанный скан. */
    private const MAX_DELETE_ABS = 40;
    private const MAX_DELETE_RATIO = 0.25;

    public function handle(): int
    {
        $disk  = Storage::disk('public');
        $files = $disk->files('uploads');

        if (empty($files)) {
            $this->info('uploads/ is empty — nothing to clean.');
            return self::SUCCESS;
        }

        [$usedPaths, $scanComplete] = $this->collectUsedPaths();

        if (!$scanComplete) {
            Log::critical('[StorageCleanup] reference scan incomplete — aborting, nothing deleted');
            $this->error('Reference scan did not cover all schemas — nothing deleted.');
            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $now = time();

        $orphans = [];
        $skipped = 0;
        foreach ($files as $file) {
            if ($now - $disk->lastModified($file) < self::GRACE_SECONDS) {
                $skipped++;
                continue;
            }
            if (!isset($usedPaths[$file])) {
                $orphans[] = $file;
            }
        }

        $total = count($files);
        if (!$dry
            && count($orphans) > self::MAX_DELETE_ABS
            && count($orphans) > $total * self::MAX_DELETE_RATIO
        ) {
            Log::critical('[StorageCleanup] refusing to delete — looks like a broken scan', [
                'orphans' => count($orphans),
                'total'   => $total,
            ]);
            $this->error(sprintf(
                'Would delete %d of %d files — too many, aborting. Run with --dry-run to inspect.',
                count($orphans),
                $total,
            ));
            return self::FAILURE;
        }

        $deleted = 0;
        foreach ($orphans as $file) {
            if ($dry) {
                $this->line("[dry-run] {$file}");
            } else {
                $disk->delete($file);
                Log::info('[StorageCleanup] deleted orphaned file', ['path' => $file]);
                $this->line("Deleted: {$file}");
            }
            $deleted++;
        }

        $label = $dry ? 'Would delete' : 'Deleted';
        $this->info("{$label}: {$deleted} file(s). Kept (referenced): "
            . ($total - $skipped - $deleted) . ". Skipped (< 7d old): {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * Собирает множество относительных путей (`uploads/xxx.webp`), на которые
     * есть хоть одна ссылка в БД — из ЛЮБОЙ текстовой/JSON-колонки любой схемы.
     *
     * @return array{0: array<string, true>, 1: bool} [пути, скан прошёл полностью]
     */
    private function collectUsedPaths(): array
    {
        $shopSchemas = DB::table('shops')->pluck('schema_name')
            ->filter()
            ->values()
            ->all();

        $schemas = array_values(array_unique(array_merge(['public'], $shopSchemas)));

        $paths = [];
        $schemasScanned = 0;

        foreach ($schemas as $schema) {
            try {
                $columns = DB::select(
                    "SELECT table_name, column_name
                       FROM information_schema.columns
                      WHERE table_schema = ?
                        AND data_type IN ('character varying', 'text', 'character', 'json', 'jsonb')",
                    [$schema]
                );
            } catch (\Throwable $e) {
                Log::warning('[StorageCleanup] could not list columns', [
                    'schema' => $schema, 'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (empty($columns)) {
                continue;
            }

            $schemasScanned++;

            foreach ($columns as $c) {
                $col = $this->quoteIdent($c->column_name);
                $tbl = $this->quoteIdent($schema) . '.' . $this->quoteIdent($c->table_name);

                try {
                    // ::text — чтобы одинаково работать с varchar, json и jsonb.
                    // Фильтр по 'uploads' без слэша: тип `json` (не `jsonb`)
                    // хранит исходный текст с экранированными слэшами (`uploads\/`),
                    // поэтому 'uploads/' их бы не поймал.
                    $rows = DB::select(
                        "SELECT DISTINCT {$col}::text AS v FROM {$tbl} WHERE {$col}::text LIKE '%uploads%'"
                    );
                } catch (\Throwable) {
                    continue; // недоступная таблица/колонка — не роняем весь прогон
                }

                foreach ($rows as $row) {
                    if ($row->v === null) {
                        continue;
                    }
                    // Снимаем JSON-экранирование слэшей: `https:\/\/…\/uploads\/x` → `…/uploads/x`.
                    $value = str_replace('\\/', '/', $row->v);
                    if (preg_match_all('#uploads/[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*#', $value, $m)) {
                        foreach ($m[0] as $raw) {
                            $path = StorageService::relativeStoragePath($raw) ?? ltrim($raw, '/');
                            $paths[$path] = true;
                        }
                    }
                }
            }
        }

        // Скан валиден только если реально прошли public + все схемы магазинов.
        $complete = $schemasScanned >= count($schemas);

        return [$paths, $complete];
    }

    /** Экранирование идентификатора из information_schema для подстановки в SQL. */
    private function quoteIdent(string $ident): string
    {
        return '"' . str_replace('"', '""', $ident) . '"';
    }
}
