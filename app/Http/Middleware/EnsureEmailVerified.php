<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response|JsonResponse
    {
        if (! $request->user() || ! $request->user()->email_verified_at) {
            return response()->json(['error' => 'Email verification required.'], 403);
        }

        return $next($request);
    }
}
