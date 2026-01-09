<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BankManagementController extends Controller
{
    /**
     * Display a listing of banks
     */
    public function index(Request $request): Response
    {
        $query = Bank::query();

        // Search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('account_number', 'like', "%{$search}%")
                    ->orWhere('account_name', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active === '1');
        }

        $banks = $query->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString()
            ->through(function ($bank) {
                return [
                    'id' => $bank->id,
                    'name' => $bank->name,
                    'account_number' => $bank->account_number,
                    'account_name' => $bank->account_name,
                    'description' => $bank->description,
                    'is_active' => $bank->is_active,
                    'created_at' => $bank->created_at->format('Y-m-d H:i:s'),
                ];
            });

        return Inertia::render('SuperAdmin/Banks/Index', [
            'banks' => $banks,
            'filters' => $request->only(['search', 'is_active']),
        ]);
    }

    /**
     * Store a newly created bank
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'account_number' => 'required|string|max:255',
            'account_name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        Bank::create([
            'name' => $validated['name'],
            'account_number' => $validated['account_number'],
            'account_name' => $validated['account_name'],
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return redirect()->route('superadmin.banks.index')
            ->with('success', 'Bank berhasil ditambahkan');
    }

    /**
     * Update the specified bank
     */
    public function update(Request $request, Bank $bank)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'account_number' => 'required|string|max:255',
            'account_name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $bank->update([
            'name' => $validated['name'],
            'account_number' => $validated['account_number'],
            'account_name' => $validated['account_name'],
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return redirect()->route('superadmin.banks.index')
            ->with('success', 'Bank berhasil diperbarui');
    }

    /**
     * Remove the specified bank
     */
    public function destroy(Bank $bank)
    {
        $bank->delete();

        return redirect()->route('superadmin.banks.index')
            ->with('success', 'Bank berhasil dihapus');
    }
}
