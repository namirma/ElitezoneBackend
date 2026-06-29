<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanSeeTicket
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $ticket = $request->route('ticket');
        $user = $request->user();

        if (! $ticket instanceof \App\Models\Ticket || ! $user) {
            return error(__('auth.forbidden'), [], 403);
        }

        // صاحب تیکت یا ادمین (برای پشتیبانی) مجازند
        if ((int) $ticket->user_id !== (int) $user->id && ! $user->hasRole('admin')) {
            return error(__('auth.forbidden'), [], 403);
        }

        return $next($request);
    }
}
