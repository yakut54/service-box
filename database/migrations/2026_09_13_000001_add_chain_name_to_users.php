<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function ($table) {
            if (!Schema::hasColumn('users', 'chain_name')) {
                $table->string('chain_name')->nullable()->after('is_chain_owner');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function ($table) {
            $table->dropColumn('chain_name');
        });
    }
};
