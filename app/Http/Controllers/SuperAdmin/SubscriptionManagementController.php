<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WhatsAppNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionManagementController extends Controller
{
    /**
     * Display a listing of subscriptions
     */
    public function index(Request $request): Response
    {
        $baseQuery = Subscription::query()->with('tenant');

        // Search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $baseQuery->whereHas('tenant', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->has('status') && $request->status) {
            $baseQuery->where('status', $request->status);
        } else {
            // Default: Hide cancelled subscriptions to avoid clutter (e.g. old plans after upgrade)
            $baseQuery->where('status', '!=', 'cancelled');
        }

        // Filter by tenant
        if ($request->has('tenant_id') && $request->tenant_id) {
            $baseQuery->where('tenant_id', $request->tenant_id);
        }

        // Transform function
        $transform = function ($subscription) {
            return [
                'id' => $subscription->id,
                'tenant' => $subscription->tenant ? [
                    'id' => $subscription->tenant->id,
                    'name' => $subscription->tenant->name,
                ] : null,
                'plan' => $subscription->plan,
                'duration_months' => $subscription->duration_months,
                'price' => $subscription->price,
                'status' => $subscription->status,
                'starts_at' => $subscription->starts_at?->format('Y-m-d H:i:s'),
                'ends_at' => $subscription->ends_at?->format('Y-m-d H:i:s'),
                'payment_provider' => $subscription->payment_provider,
                'payment_reference' => $subscription->payment_reference,
                'payment_proof' => $subscription->payment_proof ? Storage::disk('public')->url($subscription->payment_proof) : null,
                'created_at' => $subscription->created_at->format('Y-m-d H:i:s'),
            ];
        };

        // Get premium subscriptions (growth, starter, pro, enterprise) - all data, no pagination
        $premiumSubscriptions = (clone $baseQuery)
            ->whereIn('plan', ['growth', 'starter', 'pro', 'enterprise'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map($transform)
            ->values();

        // Get free subscriptions with pagination (20 per page)
        $freeSubscriptionsPaginated = (clone $baseQuery)
            ->where('plan', 'free')
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        $freeSubscriptions = [
            'data' => $freeSubscriptionsPaginated->map($transform)->values(),
            'links' => $freeSubscriptionsPaginated->linkCollection()->toArray(),
            'meta' => [
                'current_page' => $freeSubscriptionsPaginated->currentPage(),
                'last_page' => $freeSubscriptionsPaginated->lastPage(),
                'per_page' => $freeSubscriptionsPaginated->perPage(),
                'total' => $freeSubscriptionsPaginated->total(),
                'from' => $freeSubscriptionsPaginated->firstItem(),
                'to' => $freeSubscriptionsPaginated->lastItem(),
            ],
        ];

        $tenants = Tenant::select('id', 'name')->orderBy('name')->get();

        return Inertia::render('SuperAdmin/Subscriptions/Index', [
            'premiumSubscriptions' => $premiumSubscriptions,
            'freeSubscriptions' => $freeSubscriptions,
            'tenants' => $tenants,
            'filters' => $request->only(['search', 'status', 'tenant_id', 'tab']),
        ]);
    }

    /**
     * Update subscription status
     */
    public function update(Request $request, Subscription $subscription)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,active,expired,cancelled',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
        ]);

        $oldStatus = $subscription->status;
        $newStatus = $validated['status'];

        DB::transaction(function () use ($subscription, $validated, $newStatus) {
            $subscription->update([
                'status' => $newStatus,
                'starts_at' => $validated['starts_at'] ? Carbon::parse($validated['starts_at']) : $subscription->starts_at,
                'ends_at' => $validated['ends_at'] ? Carbon::parse($validated['ends_at']) : $subscription->ends_at,
            ]);

            // Update tenant is_active based on subscription status
            $tenant = $subscription->tenant;
            if ($tenant) {
                if ($newStatus === 'active') {
                    $tenant->update([
                        'is_active' => true,
                        // Clear trial_ends_at so old expired trial date
                        // doesn't interfere with checkSubscriptionStatus()
                        'trial_ends_at' => null,
                    ]);

                    // Prevent duplicate active subscriptions:
                    // Cancel any OTHER active subscriptions for this tenant
                    Subscription::where('tenant_id', $tenant->id)
                        ->where('id', '!=', $subscription->id)
                        ->where('status', 'active')
                        ->update(['status' => 'cancelled']);

                } elseif (in_array($newStatus, ['expired', 'cancelled'])) {
                    // Check if tenant has other active subscriptions
                    $hasActiveSubscription = Subscription::where('tenant_id', $tenant->id)
                        ->where('status', 'active')
                        ->where('id', '!=', $subscription->id)
                        ->exists();

                    if (! $hasActiveSubscription) {
                        $tenant->update(['is_active' => false]);
                    }
                }
            }
        });

        if ($oldStatus !== 'active' && $newStatus === 'active') {
            $this->sendActivationNotification($subscription->fresh());
        }

        return redirect()->route('superadmin.subscriptions.index')
            ->with('success', 'Subscription berhasil diperbarui');
    }

    /**
     * Extend subscription
     */
    public function extend(Request $request, Subscription $subscription)
    {
        $validated = $request->validate([
            'months' => 'required|integer|min:1|max:12',
        ]);

        $currentEndsAt = $subscription->ends_at ?? Carbon::now();
        $newEndsAt = Carbon::parse($currentEndsAt)->addMonths($validated['months']);

        $subscription->update([
            'ends_at' => $newEndsAt,
            'duration_months' => $subscription->duration_months + $validated['months'],
            'status' => 'active',
        ]);

        return redirect()->route('superadmin.subscriptions.index')
            ->with('success', "Subscription diperpanjang {$validated['months']} bulan");
    }

    /**
     * Upgrade subscription plan (e.g. from free trial to paid tier)
     */
    public function upgrade(Request $request, Subscription $subscription)
    {
        $validated = $request->validate([
            'plan' => 'required|in:starter,growth,pro,enterprise',
            'duration_months' => 'required|integer|min:1|max:12',
            'price' => 'required|numeric|min:0',
            'status' => 'nullable|in:pending,active',
            'starts_at' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
        ]);

        $previousPlan = $subscription->plan;
        $oldStatus = $subscription->status;
        $newStatus = $validated['status'] ?? 'pending';

        DB::transaction(function () use ($subscription, $validated, $previousPlan, $newStatus) {
            $startsAt = $validated['starts_at']
                ? Carbon::parse($validated['starts_at'])
                : Carbon::now();

            $endsAt = (clone $startsAt)->addMonths($validated['duration_months']);

            $metadata = $subscription->metadata ?? [];
            $metadata['upgraded_at'] = Carbon::now()->toIso8601String();
            $metadata['upgraded_from'] = $previousPlan;

            if (! empty($validated['notes'])) {
                $metadata['upgrade_notes'] = $validated['notes'];
            }

            $history = $metadata['upgrade_history'] ?? [];
            $history[] = [
                'from' => $previousPlan,
                'to' => $validated['plan'],
                'duration_months' => $validated['duration_months'],
                'price' => $validated['price'],
                'status' => $newStatus,
                'timestamp' => Carbon::now()->toIso8601String(),
                'notes' => $validated['notes'] ?? null,
            ];
            $metadata['upgrade_history'] = $history;

            $subscription->update([
                'plan' => $validated['plan'],
                'duration_months' => $validated['duration_months'],
                'price' => $validated['price'],
                'status' => $newStatus,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'payment_provider' => $subscription->payment_provider ?? 'manual',
                'payment_reference' => $newStatus === 'active' ? $subscription->payment_reference : null,
                'metadata' => $metadata,
            ]);

            if ($newStatus === 'active') {
                $tenant = $subscription->tenant;
                if ($tenant) {
                    // Activate tenant
                    $tenant->update([
                        'is_active' => true,
                        // Clear trial_ends_at so old expired trial date
                        // doesn't interfere with checkSubscriptionStatus()
                        'trial_ends_at' => null,
                    ]);

                    // Cancel any OTHER active subscriptions for this tenant
                    // to prevent duplicate active subscriptions confusing the system
                    Subscription::where('tenant_id', $tenant->id)
                        ->where('id', '!=', $subscription->id)
                        ->where('status', 'active')
                        ->update(['status' => 'cancelled']);

                    Log::info('Subscription upgraded successfully', [
                        'subscription_id' => $subscription->id,
                        'tenant_id' => $tenant->id,
                        'from_plan' => $previousPlan,
                        'to_plan' => $validated['plan'],
                        'status' => $newStatus,
                        'starts_at' => $startsAt->toIso8601String(),
                        'ends_at' => $endsAt->toIso8601String(),
                        'trial_ends_at_cleared' => true,
                    ]);
                }
            }
        });

        if ($oldStatus !== 'active' && $newStatus === 'active') {
            $this->sendActivationNotification($subscription->fresh());
        }

        return redirect()->route('superadmin.subscriptions.index')
            ->with('success', 'Subscription berhasil diupgrade');
    }

    /**
     * Delete subscription
     */
    public function destroy(Subscription $subscription)
    {
        try {
            DB::transaction(function () use ($subscription) {
                $tenant = $subscription->tenant;

                // Delete the subscription
                $subscription->delete();

                // If tenant exists, check if they have other active subscriptions
                if ($tenant) {
                    $hasActiveSubscription = Subscription::where('tenant_id', $tenant->id)
                        ->where('status', 'active')
                        ->exists();

                    if (! $hasActiveSubscription) {
                        $tenant->update(['is_active' => false]);
                    }
                }
            });

            Log::info('Subscription deleted by super admin', [
                'subscription_id' => $subscription->id,
                'tenant_id' => $subscription->tenant_id,
            ]);

            return redirect()->route('superadmin.subscriptions.index')
                ->with('success', 'Subscription berhasil dihapus');

        } catch (\Exception $e) {
            Log::error('Failed to delete subscription', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('superadmin.subscriptions.index')
                ->with('error', 'Gagal menghapus subscription: '.$e->getMessage());
        }
    }

    /**
     * Send activation notification via WhatsApp (non-blocking)
     */
    protected function sendActivationNotification(?Subscription $subscription): void
    {
        if (! $subscription) {
            return;
        }

        try {
            $tenant = $subscription->tenant;
            if (! $tenant) {
                return;
            }

            $user = User::where('tenant_id', $tenant->id)
                ->whereHas('role', function ($query) {
                    $query->whereIn('slug', ['owner', 'admin']);
                })
                ->first();

            if (! $user) {
                $user = $tenant->users()->first();
            }

            if (! $user || ! $user->whatsapp_number) {
                return;
            }

            register_shutdown_function(function () use ($user, $tenant, $subscription) {
                try {
                    $notificationService = app(WhatsAppNotificationService::class);
                    $notificationService->sendActivationNotification($user, $tenant, $subscription);
                } catch (\Exception $e) {
                    Log::error('Failed to send activation notification', [
                        'user_id' => $user->id,
                        'subscription_id' => $subscription->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        } catch (\Exception $e) {
            Log::error('Failed to send activation notification', [
                'subscription_id' => $subscription->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
