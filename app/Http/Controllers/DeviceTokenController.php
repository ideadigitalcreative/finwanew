<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use App\Services\Push\TransactionPushNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registrasi token FCM dari aplikasi Android (WebView).
 *
 * Dipanggil oleh resources/js/nativePush.ts setelah aplikasi Android
 * menyerahkan token lewat jembatan JS (lihat docs/ANDROID_PUSH_FCM.md).
 * Tersedia di web (sesi login WebView) & API (Sanctum).
 */
class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:512'],
            'platform' => ['nullable', 'string', 'in:android,ios,web'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $device = TransactionPushNotifier::registerToken(
            $request->user(),
            $data['token'],
            $data['platform'] ?? 'android',
            $data['device_name'] ?? null,
        );

        return response()->json(['success' => true, 'id' => $device->id]);
    }

    /** Dipanggil saat logout di aplikasi agar HP tidak menerima notifikasi akun lama. */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);

        DeviceToken::where('user_id', $request->user()->id)->where('token', $data['token'])->delete();

        return response()->json(['success' => true]);
    }
}
