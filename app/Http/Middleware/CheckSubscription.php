<?php

namespace App\Http\Middleware;

use App\Models\Subscription;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscription
{
    /**
     * Routes that should be accessible even with expired subscription
     */
    protected array $allowedRoutes = [
        'subscription.expired',
        'subscriptions.index',
        'subscriptions.request',
        'subscriptions.upload-payment-proof',
        'checkout',
        'checkout.process',
        'logout',
        'profile.edit',
        'profile.update',
        'profile.destroy',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        
        // Skip for super admin
        if ($user && $user->is_super_admin) {
            return $next($request);
        }

        // Skip for allowed routes
        $currentRouteName = $request->route()?->getName();
        if ($currentRouteName && in_array($currentRouteName, $this->allowedRoutes)) {
            return $next($request);
        }

        // Check if user has tenant
        if (!$request->tenant_id) {
            return $next($request);
        }

        $tenant = Tenant::find($request->tenant_id);
        if (!$tenant) {
            return $next($request);
        }

        // Check for active subscription
        $hasActiveSubscription = Subscription::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->exists();

        // Check if in trial period
        $isInTrial = $tenant->trial_ends_at && $tenant->trial_ends_at->isFuture();

        // If no active subscription and not in trial, redirect to expired page
        if (!$hasActiveSubscription && !$isInTrial) {
            // Check if they ever had a paid subscription
            $hadPaidSubscription = Subscription::where('tenant_id', $tenant->id)
                ->whereNotIn('plan', ['free', 'trial'])
                ->exists();

            if ($hadPaidSubscription) {
                // Had paid subscription but expired
                return redirect()->route('subscription.expired');
            }

            // Check if free plan has expired
            $freeSubscription = Subscription::where('tenant_id', $tenant->id)
                ->where('plan', 'free')
                ->where('status', 'active')
                ->whereNotNull('ends_at')
                ->where('ends_at', '<', now())
                ->first();

            if ($freeSubscription) {
                // Free plan expired - update status and redirect
                $freeSubscription->update(['status' => 'expired']);
                return redirect()->route('subscription.expired');
            }
        }

        return $next($request);
    }
}
