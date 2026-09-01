<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function ($table) {
            if (!Schema::hasColumn('users', 'avatar_url')) {
                $table->string('avatar_url', 1000)->nullable();
            }
            if (!Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 20)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function ($table) {
            $table->dropColumn(['avatar_url', 'phone']);
        });
    }
};
