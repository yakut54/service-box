<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function ($table) {
            if (!Schema::hasColumn('users', 'is_chain_owner')) {
                $table->boolean('is_chain_owner')->default(false)->after('is_superadmin');
            }
        });

        if (!Schema::hasTable('user_flag_audit')) {
            Schema::create('user_flag_audit', function ($table) {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
                $table->uuid('user_id');
                $table->uuid('actor_user_id')->nullable();
                $table->string('flag_key');
                $table->boolean('enabled');
                $table->timestamp('created_at', 0)->default(DB::raw('CURRENT_TIMESTAMP'));

                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->index(['user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_flag_audit');

        Schema::table('users', function ($table) {
            $table->dropColumn('is_chain_owner');
        });
    }
};
