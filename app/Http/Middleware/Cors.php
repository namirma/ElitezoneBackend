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
     * @return Response
     */
    /**
     * لیست دقیق دامنه‌های مجاز (بدون اسلشِ آخر). با config/cors.php هم‌راستاست.
     */
    private const ALLOWED_ORIGINS = [
        'https://elitezone.me',
        'https://www.elitezone.me',
        'https://staging.elitezone.me',
        // استیجینگ روی http سرو می‌شود؛ این اوریجین باید مجاز باشد تا ورود کار کند.
        'http://staging.elitezone.me',
        'http://localhost:3000',
        'http://localhost:8000',
        'http://localhost:5173',
        'http://localhost:5174',
    ];

    public function handle(Request $request, Closure $next)
    {
        $origin = $request->header('Origin');
        $isAllowed = $origin && in_array($origin, self::ALLOWED_ORIGINS, true);

        // درخواستِ preflight (OPTIONS) باید مستقیماً و بدون عبور از احراز هویت/ریدایرکت
        // پاسخ داده شود. اگر preflight وارد پشتهٔ middleware شود و ریدایرکت بگیرد،
        // مرورگر آن را رد می‌کند: «Redirect is not allowed for a preflight request».
        if ($request->getMethod() === 'OPTIONS') {
            $response = response('', 204);
            if ($isAllowed) {
                $this->applyCorsHeaders($response, $origin);
            }

            return $response;
        }

        $response = $next($request);

        if ($isAllowed && method_exists($response, 'header')) {
            $this->applyCorsHeaders($response, $origin);
        }

        return $response;
    }

    private function applyCorsHeaders(Response $response, string $origin): void
    {
        $response->header('Access-Control-Allow-Origin', $origin)
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, X-Token-Auth, Authorization, Idempotency-Key')
            ->header('Access-Control-Allow-Credentials', 'true')
            ->header('Access-Control-Max-Age', '86400');
    }
}
