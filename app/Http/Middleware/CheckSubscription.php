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
                'error' => 'Shop not found',
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
                    $response = response()->json([
                        'error' => 'Subscription expired',
                        'message' => 'Your subscription has expired. You have read-only access. Please renew to continue using full features.',
                        'days_expired' => abs($shop->getDaysUntilExpiration()),
                        'subscription_expires_at' => $shop->subscription_expires_at,
                    ], 402);

                    $response->headers->set('X-Subscription-Status', 'expired');
                    return $response;
                }
            }
        }

        return response()->json([
            'error' => 'Subscription required',
            'message' => 'Your subscription has expired. Please renew your subscription to access this feature.',
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
