<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogAllRequests
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only log POST requests to superadmin/whatsapp for debugging
        if ($request->method() === 'POST' && $request->path() === 'superadmin/whatsapp') {
            Log::info('LogAllRequests: POST request to superadmin/whatsapp', [
                'method' => $request->method(),
                'path' => $request->path(),
                'full_url' => $request->fullUrl(),
                'user_id' => $request->user()?->id,
                'is_super_admin' => $request->user()?->is_super_admin ?? false,
                'headers' => [
                    'X-Inertia' => $request->header('X-Inertia'),
                    'X-Requested-With' => $request->header('X-Requested-With'),
                    'Content-Type' => $request->header('Content-Type'),
                ],
                'has_csrf_token' => $request->has('_token') || $request->header('X-CSRF-TOKEN'),
            ]);
        }

        return $next($request);
    }
}
