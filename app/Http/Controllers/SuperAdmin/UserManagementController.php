<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserManagementController extends Controller
{
    /**
     * Display a listing of users
     */
    public function index(Request $request): Response
    {
        $query = User::query()->with(['tenant', 'role']);

        // Search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('whatsapp_number', 'like', "%{$search}%");
            });
        }

        // Filter by tenant
        if ($request->has('tenant_id') && $request->tenant_id) {
            $query->where('tenant_id', $request->tenant_id);
        }

        // Filter by super admin
        if ($request->has('is_super_admin')) {
            $query->where('is_super_admin', $request->is_super_admin === '1');
        }

        $users = $query->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString()
            ->through(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'whatsapp_number' => $user->whatsapp_number,
                    'is_super_admin' => $user->is_super_admin,
                    'tenant' => $user->tenant ? [
                        'id' => $user->tenant->id,
                        'name' => $user->tenant->name,
                    ] : null,
                    'role' => $user->role ? [
                        'id' => $user->role->id,
                        'name' => $user->role->name,
                    ] : null,
                    'created_at' => $user->created_at->format('Y-m-d H:i:s'),
                ];
            });

        $tenants = Tenant::select('id', 'name')->orderBy('name')->get();

        return Inertia::render('SuperAdmin/Users/Index', [
            'users' => $users,
            'tenants' => $tenants,
            'filters' => $request->only(['search', 'tenant_id', 'is_super_admin']),
        ]);
    }

    /**
     * Store a newly created user
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'whatsapp_number' => 'nullable|string|max:20',
            'tenant_id' => 'nullable|exists:tenants,id',
            'is_super_admin' => 'boolean',
        ]);

        // If tenant_id is not provided and user is not super admin, create a new tenant
        $tenantId = $validated['tenant_id'] ?? null;

        if (! $tenantId && ! ($validated['is_super_admin'] ?? false)) {
            // Create new tenant with unique slug
            $baseSlug = \Illuminate\Support\Str::slug($validated['name'].'-org');
            $slug = $baseSlug;
            $counter = 1;

            // Ensure slug is unique
            while (Tenant::where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$counter;
                $counter++;
            }

            $tenant = Tenant::create([
                'name' => $validated['name']."'s Organization",
                'slug' => $slug,
                'is_active' => true,
            ]);

            $tenantId = $tenant->id;
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'whatsapp_number' => $validated['whatsapp_number'] ?? null,
            'tenant_id' => $tenantId,
            'is_super_admin' => $validated['is_super_admin'] ?? false,
        ]);

        return redirect()->route('superadmin.users.index')
            ->with('success', 'User berhasil dibuat');
    }

    /**
     * Update the specified user
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => 'nullable|string|min:8|confirmed',
            'whatsapp_number' => 'nullable|string|max:20',
            'tenant_id' => 'nullable|exists:tenants,id',
            'is_super_admin' => 'boolean',
        ]);

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'whatsapp_number' => $validated['whatsapp_number'] ?? null,
            'tenant_id' => $validated['tenant_id'] ?? null,
            'is_super_admin' => $validated['is_super_admin'] ?? false,
        ]);

        if (! empty($validated['password'])) {
            $user->update([
                'password' => Hash::make($validated['password']),
            ]);
        }

        return redirect()->route('superadmin.users.index')
            ->with('success', 'User berhasil diperbarui');
    }

    /**
     * Remove the specified user
     */
    public function destroy(User $user)
    {
        // Prevent deleting super admin
        if ($user->is_super_admin) {
            return redirect()->route('superadmin.users.index')
                ->with('error', 'Tidak dapat menghapus super admin');
        }

        $user->delete();

        return redirect()->route('superadmin.users.index')
            ->with('success', 'User berhasil dihapus');
    }
}
