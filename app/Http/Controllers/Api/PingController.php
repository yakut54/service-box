<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PingController extends Controller
{
    public function ping(Request $request): JsonResponse
    {
        $shop = $request->get('_shop');

        return response()->json([
            'ok'      => true,
            'shop'    => $shop->name,
            'plan'    => $shop->subscription_plan,
            'version' => '1',
        ]);
    }
}
