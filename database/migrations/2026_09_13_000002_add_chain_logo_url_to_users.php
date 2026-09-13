<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function ($table) {
            if (!Schema::hasColumn('users', 'chain_logo_url')) {
                $table->string('chain_logo_url', 1000)->nullable()->after('chain_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function ($table) {
            $table->dropColumn('chain_logo_url');
        });
    }
};
