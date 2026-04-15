<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsAdmin
{
    public function handle(Request $request, Closure $next): Response|JsonResponse
    {
        if (! $request->user() || ! $request->user()->is_admin) {
            return response()->json(['error' => 'Forbidden. Admin access required.'], 403);
        }

        return $next($request);
    }
}
