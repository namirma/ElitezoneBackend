<?php

namespace App\Services\Pricing\Pipes;

use App\Services\Pricing\PricingContext;
use Closure;

interface PricingPipe
{
    public function handle(PricingContext $context, Closure $next);
}
