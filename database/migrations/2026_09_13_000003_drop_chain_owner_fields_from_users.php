<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function ($table) {
            if (Schema::hasColumn('users', 'chain_logo_url')) {
                $table->dropColumn('chain_logo_url');
            }
            if (Schema::hasColumn('users', 'chain_name')) {
                $table->dropColumn('chain_name');
            }
            if (Schema::hasColumn('users', 'is_chain_owner')) {
                $table->dropColumn('is_chain_owner');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function ($table) {
            $table->boolean('is_chain_owner')->default(false);
            $table->string('chain_name')->nullable();
            $table->string('chain_logo_url', 1000)->nullable();
        });
    }
};
