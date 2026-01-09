<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionExpiredController extends Controller
{
    /**
     * Show the subscription expired page
     */
    public function index(Request $request): Response
    {
        $tenant = Tenant::findOrFail($request->tenant_id);

        // Get the latest subscription info
        $latestSubscription = Subscription::where('tenant_id', $tenant->id)
            ->orderBy('ends_at', 'desc')
            ->first();

        // Get pending subscription if exists
        $pendingSubscription = Subscription::where('tenant_id', $tenant->id)
            ->where('status', 'pending')
            ->first();

        return Inertia::render('Subscriptions/Expired', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
            'latestSubscription' => $latestSubscription ? [
                'plan' => $latestSubscription->plan,
                'status' => $latestSubscription->status,
                'ends_at' => $latestSubscription->ends_at?->toISOString(),
            ] : null,
            'pendingSubscription' => $pendingSubscription ? [
                'id' => $pendingSubscription->id,
                'plan' => $pendingSubscription->plan,
                'status' => $pendingSubscription->status,
            ] : null,
        ]);
    }
}
