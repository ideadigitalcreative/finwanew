<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Carbon\Carbon;

class SubscriptionController extends Controller
{
    /**
     * Show subscription management or wizard
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $tenant = Tenant::findOrFail($request->tenant_id);

        // Only admin/owner can see subscription
        if (!$user->isAdmin()) {
            abort(403);
        }

        $currentSubscriptions = Subscription::where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'pending'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END") // Prioritize pending over active
            ->orderBy('created_at', 'desc')
            ->get();

        // Get active subscription (for display)
        $activeSubscription = $currentSubscriptions->first(function ($sub) {
            return $sub->status === 'active';
        });

        // Get pending subscription (prioritize for display and upload)
        $pendingSubscription = $currentSubscriptions->first(function ($sub) {
            return $sub->status === 'pending';
        });

        // Display pending subscription if exists, otherwise active
        $subscription = $pendingSubscription ?? $activeSubscription;
        
        // Check if user has any subscription history
        $hasAnySubscription = Subscription::where('tenant_id', $tenant->id)->exists();
        
        // If no subscription history and no active/pending, show wizard
        if (!$hasAnySubscription) {
            $planDetails = [
                'name' => 'Paket Lengkap',
                'monthly_price' => 20000,
            ];
            
            $durationOptions = $this->getDurationOptions();
            
            $banks = Bank::where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(function ($bank) {
                    return [
                        'id' => $bank->id,
                        'name' => $bank->name,
                        'account_number' => $bank->account_number,
                        'account_name' => $bank->account_name,
                    ];
                });
            
            return Inertia::render('Subscriptions/Wizard', [
                'planDetails' => $planDetails,
                'durationOptions' => $durationOptions,
                'banks' => $banks,
            ]);
        }

        // Get pending request (user-requested subscription)
        $pendingRequest = $currentSubscriptions->first(function ($sub) {
            $metadata = $sub->metadata ?? [];
            return $sub->status === 'pending'
                && ($metadata['requested_by_user'] ?? false);
        });

        $planDetails = [
            'name' => 'Paket Lengkap',
            'monthly_price' => 20000,
        ];
        
        $durationOptions = $this->getDurationOptions();

        $subscriptions = Subscription::where('tenant_id', $tenant->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($sub) {
                return [
                    'id' => $sub->id,
                    'package' => $sub->plan, // Map plan to package for frontend
                    'status' => $sub->status,
                    'starts_at' => $sub->starts_at?->toISOString(),
                    'ends_at' => $sub->ends_at?->toISOString(),
                    'created_at' => $sub->created_at->toISOString(),
                ];
            });

        // Get active banks for payment
        $banks = Bank::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function ($bank) {
                return [
                    'id' => $bank->id,
                    'name' => $bank->name,
                    'account_number' => $bank->account_number,
                    'account_name' => $bank->account_name,
                    'description' => $bank->description,
                ];
            });

        return Inertia::render('Subscriptions/Index', [
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'package' => $subscription->plan, // Map plan to package for frontend
                'status' => $subscription->status,
                'starts_at' => $subscription->starts_at?->toISOString(),
                'ends_at' => $subscription->ends_at?->toISOString(),
                'payment_provider' => $subscription->payment_provider,
                'payment_reference' => $subscription->payment_reference,
                'payment_proof' => $subscription->payment_proof ? Storage::disk('public')->url($subscription->payment_proof) : null,
                'price' => $subscription->price,
            ] : null,
            'activeSubscription' => $activeSubscription ? [
                'id' => $activeSubscription->id,
                'package' => $activeSubscription->plan,
                'status' => $activeSubscription->status,
                'starts_at' => $activeSubscription->starts_at?->toISOString(),
                'ends_at' => $activeSubscription->ends_at?->toISOString(),
            ] : null,
            'pendingSubscription' => $pendingSubscription ? [
                'id' => $pendingSubscription->id,
                'package' => $pendingSubscription->plan,
                'status' => $pendingSubscription->status,
                'starts_at' => $pendingSubscription->starts_at?->toISOString(),
                'ends_at' => $pendingSubscription->ends_at?->toISOString(),
                'payment_provider' => $pendingSubscription->payment_provider,
                'payment_reference' => $pendingSubscription->payment_reference,
                'payment_proof' => $pendingSubscription->payment_proof ? Storage::disk('public')->url($pendingSubscription->payment_proof) : null,
                'price' => $pendingSubscription->price,
            ] : null,
            'subscriptions' => $subscriptions,
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'is_active' => $tenant->is_active,
                'trial_ends_at' => $tenant->trial_ends_at?->toISOString(),
            ],
            'planDetails' => $planDetails,
            'durationOptions' => $durationOptions,
            'banks' => $banks,
            'pendingRequest' => $pendingRequest ? [
                'id' => $pendingRequest->id,
                'plan' => $pendingRequest->plan,
                'duration_months' => $pendingRequest->duration_months,
                'price' => $pendingRequest->price,
                'status' => $pendingRequest->status,
                'created_at' => $pendingRequest->created_at->toISOString(),
                'request_type' => $pendingRequest->metadata['request_type'] ?? 'upgrade',
                'notes' => $pendingRequest->metadata['requested_notes'] ?? null,
            ] : null,
        ]);
    }

    /**
     * Show 3-step subscription wizard
     */
    public function wizard(Request $request): Response
    {
        $user = $request->user();
        $tenant = Tenant::findOrFail($request->tenant_id);

        if (!$user->isAdmin()) {
            abort(403);
        }

        $planDetails = [
            'name' => 'Paket Lengkap',
            'monthly_price' => 20000,
        ];
        
        $durationOptions = $this->getDurationOptions();
        
        $banks = Bank::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function ($bank) {
                return [
                    'id' => $bank->id,
                    'name' => $bank->name,
                    'account_number' => $bank->account_number,
                    'account_name' => $bank->account_name,
                ];
            });
        
        return Inertia::render('Subscriptions/Wizard', [
            'planDetails' => $planDetails,
            'durationOptions' => $durationOptions,
            'banks' => $banks,
        ]);
    }

    /**
     * Upload payment proof
     */
    public function uploadPaymentProof(Request $request, Subscription $subscription)
    {
        $user = $request->user();
        $tenant = Tenant::findOrFail($request->tenant_id);

        // Only admin/owner can upload payment proof
        if (!$user->isAdmin()) {
            abort(403);
        }

        // Verify subscription belongs to tenant
        if ($subscription->tenant_id !== $tenant->id) {
            abort(403);
        }

        // Only allow upload for pending subscriptions
        if ($subscription->status !== 'pending') {
            return redirect()->back()->with('error', 'Hanya subscription dengan status pending yang dapat mengupload bukti pembayaran');
        }

        $request->validate([
            'payment_proof' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120', // 5MB max
        ]);

        // Delete old payment proof if exists
        if ($subscription->payment_proof) {
            // Check if it's a full URL or just path
            $oldPath = $subscription->payment_proof;
            // Remove storage URL prefix if exists
            if (str_contains($oldPath, '/storage/')) {
                $oldPath = str_replace('/storage/', '', $oldPath);
            }
            // Remove domain and path if it's a full URL
            if (str_contains($oldPath, 'http')) {
                $parsedUrl = parse_url($oldPath);
                $oldPath = ltrim($parsedUrl['path'] ?? '', '/storage/');
            }
            
            if (Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        // Store payment proof
        $path = $request->file('payment_proof')->store(
            "subscriptions/{$tenant->id}/payment-proofs",
            'public'
        );

        $subscription->update([
            'payment_proof' => $path,
        ]);

        return redirect()->back()->with('success', 'Bukti pembayaran berhasil diupload');
    }

    /**
     * Update subscription
     */
    public function update(Request $request, Subscription $subscription)
    {
        $user = $request->user();

        // Only admin/owner can update
        if (!$user->isAdmin()) {
            abort(403);
        }

        // Verify subscription belongs to tenant
        if ($subscription->tenant_id !== $request->tenant_id) {
            abort(403);
        }

        $request->validate([
            'package' => 'required|in:starter,growth,pro,enterprise',
            'status' => 'required|in:active,cancelled,expired',
        ]);

        $subscription->update($request->only(['package', 'status']));

        return redirect()->back()->with('success', 'Subscription updated');
    }

    /**
     * Request new subscription or extension
     */
    public function request(Request $request)
    {
        $user = $request->user();
        $tenant = Tenant::findOrFail($request->tenant_id);

        if (!$user->isAdmin()) {
            abort(403);
        }

        $currentActive = Subscription::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->orderBy('created_at', 'desc')
            ->first();

        $durationOptions = collect($this->getDurationOptions())->pluck('value')->toArray();

        $validated = $request->validate([
            'request_type' => ['nullable', Rule::in(['upgrade', 'extend'])],
            'plan' => ['required', Rule::in(['growth'])], // Only growth plan (Paket Lengkap)
            'duration_months' => ['required', 'integer', Rule::in($durationOptions)],
            'payment_method' => ['required', Rule::in(['qris', 'bank'])],
            'payment_proof' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        // Prevent duplicate pending requests
        $existingPending = Subscription::where('tenant_id', $tenant->id)
            ->where('status', 'pending')
            ->where('metadata->requested_by_user', true)
            ->first();

        if ($existingPending) {
            return redirect()->back()->with('error', 'Masih ada pengajuan subscription yang sedang diproses. Harap tunggu konfirmasi admin.');
        }

        $plan = $validated['plan'];
        $duration = (int) $validated['duration_months'];
        $monthlyPrice = 20000; // Fixed price like checkout
        $price = $this->calculatePrice($monthlyPrice, $duration);

        $startsAt = Carbon::now();

        if (($validated['request_type'] ?? null) === 'extend' && $currentActive && $currentActive->ends_at) {
            $startsAt = Carbon::parse($currentActive->ends_at)->greaterThan($startsAt)
                ? Carbon::parse($currentActive->ends_at)
                : $startsAt;
        }

        $endsAt = (clone $startsAt)->addMonths($duration);
        
        // Handle payment proof upload
        $paymentProofPath = null;
        if ($request->hasFile('payment_proof')) {
            $paymentProofPath = $request->file('payment_proof')->store(
                "subscriptions/{$tenant->id}/payment-proofs",
                'public'
            );
        }

        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan' => $plan,
            'duration_months' => $duration,
            'price' => $price,
            'status' => 'pending',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'payment_provider' => $validated['payment_method'] === 'qris' ? 'qris' : 'manual',
            'payment_reference' => null,
            'payment_proof' => $paymentProofPath,
            'metadata' => [
                'requested_by_user' => true,
                'requested_by_user_id' => $user->id,
                'requested_at' => Carbon::now()->toIso8601String(),
                'request_type' => $validated['request_type'] ?? 'new',
                'requested_notes' => $validated['notes'] ?? null,
                'current_subscription_id' => $currentActive?->id,
                'payment_method' => $validated['payment_method'],
            ],
        ]);

        return redirect()->route('subscriptions.index')->with('success', 'Permintaan subscription berhasil dikirim. Admin akan menghubungi Anda untuk konfirmasi pembayaran.');
    }

    /**
     * Plan definitions - Same as checkout page (single plan with 20rb/month)
     */
    protected function getPlanDefinitions(): array
    {
        return [
            'growth' => [
                'slug' => 'growth',
                'name' => 'Paket Lengkap',
                'monthly_price' => 20000,
                'max_whatsapp_accounts' => 5,
                'description' => 'Paket lengkap dengan semua fitur yang tersedia.',
                'features' => [
                    '5 WhatsApp Numbers',
                    'All Basic Features',
                    'Priority Support',
                ],
            ],
        ];
    }

    /**
     * Duration options - Same as checkout page
     */
    protected function getDurationOptions(): array
    {
        return [
            ['value' => 1, 'label' => '1 Bulan', 'discount' => 0],
            ['value' => 3, 'label' => '3 Bulan', 'discount' => 5],
            ['value' => 6, 'label' => '6 Bulan', 'discount' => 10],
            ['value' => 12, 'label' => '12 Bulan', 'discount' => 15],
        ];
    }

    /**
     * Calculate price based on duration (apply discounts like checkout page)
     */
    protected function calculatePrice(int $monthlyPrice, int $durationMonths): float
    {
        $subtotal = $monthlyPrice * $durationMonths;
        
        // Apply discount based on duration (same as checkout)
        $discountPercent = 0;
        if ($durationMonths === 3) {
            $discountPercent = 5; // 5% discount
        } elseif ($durationMonths === 6) {
            $discountPercent = 10; // 10% discount
        } elseif ($durationMonths === 12) {
            $discountPercent = 15; // 15% discount
        }
        
        $discount = ($subtotal * $discountPercent) / 100;
        $total = $subtotal - $discount;
        
        return round($total, 2);
    }
}
