<?php

namespace App\Http\Controllers;

use App\Models\Master;
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

        $masters = $query->orderBy('sort_order')->orderBy('name')->get();

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
        ]);

        $master->update($data);

        return response()->json(['data' => $master]);
    }

    /**
     * DELETE /api/admin/masters/{master}
     */
    public function destroy(string $master): JsonResponse
    {
        $master = Master::findOrFail($master);
        $master->delete();

        return response()->json(['message' => 'Мастер удалён'], 200);
    }
}
