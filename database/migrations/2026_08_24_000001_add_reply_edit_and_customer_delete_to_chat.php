<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function ($table) {
            if (!Schema::hasColumn('shops', 'chat_customer_delete_enabled')) {
                $table->boolean('chat_customer_delete_enabled')->default(false);
            }
        });

        $schemas = DB::table('shops')->pluck('schema_name');
        foreach ($schemas as $schema) {
            DB::statement("ALTER TABLE \"{$schema}\".chat_messages ADD COLUMN IF NOT EXISTS reply_to_message_id UUID REFERENCES \"{$schema}\".chat_messages(id) ON DELETE SET NULL");
            DB::statement("ALTER TABLE \"{$schema}\".chat_messages ADD COLUMN IF NOT EXISTS edited_at TIMESTAMPTZ");
        }
    }

    public function down(): void
    {
        Schema::table('shops', function ($table) {
            $table->dropColumn('chat_customer_delete_enabled');
        });

        $schemas = DB::table('shops')->pluck('schema_name');
        foreach ($schemas as $schema) {
            DB::statement("ALTER TABLE \"{$schema}\".chat_messages DROP COLUMN IF EXISTS reply_to_message_id");
            DB::statement("ALTER TABLE \"{$schema}\".chat_messages DROP COLUMN IF EXISTS edited_at");
        }
    }
};
