<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyYooKassaWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->isFromYooKassa($request)) {
            Log::warning('YooKassa webhook blocked: invalid IP', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            return response()->json(['error' => 'Forbidden'], 403);
        }

        Log::info('YooKassa webhook verified', [
            'ip' => $request->ip(),
            'event' => $request->input('event'),
        ]);

        return $next($request);
    }

    protected function isFromYooKassa(Request $request): bool
    {
        $ip = $request->ip();

        $allowedRanges = [
            '185.71.76.0/27',
            '185.71.77.0/27',
            '77.75.153.0/25',
            '77.75.156.11/32',
            '77.75.156.35/32',
            '77.75.154.128/25',
            '2a02:5180::/32',
        ];

        if (app()->environment('local')) {
            $allowedRanges[] = '127.0.0.1/32';
            $allowedRanges[] = '::1/128';
        }

        foreach ($allowedRanges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    protected function ipInRange(string $ip, string $range): bool
    {
        [$subnet, $mask] = array_pad(explode('/', $range), 2, 32);

        if (str_contains($ip, ':')) {
            return $this->ipv6InRange($ip, $subnet, (int) $mask);
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $maskLong = -1 << (32 - (int) $mask);
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }

    protected function ipv6InRange(string $ip, string $subnet, int $mask): bool
    {
        $ipBinary = inet_pton($ip);
        $subnetBinary = inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false) {
            return false;
        }

        $ipBits = unpack('N*', $ipBinary);
        $subnetBits = unpack('N*', $subnetBinary);

        $fullBytes = (int) floor($mask / 32);
        $remainingBits = $mask % 32;

        for ($i = 1; $i <= $fullBytes; $i++) {
            if (!isset($ipBits[$i]) || !isset($subnetBits[$i])) {
                return false;
            }
            if ($ipBits[$i] !== $subnetBits[$i]) {
                return false;
            }
        }

        if ($remainingBits > 0) {
            $maskValue = -1 << (32 - $remainingBits);
            $nextBlock = $fullBytes + 1;
            if (isset($ipBits[$nextBlock]) && isset($subnetBits[$nextBlock])) {
                if (($ipBits[$nextBlock] & $maskValue) !== ($subnetBits[$nextBlock] & $maskValue)) {
                    return false;
                }
            }
        }

        return true;
    }
}
