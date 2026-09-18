<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Rejects visitors without a verified steam id: API callers get a 401 JSON, browsers go to the Steam login. */
class RequireSteamAuth
{
    /** Lets the request through only when the session holds a steam id. */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->has('steam_id')) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        return redirect()->route('steam.login');
    }
}
