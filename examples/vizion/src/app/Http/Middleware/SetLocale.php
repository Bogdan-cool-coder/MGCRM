<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && $request->user()->locale) {
            app()->setLocale($request->user()->locale);
        }

        return $next($request);
    }
}
