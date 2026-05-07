<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAuth
{
    /**
     * Handle an incoming request.
     * Validates API key from header or query parameter.
     */
    public function handle(Request $request, Closure $next, string $keyType = 'webhook'): Response
    {
        // Get expected API key based on type
        $expectedKey = match ($keyType) {
            'webhook' => config('services.webhook.api_key'),
            'ocr' => config('services.ocr_worker.api_key'),
            'internal' => config('services.internal.api_key'),
            default => config('services.webhook.api_key'),
        };

        // Abort if API key not configured
        if (empty($expectedKey)) {
            return response()->json([
                'success' => false,
                'error' => 'API key not configured on server',
            ], 500);
        }

        // Get API key from request (header or query param)
        $providedKey = $request->header('X-Api-Key')
            ?? $request->header('Authorization')
            ?? $request->query('api_key');

        // Remove "Bearer " prefix if present
        if (str_starts_with($providedKey ?? '', 'Bearer ')) {
            $providedKey = substr($providedKey, 7);
        }

        // Validate API key
        if (empty($providedKey) || ! hash_equals($expectedKey, $providedKey)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid or missing API key',
            ], 401);
        }

        return $next($request);
    }
}
