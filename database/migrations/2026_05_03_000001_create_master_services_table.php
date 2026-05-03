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

            DB::statement("
                CREATE TABLE IF NOT EXISTS \"{$s}\".master_services (
                    master_id  UUID NOT NULL REFERENCES \"{$s}\".masters(id)  ON DELETE CASCADE,
                    product_id UUID NOT NULL REFERENCES \"{$s}\".products(id) ON DELETE CASCADE,
                    PRIMARY KEY (master_id, product_id)
                )
            ");

            DB::statement("
                CREATE INDEX IF NOT EXISTS master_services_product_id_idx
                ON \"{$s}\".master_services(product_id)
            ");
        }
    }

    public function down(): void
    {
        $schemas = DB::select("
            SELECT schema_name FROM information_schema.schemata
            WHERE schema_name LIKE 'shop_%'
        ");

        foreach ($schemas as $schema) {
            DB::statement("DROP TABLE IF EXISTS \"{$schema->schema_name}\".master_services");
        }
    }
};
