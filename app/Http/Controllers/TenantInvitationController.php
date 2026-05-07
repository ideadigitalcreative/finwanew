<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Models\UserTenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class TenantInvitationController extends Controller
{
    /**
     * List invitations for current tenant
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $tenant = Tenant::findOrFail($request->tenant_id);

        // Only admin/owner can see invitations
        if (! $user->isAdmin()) {
            abort(403);
        }

        $invitations = TenantInvitation::where('tenant_id', $tenant->id)
            ->with(['role', 'inviter'])
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Tenants/Invitations', [
            'invitations' => $invitations,
            'roles' => Role::where('tenant_id', $tenant->id)->get(),
        ]);
    }

    /**
     * Create new invitation
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $tenant = Tenant::findOrFail($request->tenant_id);

        // Only admin/owner can invite
        if (! $user->isAdmin()) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
            'role_id' => 'required|exists:roles,id',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator);
        }

        // Check if user already in tenant
        $existingUser = User::where('email', $request->email)->first();
        if ($existingUser && $existingUser->belongsToTenant($tenant->id)) {
            return redirect()->back()->withErrors(['email' => 'User already belongs to this tenant']);
        }

        // Check if pending invitation exists
        $existingInvitation = TenantInvitation::where('tenant_id', $tenant->id)
            ->where('email', $request->email)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($existingInvitation) {
            return redirect()->back()->withErrors(['email' => 'Invitation already sent to this email']);
        }

        // Create invitation
        $invitation = TenantInvitation::create([
            'tenant_id' => $tenant->id,
            'email' => $request->email,
            'role_id' => $request->role_id,
            'token' => TenantInvitation::generateToken(),
            'invited_by' => $user->id,
            'expires_at' => Carbon::now()->addDays(7), // 7 days expiry
        ]);

        // Send email invitation (optional - bisa diimplementasi nanti)
        // Mail::to($request->email)->send(new TenantInvitationMail($invitation));

        return redirect()->back()->with('success', 'Invitation sent successfully');
    }

    /**
     * Accept invitation
     */
    public function accept(Request $request, string $token)
    {
        $invitation = TenantInvitation::where('token', $token)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();

        $user = $request->user();

        // Check if user email matches
        if ($user->email !== $invitation->email) {
            abort(403, 'Invitation email does not match your account');
        }

        // Check if already in tenant
        if ($user->belongsToTenant($invitation->tenant_id)) {
            return redirect('/dashboard')->with('error', 'You already belong to this tenant');
        }

        // Add user to tenant
        UserTenant::create([
            'user_id' => $user->id,
            'tenant_id' => $invitation->tenant_id,
            'role_id' => $invitation->role_id,
            'is_active' => true,
        ]);

        // Mark invitation as accepted
        $invitation->update([
            'accepted_at' => now(),
        ]);

        // Update user default tenant if needed
        if (! $user->tenant_id) {
            $user->update([
                'tenant_id' => $invitation->tenant_id,
                'role_id' => $invitation->role_id,
            ]);
        }

        // Switch to this tenant
        session(['current_tenant_id' => $invitation->tenant_id]);

        return redirect('/dashboard')->with('success', 'Successfully joined tenant');
    }

    /**
     * Delete invitation
     */
    public function destroy(Request $request, TenantInvitation $invitation)
    {
        $user = $request->user();

        // Only admin/owner can delete
        if (! $user->isAdmin()) {
            abort(403);
        }

        // Verify invitation belongs to user's tenant
        if ($invitation->tenant_id !== $request->tenant_id) {
            abort(403);
        }

        $invitation->delete();

        return redirect()->back()->with('success', 'Invitation deleted');
    }
}
