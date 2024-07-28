<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Sanitizer
{
    /**
     * Handle an incoming request.
     *
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $sanitized = array_map(function ($input) {
            return is_array($input) ? array_map('trim', $input) : trim($input);
        }, $request->all());

        $request->merge($sanitized);

        return $next($request);
    }
}
