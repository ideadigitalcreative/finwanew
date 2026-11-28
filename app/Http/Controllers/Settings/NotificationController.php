<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengaturan notifikasi push per user (aktif/nonaktif, sembunyikan nominal).
 */
class NotificationController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Notifications', [
            'pushEnabled' => (bool) ($user->push_enabled ?? true),
            'pushHideAmount' => (bool) ($user->push_hide_amount ?? false),
            'devices' => $user->deviceTokens()
                ->orderByDesc('last_seen_at')
                ->get(['id', 'platform', 'device_name', 'last_seen_at'])
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'name' => $d->device_name ?: ucfirst($d->platform),
                    'last_seen' => $d->last_seen_at?->diffForHumans(),
                ]),
            'serverEnabled' => (bool) config('finwa_push.enabled'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'push_enabled' => ['required', 'boolean'],
            'push_hide_amount' => ['required', 'boolean'],
        ]);

        $request->user()->forceFill($data)->save();

        return back()->with('success', 'Pengaturan notifikasi disimpan');
    }

    public function destroyDevice(Request $request, int $id): RedirectResponse
    {
        $request->user()->deviceTokens()->whereKey($id)->delete();

        return back();
    }
}
