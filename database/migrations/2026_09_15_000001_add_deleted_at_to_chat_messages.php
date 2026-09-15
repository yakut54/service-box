<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $schemas = DB::table('shops')->pluck('schema_name');
        foreach ($schemas as $schema) {
            DB::statement("ALTER TABLE \"{$schema}\".chat_messages ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMPTZ");
            DB::statement("ALTER TABLE \"{$schema}\".chat_messages DROP CONSTRAINT IF EXISTS chat_messages_not_empty");
            DB::statement("ALTER TABLE \"{$schema}\".chat_messages ADD CONSTRAINT chat_messages_not_empty CHECK (body IS NOT NULL OR image_url IS NOT NULL OR deleted_at IS NOT NULL)");
        }
    }

    public function down(): void
    {
        $schemas = DB::table('shops')->pluck('schema_name');
        foreach ($schemas as $schema) {
            DB::statement("ALTER TABLE \"{$schema}\".chat_messages DROP CONSTRAINT IF EXISTS chat_messages_not_empty");
            DB::statement("ALTER TABLE \"{$schema}\".chat_messages ADD CONSTRAINT chat_messages_not_empty CHECK (body IS NOT NULL OR image_url IS NOT NULL)");
            DB::statement("ALTER TABLE \"{$schema}\".chat_messages DROP COLUMN IF EXISTS deleted_at");
        }
    }
};
