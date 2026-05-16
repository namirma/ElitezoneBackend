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
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // ۱. لیست دقیق دامنه‌های مجاز پروژه شما (بدون اسلشِ آخر)
        $allowedOrigins = [
            'https://elitezone.me',
            'https://www.elitezone.me',
            'https://staging.elitezone.me',
            'http://localhost:3000',
            'http://localhost:8000'
        ];

        $origin = $request->header('Origin');

        // ۲. فقط اگر Origin درخواست داخل لیست مجاز ما بود، هدرها را اضافه کن
        if ($origin && in_array($origin, $allowedOrigins) && method_exists($response, 'header')) {
            $response->header('Access-Control-Allow-Origin', $origin)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, X-Token-Auth, Authorization, Idempotency-Key')
                ->header('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
