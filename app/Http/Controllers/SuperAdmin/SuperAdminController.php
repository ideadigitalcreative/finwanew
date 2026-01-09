<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Tenant;
use App\Models\Subscription;
use App\Models\Bank;
use App\Models\Transaction;
use App\Models\Channel;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\DB;

class SuperAdminController extends Controller
{
    /**
     * Display super admin dashboard
     */
    public function index(Request $request): Response
    {
        // Calculate total revenue from all active subscriptions
        $totalRevenue = Subscription::where('status', 'active')
            ->sum('price');

        // Pending subscriptions count
        $pendingSubscriptionsCount = Subscription::where('status', 'pending')->count();

        // Expiring soon (within 7 days)
        $expiringSoonCount = Subscription::where('status', 'active')
            ->where('ends_at', '>', now())
            ->where('ends_at', '<=', now()->addDays(7))
            ->count();

        // New users this week
        $newUsersThisWeek = User::where('created_at', '>=', now()->startOfWeek())->count();
        $newUsersLastWeek = User::where('created_at', '>=', now()->subWeek()->startOfWeek())
            ->where('created_at', '<', now()->startOfWeek())
            ->count();

        // Subscription conversion rate (active subscriptions / total tenants with users)
        $tenantsWithUsers = Tenant::has('users')->count();
        $conversionRate = $tenantsWithUsers > 0 
            ? round((Subscription::where('status', 'active')->count() / $tenantsWithUsers) * 100, 1)
            : 0;

        // Get statistics
        $stats = [
            'total_users' => User::count(),
            'total_revenue' => (float) $totalRevenue,
            'active_tenants' => Tenant::where('is_active', true)->count(),
            'total_subscriptions' => Subscription::count(),
            'active_subscriptions' => Subscription::where('status', 'active')->count(),
            'pending_subscriptions' => $pendingSubscriptionsCount,
            'expiring_soon' => $expiringSoonCount,
            'total_transactions' => Transaction::count(),
            'total_channels' => Channel::where('type', 'whatsapp')->count(),
            'total_banks' => Bank::where('is_active', true)->count(),
            'new_users_this_week' => $newUsersThisWeek,
            'new_users_last_week' => $newUsersLastWeek,
            'conversion_rate' => $conversionRate,
        ];

        // Get pending subscriptions (for quick action)
        $pendingSubscriptions = Subscription::with('tenant')
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($subscription) {
                return [
                    'id' => $subscription->id,
                    'tenant_name' => $subscription->tenant->name ?? 'N/A',
                    'plan' => $subscription->plan,
                    'status' => $subscription->status,
                    'price' => $subscription->price,
                    'duration_months' => $subscription->duration_months,
                    'starts_at' => $subscription->starts_at?->format('Y-m-d H:i:s'),
                    'ends_at' => $subscription->ends_at?->format('Y-m-d H:i:s'),
                    'created_at' => $subscription->created_at->format('Y-m-d H:i:s'),
                    'payment_proof' => $subscription->payment_proof ? \Illuminate\Support\Facades\Storage::disk('public')->url($subscription->payment_proof) : null,
                ];
            });

        // Get expiring soon subscriptions
        $expiringSubscriptions = Subscription::with('tenant')
            ->where('status', 'active')
            ->where('ends_at', '>', now())
            ->where('ends_at', '<=', now()->addDays(7))
            ->orderBy('ends_at', 'asc')
            ->limit(5)
            ->get()
            ->map(function ($subscription) {
                return [
                    'id' => $subscription->id,
                    'tenant_name' => $subscription->tenant->name ?? 'N/A',
                    'plan' => $subscription->plan,
                    'ends_at' => $subscription->ends_at?->format('Y-m-d H:i:s'),
                    'days_left' => $subscription->ends_at ? (int) ceil(now()->floatDiffInDays($subscription->ends_at, false)) : 0,
                ];
            });

        // Monthly revenue data (last 6 months)
        $monthlyRevenue = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $revenue = Subscription::where('status', 'active')
                ->whereMonth('created_at', $month->month)
                ->whereYear('created_at', $month->year)
                ->sum('price');
            
            $monthlyRevenue[] = [
                'month' => $month->format('M Y'),
                'revenue' => (float) $revenue,
            ];
        }

        // Get recent subscriptions
        $recentSubscriptions = Subscription::with('tenant')
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get()
            ->map(function ($subscription) {
                return [
                    'id' => $subscription->id,
                    'tenant_name' => $subscription->tenant->name ?? 'N/A',
                    'plan' => $subscription->plan,
                    'status' => $subscription->status,
                    'price' => $subscription->price,
                    'duration_months' => $subscription->duration_months,
                    'starts_at' => $subscription->starts_at?->format('Y-m-d H:i:s'),
                    'ends_at' => $subscription->ends_at?->format('Y-m-d H:i:s'),
                    'created_at' => $subscription->created_at->format('Y-m-d H:i:s'),
                ];
            });

        // Get recent tenants (only those with users)
        $recentTenants = Tenant::withCount(['users', 'subscriptions'])
            ->has('users') // Only tenants that have at least 1 user
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get()
            ->map(function ($tenant) {
                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'is_active' => $tenant->is_active,
                    'users_count' => $tenant->users_count,
                    'subscriptions_count' => $tenant->subscriptions_count,
                    'created_at' => $tenant->created_at->format('Y-m-d H:i:s'),
                ];
            });

        return Inertia::render('SuperAdmin/Dashboard', [
            'stats' => $stats,
            'recentSubscriptions' => $recentSubscriptions,
            'recentTenants' => $recentTenants,
            'pendingSubscriptions' => $pendingSubscriptions,
            'expiringSubscriptions' => $expiringSubscriptions,
            'monthlyRevenue' => $monthlyRevenue,
        ]);
    }
}
