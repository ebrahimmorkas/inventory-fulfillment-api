<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deactivating a user must lock them out immediately, even if they still
 * hold a valid API token.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->is_active) {
            abort(Response::HTTP_FORBIDDEN, 'This account has been deactivated.');
        }

        return $next($request);
    }
}
