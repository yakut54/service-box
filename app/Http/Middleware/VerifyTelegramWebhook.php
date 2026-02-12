<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyTelegramWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->isFromTelegram($request)) {
            Log::warning('Telegram webhook blocked: invalid IP', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if (!$this->verifySecretToken($request)) {
            Log::warning('Telegram webhook blocked: invalid secret token', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        Log::info('Telegram webhook verified', [
            'ip' => $request->ip(),
            'update_id' => $request->input('update_id'),
        ]);

        return $next($request);
    }

    protected function isFromTelegram(Request $request): bool
    {
        $ip = $request->ip();

        $allowedRanges = [
            '149.154.160.0/20',
            '91.108.4.0/22',
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

    protected function verifySecretToken(Request $request): bool
    {
        $secretToken = config('services.telegram.secret_token');

        if (empty($secretToken)) {
            if (app()->environment('production')) {
                Log::critical('Telegram secret token not configured in production!');
                return false;
            }
            return true;
        }

        $requestToken = $request->header('X-Telegram-Bot-Api-Secret-Token');
        return hash_equals($secretToken, $requestToken ?? '');
    }

    protected function ipInRange(string $ip, string $range): bool
    {
        [$subnet, $mask] = array_pad(explode('/', $range), 2, 32);

        if (str_contains($ip, ':')) {
            return $this->ipv6InRange($ip, $subnet, (int) $mask);
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
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
