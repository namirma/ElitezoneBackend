<?php

namespace App\Http\Middleware;

use App\Services\PricingRoleResolver;
use Closure;

class AttachActivePriceCategory
{
    public function __construct(private PricingRoleResolver $resolver) {}

    public function handle($request, Closure $next)
    {
        $code = auth()->check()
            ? $this->resolver->resolve($request->user())
            : 'employer_without_consultant';

        app()->instance('active_price_filter_category_code', $code);
        return $next($request);
    }
}
