<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAccess
{
    /**
     * Handle an incoming request.
     * Ensure user can only access their own tenant data
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(403, 'User must be authenticated');
        }

        // Super admin bypass tenant check
        if ($user->isSuperAdmin()) {
            // For super admin, set a default tenant_id if needed, but don't enforce tenant membership
            $currentTenantId = session('current_tenant_id', $user->tenant_id ?? 1);
            $request->merge(['tenant_id' => $currentTenantId]);
            return $next($request);
        }

        // Get current tenant from session or use default
        $currentTenantId = session('current_tenant_id', $user->tenant_id);
        
        // Check if user belongs to this tenant
        if (!$user->belongsToTenant($currentTenantId)) {
            // Fallback to first active tenant
            $firstTenant = $user->activeTenants()->first();
            if ($firstTenant) {
                $currentTenantId = $firstTenant->id;
                session(['current_tenant_id' => $currentTenantId]);
            } else {
                abort(403, 'User must belong to at least one active tenant');
            }
        }

        // Get tenant and verify it's active
        $tenant = Tenant::find($currentTenantId);
        if (!$tenant) {
            abort(403, 'Tenant not found');
        }
        
        // Allow access to tenant with pending subscription (user just registered)
        // Tenant is not active but user can still access dashboard to upload payment proof
        if (!$tenant->is_active) {
            // Check if tenant has pending subscription
            $hasPendingSubscription = \App\Models\Subscription::where('tenant_id', $tenant->id)
                ->where('status', 'pending')
                ->exists();
            
            if (!$hasPendingSubscription) {
                // No pending subscription, reject access
                abort(403, 'Tenant is not active');
            }
            // Has pending subscription, allow access (user can upload payment proof)
        }

        // Add tenant_id to request for easy access in controllers
        $request->merge(['tenant_id' => $currentTenantId]);

        return $next($request);
    }
}
