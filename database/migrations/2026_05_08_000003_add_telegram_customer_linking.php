<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $schemas = DB::select("
            SELECT schema_name
            FROM information_schema.schemata
            WHERE schema_name LIKE 'shop_%'
        ");

        foreach ($schemas as $row) {
            $s = $row->schema_name;

            // Add telegram_chat_id to customers
            DB::statement("
                ALTER TABLE \"{$s}\".customers
                ADD COLUMN IF NOT EXISTS telegram_chat_id BIGINT DEFAULT NULL
            ");

            // Create telegram_link_tokens table
            DB::statement("
                CREATE TABLE IF NOT EXISTS \"{$s}\".telegram_link_tokens (
                    token TEXT PRIMARY KEY,
                    customer_phone TEXT NOT NULL,
                    expires_at TIMESTAMPTZ NOT NULL,
                    used BOOLEAN NOT NULL DEFAULT FALSE,
                    created_at TIMESTAMPTZ DEFAULT NOW()
                )
            ");

            DB::statement("
                CREATE INDEX IF NOT EXISTS tlt_phone_idx ON \"{$s}\".telegram_link_tokens(customer_phone)
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
            DB::statement("DROP TABLE IF EXISTS \"{$s}\".telegram_link_tokens");
            DB::statement("ALTER TABLE \"{$s}\".customers DROP COLUMN IF EXISTS telegram_chat_id");
        }
    }
};
