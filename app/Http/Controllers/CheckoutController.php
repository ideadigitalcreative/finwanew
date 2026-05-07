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
        $plan = $request->query('plan', 'lite'); // Default: lite plan

        // Get plan details
        if ($plan === 'free') {
            $planDetails = [
                'name' => 'Paket Gratis',
                'monthly_price' => 0,
            ];
            $durations = [['months' => 1, 'label' => 'Gratis Selamanya', 'discount' => 0]];
            $banks = [];
        } elseif ($plan === 'pro') {
            $planDetails = [
                'name' => 'Paket PRO',
                'monthly_price' => 45000,
            ];
            $durations = [
                ['months' => 1, 'label' => '1 Bulan', 'discount' => 0],
                ['months' => 3, 'label' => '3 Bulan', 'discount' => 5],
                ['months' => 6, 'label' => '6 Bulan', 'discount' => 10],
                ['months' => 12, 'label' => '12 Bulan', 'discount' => 15],
            ];
            $banks = $this->getActiveBanks();
        } else {
            // Default: Paket Lite (Growth)
            $planDetails = [
                'name' => 'Paket Lite',
                'monthly_price' => 20000,
            ];
            $durations = [
                ['months' => 1, 'label' => '1 Bulan', 'discount' => 0],
                ['months' => 3, 'label' => '3 Bulan', 'discount' => 5],
                ['months' => 6, 'label' => '6 Bulan', 'discount' => 10],
                ['months' => 12, 'label' => '12 Bulan', 'discount' => 15],
            ];
            $banks = $this->getActiveBanks();
        }

        return Inertia::render('Checkout/Index', [
            'planDetails' => $planDetails,
            'durations' => $durations,
            'banks' => $banks,
            'isFreePlan' => $plan === 'free',
            'planSlug' => $plan,
        ]);
    }

    /**
     * Get active banks for payment
     */
    private function getActiveBanks()
    {
        return Bank::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($bank) => [
                'id' => $bank->id,
                'name' => $bank->name,
                'account_number' => $bank->account_number,
                'account_name' => $bank->account_name,
                'description' => $bank->description,
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
