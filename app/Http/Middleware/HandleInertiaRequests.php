<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'whatsapp_number' => $user->whatsapp_number,
                    'tenant_id' => $user->tenant_id,
                    'role_id' => $user->role_id,
                    'is_super_admin' => $user->is_super_admin ?? false,
                    'is_premium' => $user->tenant?->subscriptions()
                        ->where('status', 'active')
                        ->where('plan', '!=', 'free')
                        ->where('ends_at', '>=', now())
                        ->exists() ?? false,
                    'tenant' => $user->tenant ? [
                        'id' => $user->tenant->id,
                        'name' => $user->tenant->name,
                        'slug' => $user->tenant->slug,
                    ] : null,
                    'role' => $user->role ? [
                        'id' => $user->role->id,
                        'name' => $user->role->name,
                        'slug' => $user->role->slug,
                    ] : null,
                    'tenants' => \App\Models\UserTenant::where('user_id', $user->id)
                        ->where('is_active', true)
                        ->with(['tenant', 'role'])
                        ->get()
                        ->map(function ($userTenant) use ($user) {
                            $tenant = $userTenant->tenant;
                            if (!$tenant) {
                                return null;
                            }
                            
                            return [
                                'id' => $tenant->id,
                                'name' => $tenant->name,
                                'slug' => $tenant->slug,
                                'role' => $userTenant->role ? [
                                    'id' => $userTenant->role->id,
                                    'name' => $userTenant->role->name,
                                    'slug' => $userTenant->role->slug,
                                ] : null,
                                'is_current' => session('current_tenant_id', $user->tenant_id) == $tenant->id,
                            ];
                        })
                        ->filter(),
                    'current_tenant_id' => session('current_tenant_id', $user->tenant_id),
                ] : null,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Super admin badge counts
            'pending_subscriptions_count' => $user && ($user->is_super_admin ?? false)
                ? \App\Models\Subscription::where('status', 'pending')->count()
                : 0,
        ];
    }
}
