<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureOwnerRole
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user() || $request->user()->role !== 'owner') {
            return response()->json(['message' => 'Forbidden: Owner access required.'], 403);
        }
        return $next($request);
    }
}