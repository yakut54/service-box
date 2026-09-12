<?php

namespace App\Http\Controllers\Chain;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChainShopRequest;
use App\Models\Shop;
use App\Models\ShopFeature;
use App\Models\ShopStaff;
use App\Models\User;
use App\Services\ShopRevenueAggregator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ChainShopController extends Controller
{
    // GET /api/chain/shops
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = Cache::remember("chain:shops:{$user->id}", 60, function () use ($user) {
            $shops = $user->shops()->orderBy('created_at')->get();

            $totals = ShopRevenueAggregator::totals($shops, 'total_price', ['cancelled'], 30);

            $staffCounts = ShopStaff::whereIn('shop_id', $shops->pluck('id'))
                ->whereNotNull('accepted_at')
                ->selectRaw('shop_id, count(*) as cnt')
                ->groupBy('shop_id')
                ->pluck('cnt', 'shop_id');

            return $shops->map(function (Shop $shop) use ($totals, $staffCounts) {
                $shopTotals = $totals['per_shop'][$shop->id] ?? null;

                return [
                    'id'                  => $shop->id,
                    'name'                => $shop->name,
                    'domain'              => $shop->domain,
                    'timezone'            => $shop->timezone,
                    'created_at'          => $shop->created_at,
                    'revenue_30d_kopecks' => $shopTotals['period_kopecks'] ?? 0,
                    'orders_30d'          => $shopTotals['period_orders'] ?? 0,
                    'staff_count'         => (int) ($staffCounts[$shop->id] ?? 0),
                ];
            })->values();
        });

        return response()->json(['data' => $data]);
    }

    // POST /api/chain/shops
    public function store(StoreChainShopRequest $request): JsonResponse
    {
        $user = $request->user();

        $maxShops = (int) config('platform.max_shops_per_chain_owner');
        if ($user->shops()->count() >= $maxShops) {
            return response()->json([
                'message' => "Достигнут лимит точек ({$maxShops})",
            ], 422);
        }

        $oldestShop = $user->shops()->orderBy('created_at')->first();

        $shop = DB::transaction(function () use ($request, $user, $oldestShop) {
            // schema_name/api_key/widget_config и создание тенантной схемы —
            // всё в Shop::boot() (см. коммит про двойной createSchema в
            // AuthController::register) — тут просто Shop::create().
            $shop = Shop::create([
                'user_id'  => $user->id,
                'name'     => $request->validated('name'),
                'domain'   => $request->validated('domain'),
                'timezone' => $request->validated('timezone') ?? $oldestShop?->timezone ?? 'Europe/Moscow',
            ]);

            $this->inheritFeatures($oldestShop, $shop, $user);

            return $shop;
        });

        Cache::forget("chain:shops:{$user->id}");

        return response()->json(['data' => [
            'id'         => $shop->id,
            'name'       => $shop->name,
            'domain'     => $shop->domain,
            'timezone'   => $shop->timezone,
            'created_at' => $shop->created_at,
        ]], 201);
    }

    /**
     * Новая точка получает те же оплаченные фичи, что и первая точка этого
     * же владельца — иначе он открывает каждую новую базу «пустой» и звонит
     * нам, чтобы включить то, за что уже заплатил. Копируем только строки
     * shop_features (booking/digital_goods и т.п.) — «флаговые» колонки на
     * самой shops (customer_push_enabled) не входят сюда: у них уже есть
     * свой дефолт в Shop::boot(), который срабатывает для любой новой точки
     * одинаково, копировать нечего.
     */
    private function inheritFeatures(?Shop $sourceShop, Shop $newShop, User $actor): void
    {
        if (!$sourceShop) {
            return;
        }

        $rows = ShopFeature::where('shop_id', $sourceShop->id)->get(['feature_key', 'enabled']);

        foreach ($rows as $row) {
            ShopFeature::create([
                'shop_id'     => $newShop->id,
                'feature_key' => $row->feature_key,
                'enabled'     => $row->enabled,
            ]);

            DB::table('shop_feature_audit')->insert([
                'shop_id'       => $newShop->id,
                'actor_user_id' => $actor->id,
                'feature_key'   => $row->feature_key,
                'enabled'       => $row->enabled,
            ]);
        }
    }
}
