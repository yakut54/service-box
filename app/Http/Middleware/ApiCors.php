<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiCors
{
    public function handle(Request $request, Closure $next): Response
    {
        // OPTIONS preflight — вернуть немедленно, до auth-проверки
        if ($request->isMethod('OPTIONS')) {
            return response('', 200, $this->corsHeaders());
        }

        $response = $next($request);

        foreach ($this->corsHeaders() as $header => $value) {
            $response->headers->set($header, $value);
        }

        return $response;
    }

    private function corsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'X-API-Key, Content-Type, Accept',
            'Access-Control-Max-Age'       => '86400',
        ];
    }
}
