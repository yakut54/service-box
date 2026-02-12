<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\DB;

class TenantService
{
    protected static ?string $currentShopId = null;
    protected static ?string $currentSchema = null;

    public static function setContextByApiKey(string $apiKey): Shop
    {
        $shop = Shop::where('api_key', $apiKey)->firstOrFail();
        self::setContext($shop);
        return $shop;
    }

    public static function setContextById(string $shopId): Shop
    {
        $shop = Shop::findOrFail($shopId);
        self::setContext($shop);
        return $shop;
    }

    public static function setContext(Shop $shop): void
    {
        self::$currentShopId = $shop->id;
        self::$currentSchema = $shop->schema_name;
        DB::statement('SET search_path TO ' . $shop->schema_name . ', public');
    }

    public static function resetContext(): void
    {
        self::$currentShopId = null;
        self::$currentSchema = null;
        DB::statement('SET search_path TO public');
    }

    public static function getCurrentShopId(): ?string
    {
        return self::$currentShopId;
    }

    public static function getCurrentSchema(): ?string
    {
        return self::$currentSchema;
    }

    public static function hasContext(): bool
    {
        return self::$currentShopId !== null;
    }

    public static function inContext(Shop $shop, callable $callback): mixed
    {
        $previousShopId = self::$currentShopId;
        $previousSchema = self::$currentSchema;

        try {
            self::setContext($shop);
            return $callback();
        } finally {
            if ($previousShopId && $previousSchema) {
                self::$currentShopId = $previousShopId;
                self::$currentSchema = $previousSchema;
                DB::statement('SET search_path TO ' . $previousSchema . ', public');
            } else {
                self::resetContext();
            }
        }
    }

    public static function createSchema(string $schemaName): void
    {
        DB::statement('SELECT public.create_shop_schema(?)', [$schemaName]);
    }
}
