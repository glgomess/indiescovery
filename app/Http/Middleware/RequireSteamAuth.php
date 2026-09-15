<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Sends visitors without a verified steam id in their session to the Steam login. */
class RequireSteamAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->has('steam_id')) {
            return redirect()->route('steam.login');
        }

        return $next($request);
    }
}
