<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutController extends Controller
{
    /**
     * Show checkout page
     */
    public function show(Request $request): Response
    {
        $plan = $request->query('plan', 'paid'); // Default: paid plan
        
        // Check if free plan
        if ($plan === 'free') {
            $planDetails = [
                'name' => 'Paket Ujicoba',
                'monthly_price' => 0,
            ];
            
            // Free plan: only 3 days trial
            $durations = [
                ['months' => 1, 'label' => '3 Hari', 'discount' => 0],
            ];
            
            // No banks needed for free plan
            $banks = [];
        } else {
            // Paid plan: Paket Lengkap (20rb/bulan)
            $planDetails = [
                'name' => 'Paket Lengkap',
                'monthly_price' => 20000,
            ];
            
            // Duration options with discounts
            $durations = [
                ['months' => 1, 'label' => '1 Bulan', 'discount' => 0],
                ['months' => 3, 'label' => '3 Bulan', 'discount' => 5], // 5% discount
                ['months' => 6, 'label' => '6 Bulan', 'discount' => 10], // 10% discount
                ['months' => 12, 'label' => '12 Bulan', 'discount' => 15], // 15% discount
            ];

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
        }

        return Inertia::render('Checkout/Index', [
            'planDetails' => $planDetails,
            'durations' => $durations,
            'banks' => $banks,
            'isFreePlan' => $plan === 'free',
        ]);
    }

    /**
     * Process checkout with registration
     */
    public function process(Request $request)
    {
        // This will be handled by Fortify registration + custom checkout logic
        // For now, just redirect to register with checkout data in session
        $request->session()->put('checkout', [
            'plan' => 'growth',
            'duration_months' => $request->input('duration_months', 1),
            'price' => $request->input('price'),
        ]);

        return redirect()->route('register');
    }
}

