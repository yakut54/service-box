<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_pricing', function (Blueprint $table) {
            $table->string('plan')->primary(); // micro|start|business|pro
            $table->integer('price_kopecks');  // цена за 1 месяц в копейках
            $table->timestamps();
        });

        // Начальные цены (текущие актуальные)
        DB::table('plan_pricing')->insert([
            ['plan' => 'micro',    'price_kopecks' => 150000, 'created_at' => now(), 'updated_at' => now()],
            ['plan' => 'start',    'price_kopecks' => 300000, 'created_at' => now(), 'updated_at' => now()],
            ['plan' => 'business', 'price_kopecks' => 450000, 'created_at' => now(), 'updated_at' => now()],
            ['plan' => 'pro',      'price_kopecks' => 900000, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_pricing');
    }
};
