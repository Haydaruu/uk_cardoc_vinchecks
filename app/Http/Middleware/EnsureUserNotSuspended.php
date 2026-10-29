<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block suspended users from performing write/purchase/check actions.
 * Suspended users can still access read-only routes (history, reports, invoices).
 */
class EnsureUserNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->is_suspended) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your account is currently suspended. You cannot perform this action.',
                ], 403);
            }

            abort(403, 'Your account is currently suspended. You cannot perform this action.');
        }

        return $next($request);
    }
}
