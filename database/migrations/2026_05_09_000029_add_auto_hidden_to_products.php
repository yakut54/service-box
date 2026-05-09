<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $schemas = DB::select("
            SELECT schema_name FROM information_schema.schemata
            WHERE schema_name LIKE 'shop_%'
        ");

        foreach ($schemas as $row) {
            DB::statement("
                ALTER TABLE \"{$row->schema_name}\".products
                ADD COLUMN IF NOT EXISTS auto_hidden BOOLEAN NOT NULL DEFAULT FALSE
            ");
        }
    }

    public function down(): void
    {
        $schemas = DB::select("
            SELECT schema_name FROM information_schema.schemata
            WHERE schema_name LIKE 'shop_%'
        ");

        foreach ($schemas as $row) {
            DB::statement("
                ALTER TABLE \"{$row->schema_name}\".products
                DROP COLUMN IF EXISTS auto_hidden
            ");
        }
    }
};
