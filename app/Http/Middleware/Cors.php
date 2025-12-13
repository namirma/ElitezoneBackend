<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Cors
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $origin = $request->header('Origin');

        $allowedOrigin = $origin ?: '*';

        if (method_exists($response, 'header')) {
            $response->header('Access-Control-Allow-Origin', $allowedOrigin)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, X-Token-Auth, Authorization, Idempotency-Key')
                ->header('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
