<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceLinkToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WhatsAppLinkController extends Controller
{
    /**
     * Generate a new device link token for the authenticated user
     */
    public function generateToken(Request $request)
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $linkToken = DeviceLinkToken::generateForUser($user->id);

        // Get shared channel number
        $sharedChannel = app(\App\Services\WhatsAppUserMappingService::class)->getSharedWhatsAppChannel();
        $sharedNumber = $sharedChannel ? $sharedChannel->channel_account : '';

        return response()->json([
            'token' => $linkToken->token,
            'expires_at' => $linkToken->expires_at,
            'whatsapp_number' => $user->whatsapp_number,
            'whatsapp_link' => "https://wa.me/{$sharedNumber}?text=".urlencode('LINK '.$linkToken->token),
        ]);
    }
}
