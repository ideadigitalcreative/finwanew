<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        \Illuminate\Support\Facades\Log::info('EnsureSuperAdmin middleware called', [
            'path' => $request->path(),
            'method' => $request->method(),
            'user_id' => $request->user()?->id,
        ]);

        $user = $request->user();

        if (! $user) {
            \Illuminate\Support\Facades\Log::warning('EnsureSuperAdmin: User not authenticated', [
                'path' => $request->path(),
            ]);
            abort(403, 'User must be authenticated');
        }

        if (! $user->isSuperAdmin()) {
            \Illuminate\Support\Facades\Log::warning('EnsureSuperAdmin: User is not super admin', [
                'user_id' => $user->id,
                'is_super_admin' => $user->is_super_admin,
                'path' => $request->path(),
            ]);
            abort(403, 'Access denied. Super admin privileges required.');
        }

        \Illuminate\Support\Facades\Log::info('EnsureSuperAdmin: Access granted', [
            'user_id' => $user->id,
            'path' => $request->path(),
        ]);

        return $next($request);
    }
}
