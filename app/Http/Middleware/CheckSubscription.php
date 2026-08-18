<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $shop = $request->attributes->get('shop');

        if (!$shop) {
            return response()->json([
                'error' => 'Магазин не найден',
            ], 404);
        }

        if (!$shop->hasActiveSubscription()) {
            return $this->handleExpiredSubscription($request, $shop);
        }

        if ($shop->isSubscriptionExpiringSoon()) {
            $response = $next($request);
            $response->headers->set('X-Subscription-Expires-In', $shop->getDaysUntilExpiration() . ' days');
            return $response;
        }

        return $next($request);
    }

    private function handleExpiredSubscription(Request $request, $shop): Response
    {
        $readOnlyEndpoints = [
            'GET:api/admin/shop',
            'GET:api/admin/orders',
            'GET:api/admin/orders/*',
            'GET:api/admin/products',
            'GET:api/admin/products/*',
            'GET:api/admin/bookings',
            'GET:api/admin/bookings/*',
            'GET:api/admin/customers/*/orders',
        ];

        $method = $request->method();
        $path = $request->path();

        foreach ($readOnlyEndpoints as $endpoint) {
            [$allowedMethod, $allowedPath] = explode(':', $endpoint);

            if ($method === $allowedMethod) {
                if ($allowedPath === $path || $this->matchesWildcard($allowedPath, $path)) {
                    // Разрешённый на чтение эндпоинт — пропускаем запрос дальше,
                    // но помечаем ответ как read-only, чтобы фронт мог показать баннер
                    $response = $next($request);
                    $response->headers->set('X-Subscription-Status', 'expired');
                    $response->headers->set('X-Subscription-ReadOnly', '1');
                    return $response;
                }
            }
        }

        return response()->json([
            'error' => 'Требуется подписка',
            'message' => 'Срок действия подписки истёк. Продлите подписку, чтобы пользоваться этой функцией.',
            'subscription_expires_at' => $shop->subscription_expires_at,
            'days_expired' => abs($shop->getDaysUntilExpiration()),
            'plan' => $shop->subscription_plan,
        ], 402);
    }

    private function matchesWildcard(string $pattern, string $path): bool
    {
        $pattern = str_replace('/', '\/', $pattern);
        $pattern = str_replace('*', '.*', $pattern);
        return (bool) preg_match('/^' . $pattern . '$/', $path);
    }
}
