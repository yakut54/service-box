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

        foreach ($schemas as $schema) {
            $s = $schema->schema_name;
            DB::statement("ALTER TABLE \"{$s}\".masters ADD COLUMN IF NOT EXISTS user_id UUID NULL");
        }
    }

    public function down(): void
    {
        $schemas = DB::select("
            SELECT schema_name FROM information_schema.schemata
            WHERE schema_name LIKE 'shop_%'
        ");

        foreach ($schemas as $schema) {
            DB::statement("ALTER TABLE \"{$schema->schema_name}\".masters DROP COLUMN IF EXISTS user_id");
        }
    }
};
