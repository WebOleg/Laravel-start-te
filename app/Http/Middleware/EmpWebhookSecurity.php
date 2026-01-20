<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EmpWebhookSecurity
{
    private const MAX_BODY_SIZE = 65536;
    private const RATE_LIMIT = 100;
    private const RATE_WINDOW = 60;

    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->reject('Method not allowed', 405);
        }

        $expectedToken = config('services.emp.webhook_token');
        $requestToken = $request->route('token');

        if (!$expectedToken || !hash_equals($expectedToken, $requestToken ?? '')) {
            Log::warning('EMP webhook invalid token', ['ip' => $request->ip()]);
            return $this->reject('Forbidden', 403);
        }

        $contentType = $request->header('Content-Type', '');
        if (!str_contains($contentType, 'application/x-www-form-urlencoded')) {
            return $this->reject('Invalid content type', 415);
        }

        $size = strlen($request->getContent());
        if ($size > self::MAX_BODY_SIZE) {
            return $this->reject('Payload too large', 413);
        }

        if (!$this->checkRateLimit($request->ip())) {
            Log::warning('EMP webhook rate limit exceeded', ['ip' => $request->ip()]);
            return $this->reject('Too many requests', 429);
        }

        return $next($request);
    }

    private function checkRateLimit(string $ip): bool
    {
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            $ipLong = crc32($ip);
        }

        $key = 'wh_rl:' . $ipLong;
        $count = (int) Cache::get($key, 0);

        if ($count >= self::RATE_LIMIT) {
            return false;
        }

        Cache::put($key, $count + 1, self::RATE_WINDOW);
        return true;
    }

    private function reject(string $message, int $status): Response
    {
        return response($message, $status);
    }
}
