<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class OptionalApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken()) {
            try {
                Auth::guard('api')->authenticate();
            } catch (Throwable) {
                // Un token inválido no bloquea la consulta pública por número y correo.
            }
        }

        return $next($request);
    }
}
