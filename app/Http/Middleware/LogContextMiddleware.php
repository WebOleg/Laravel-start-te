<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

class LogContextMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // default job type for web traffic
        Context::add('job_type', 'http_request');

        if ($request->route('acquirer')) {
            Context::add('acquirer', $request->route('acquirer'));
        } elseif ($request->hasHeader('X-Acquirer')) {
            Context::add('acquirer', $request->header('X-Acquirer'));
        }

        return $next($request);
    }
}
