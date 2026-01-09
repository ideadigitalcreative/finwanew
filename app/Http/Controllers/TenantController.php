<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends Controller
{
    /**
     * Switch to different tenant
     */
    public function switch(Request $request, Tenant $tenant)
    {
        $user = $request->user();

        // Verify user belongs to this tenant
        if (!$user->belongsToTenant($tenant->id)) {
            abort(403, 'You do not have access to this tenant');
        }

        // Set current tenant in session
        session(['current_tenant_id' => $tenant->id]);

        return redirect('/dashboard')->with('success', 'Switched to ' . $tenant->name);
    }

    /**
     * List user's tenants
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $tenants = $user->activeTenants()
            ->with(['roles'])
            ->get()
            ->map(function ($tenant) use ($user) {
                $membership = $tenant->pivot;
                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'is_active' => $tenant->is_active,
                    'role' => $membership->role ? [
                        'id' => $membership->role->id,
                        'name' => $membership->role->name,
                        'slug' => $membership->role->slug,
                    ] : null,
                    'joined_at' => $membership->joined_at,
                    'is_current' => session('current_tenant_id', $user->tenant_id) == $tenant->id,
                ];
            });

        return Inertia::render('Tenants/Index', [
            'tenants' => $tenants,
            'current_tenant_id' => session('current_tenant_id', $user->tenant_id),
        ]);
    }
}
