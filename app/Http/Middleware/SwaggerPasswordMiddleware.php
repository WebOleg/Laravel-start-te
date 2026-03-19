<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SwaggerPasswordMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (!str_starts_with($request->path(), 'api/documentation') && !str_starts_with($request->path(), 'docs')) {
            return $next($request);
        }

        $password = env('SWAGGER_PASSWORD');

        if (!$password) {
            return $next($request);
        }

        if ($request->query('password') === $password) {
            $token = hash('sha256', $password . config('app.key'));
            $cookie = cookie('swagger_token', $token, 60 * 24); // 24 hours
            return redirect($request->url())->withCookie($cookie);
        }

        $token = $request->cookie('swagger_token');
        $expected = hash('sha256', $password . config('app.key'));

        if ($token === $expected) {
            return $next($request);
        }

        abort(403, 'Access denied. Append ?password=YOUR_PASSWORD to the URL.');
    }
}
