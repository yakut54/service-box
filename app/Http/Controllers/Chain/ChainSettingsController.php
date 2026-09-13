<?php

namespace App\Http\Controllers\Chain;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateChainSettingsRequest;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;

class ChainSettingsController extends Controller
{
    /**
     * PUT /api/chain/settings
     *
     * Название сети и ЕДИНЫЙ логотип сети — единственное место, где владелец
     * сети их настраивает. Точки (shops) своего логотипа не имеют — см.
     * ShopController::getPublicInfo, где widget_config.logo_url точки
     * принудительно подменяется на этот логотип для всех точек владельца сети.
     */
    public function update(UpdateChainSettingsRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->chain_name = $request->validated('name');

        if ($request->has('logo_url')) {
            $newLogoUrl = $request->validated('logo_url');
            if ($newLogoUrl !== $user->chain_logo_url) {
                StorageService::deleteByUrl($user->chain_logo_url);
                $user->chain_logo_url = $newLogoUrl;
            }
        }

        $user->save();

        return response()->json([
            'data' => [
                'shops_count' => $user->shops()->count(),
                'name'        => $user->chain_name,
                'logo_url'    => $user->chain_logo_url,
            ],
        ]);
    }
}
