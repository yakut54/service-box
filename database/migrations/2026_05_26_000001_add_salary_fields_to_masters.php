<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Получаем все схемы магазинов
        $schemas = DB::select("
            SELECT schema_name
            FROM information_schema.schemata
            WHERE schema_name LIKE 'shop_%'
        ");

        foreach ($schemas as $row) {
            $s = $row->schema_name;
            DB::statement("
                ALTER TABLE \"{$s}\".masters
                ADD COLUMN IF NOT EXISTS salary_type  VARCHAR(10) NOT NULL DEFAULT 'percent',
                ADD COLUMN IF NOT EXISTS salary_rate  NUMERIC(10,2) NOT NULL DEFAULT 0
            ");
        }
    }

    public function down(): void
    {
        $schemas = DB::select("
            SELECT schema_name
            FROM information_schema.schemata
            WHERE schema_name LIKE 'shop_%'
        ");

        foreach ($schemas as $row) {
            $s = $row->schema_name;
            DB::statement("
                ALTER TABLE \"{$s}\".masters
                DROP COLUMN IF EXISTS salary_type,
                DROP COLUMN IF EXISTS salary_rate
            ");
        }
    }
};
