<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DdosProtection
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, int $maxRequests = 100, int $decayMinutes = 1): Response
    {
        $ip = $request->ip();
        $path = $request->path();
        $method = $request->method();

        // Skip rate limiting for static assets and health checks
        if ($this->isStaticAsset($path) || $this->isHealthCheck($path)) {
            return $next($request);
        }

        $key = "ddos:{$ip}:{$method}:{$path}";

        // Get current request count
        $requests = Cache::get($key, 0);

        // Check if limit exceeded
        if ($requests >= $maxRequests) {
            Log::warning('DDOS protection triggered', [
                'ip' => $ip,
                'path' => $path,
                'method' => $method,
                'requests' => $requests,
                'max_requests' => $maxRequests,
            ]);

            return response()->json([
                'error' => 'Terlalu banyak request. Silakan coba lagi nanti.',
                'retry_after' => $decayMinutes * 60,
            ], 429, [
                'Retry-After' => $decayMinutes * 60,
                'X-RateLimit-Limit' => $maxRequests,
                'X-RateLimit-Remaining' => 0,
                'X-RateLimit-Reset' => now()->addMinutes($decayMinutes)->timestamp,
            ]);
        }

        // Increment counter
        Cache::put($key, $requests + 1, now()->addMinutes($decayMinutes));

        $response = $next($request);

        // Add rate limit headers to response
        $remaining = max(0, $maxRequests - ($requests + 1));
        $response->headers->set('X-RateLimit-Limit', $maxRequests);
        $response->headers->set('X-RateLimit-Remaining', $remaining);
        $response->headers->set('X-RateLimit-Reset', now()->addMinutes($decayMinutes)->timestamp);

        return $response;
    }

    /**
     * Check if the path is a static asset
     */
    private function isStaticAsset(string $path): bool
    {
        $staticExtensions = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot'];
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return in_array(strtolower($extension), $staticExtensions) ||
               str_starts_with($path, 'storage/') ||
               str_starts_with($path, 'assets/');
    }

    /**
     * Check if the path is a health check endpoint
     */
    private function isHealthCheck(string $path): bool
    {
        return $path === 'up' || str_starts_with($path, 'health');
    }
}
