<?php

namespace App\Http\Middleware;

use App\Services\PricingRoleResolver;
use Closure;
use Illuminate\Support\Facades\Auth;

class AttachActivePriceCategory
{
    public function __construct(private PricingRoleResolver $resolver) {}

    public function handle($request, Closure $next)
    {
        $user = auth('sanctum')->user();

        if ($user) {
            Auth::setUser($user);
            $code = $this->resolver->resolve($user);
        } else {
            $code = 'employer_without_elite_yar';
        }

        app()->instance('active_price_filter_category_code', $code);

        return $next($request);
    }
}
