<?php

namespace App\Http\Controllers;

use App\Models\Master;
use App\Services\MasterCascadeService;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MasterController extends Controller
{
    /**
     * GET /api/admin/masters
     */
    public function index(Request $request): JsonResponse
    {
        $query = Master::query();

        if ($request->has('active')) {
            $query->where('is_active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN));
        }

        $masters = $query->with('services:id,name')->orderBy('sort_order')->orderBy('name')->get();

        return response()->json([
            'data' => $masters,
            'count' => $masters->count(),
        ]);
    }

    /**
     * POST /api/admin/masters
     */
    public function store(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        if ($shop) {
            $limits = $shop->getPlanLimits();
            if ($limits['max_masters'] !== null) {
                $current = Master::count();
                if ($current >= $limits['max_masters']) {
                    $word = $limits['max_masters'] === 1 ? 'мастера' : 'мастеров';
                    return response()->json([
                        'message' => "Ваш тариф позволяет не более {$limits['max_masters']} {$word}. Обновите план для снятия ограничения.",
                    ], 403);
                }
            }
        }

        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'phone'          => 'nullable|string|max:20',
            'email'          => 'nullable|email|max:255',
            'specialization' => 'nullable|string|max:255',
            'avatar_url'     => 'nullable|url|max:1000',
            'is_active'      => 'boolean',
            'sort_order'     => 'integer|min:0',
        ]);

        $master = Master::create($data);

        return response()->json(['data' => $master], 201);
    }

    /**
     * GET /api/admin/masters/{master}
     */
    public function show(string $master): JsonResponse
    {
        $master = Master::with('services:id,name,category_id')->findOrFail($master);

        return response()->json(['data' => $master]);
    }

    /**
     * GET /api/admin/masters/{master}/services
     */
    public function getServices(string $master): JsonResponse
    {
        $master = Master::with('services:id,name,category_id')->findOrFail($master);

        return response()->json([
            'data' => $master->services->pluck('id'),
        ]);
    }

    /**
     * PUT /api/admin/masters/{master}/services
     */
    public function syncServices(Request $request, string $master): JsonResponse
    {
        $master = Master::findOrFail($master);

        $data = $request->validate([
            'service_ids'   => 'present|array',
            'service_ids.*' => 'uuid',
        ]);

        $master->services()->sync($data['service_ids']);

        return response()->json([
            'data' => $master->services()->pluck('id'),
        ]);
    }

    /**
     * PUT /api/admin/masters/{master}
     */
    public function update(Request $request, string $master): JsonResponse
    {
        $master = Master::findOrFail($master);

        $data = $request->validate([
            'name'           => 'sometimes|required|string|max:255',
            'phone'          => 'nullable|string|max:20',
            'email'          => 'nullable|email|max:255',
            'specialization' => 'nullable|string|max:255',
            'avatar_url'     => 'nullable|url|max:1000',
            'is_active'      => 'boolean',
            'sort_order'     => 'integer|min:0',
            'salary_type'    => 'sometimes|in:fixed,percent',
            'salary_rate'    => 'sometimes|numeric|min:0|max:99999',
        ]);

        if (isset($data['is_active']) && $data['is_active'] && !$master->is_active) {
            $shop = $request->attributes->get('shop');
            if ($shop) {
                $limits = $shop->getPlanLimits();
                $max    = $limits['max_masters'];
                if ($max !== null && Master::where('is_active', true)->count() >= $max) {
                    $word = $max === 1 ? 'активного мастера' : 'активных мастеров';
                    return response()->json([
                        'message' => "Ваш тариф позволяет не более {$max} {$word}.",
                    ], 403);
                }
            }
        }

        $oldAvatarUrl = $master->avatar_url;
        $wasActive    = $master->is_active;
        $master->update($data);

        if (isset($data['avatar_url']) && $data['avatar_url'] !== $oldAvatarUrl) {
            StorageService::deleteByUrl($oldAvatarUrl);
        }

        $shop = $request->attributes->get('shop');
        if ($shop && isset($data['is_active'])) {
            $schema = $shop->schema_name;

            if ($wasActive && !$master->is_active) {
                $hidden = MasterCascadeService::handleDeactivation($master->id, $schema, $shop);
                MasterCascadeService::notifyDeactivation($shop, $hidden);
            } elseif (!$wasActive && $master->is_active) {
                $restored = MasterCascadeService::handleReactivation($master->id, $schema, $shop);
                MasterCascadeService::notifyReactivation($shop, $restored);
            }
        }

        return response()->json(['data' => $master]);
    }

    /**
     * DELETE /api/admin/masters/{master}
     */
    public function destroy(string $master): JsonResponse
    {
        $master = Master::findOrFail($master);
        $avatarUrl = $master->avatar_url;
        $master->delete();
        StorageService::deleteByUrl($avatarUrl);

        return response()->json(['message' => 'Мастер удалён'], 200);
    }
}
