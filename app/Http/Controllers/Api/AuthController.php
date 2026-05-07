<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Register new user
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role_id' => 2, // Assuming 2 is USER role? Need to check Role constants or seeder
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ], 201);
    }

    /**
     * Login user
     */
    public function login(Request $request)
    {
        if (! Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'Invalid login details',
            ], 401);
        }

        $user = User::where('email', $request['email'])->firstOrFail();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    /**
     * Google Login (Exchange ID Token for Access Token)
     */
    public function googleLogin(Request $request)
    {
        try {
            $idToken = $request->input('id_token');

            // Verify ID Token with Google using Google Client
            $clientId = config('services.google.client_id');
            Log::info('Verifying Google Token with Client ID: '.$clientId);

            $client = new \Google_Client(['client_id' => $clientId]);

            try {
                $payload = $client->verifyIdToken($idToken);
            } catch (\Exception $e) {
                Log::error('Google Token Verification Exception: '.$e->getMessage());
                // Try verifying without client_id check to see what's inside
                $clientNoCheck = new \Google_Client;
                $payloadNoCheck = $clientNoCheck->verifyIdToken($idToken);
                if ($payloadNoCheck) {
                    Log::error('Token is valid but Audience mismatch. Token Aud: '.$payloadNoCheck['aud']);
                }
                throw $e;
            }

            if (! $payload) {
                Log::error('Invalid Google ID Token (Payload null)');

                return response()->json(['message' => 'Invalid Google ID Token'], 401);
            }

            Log::info('Google Token Verified. Email: '.$payload['email']);

            $googleId = $payload['sub'];
            $email = $payload['email'];
            $name = $payload['name'];
            $avatar = $payload['picture'] ?? null;

            // Find or create user
            $user = User::where('email', $email)->first();

            if ($user) {
                // Update Google ID if not set
                if (! $user->google_id) {
                    $user->google_id = $googleId;
                    $user->save();
                }
            } else {
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make(Str::random(16)), // Random password
                    'google_id' => $googleId,
                    'avatar' => $avatar,
                    'email_verified_at' => now(),
                    'role_id' => 2,
                ]);
            }

            // Ensure user has at least one tenant
            if ($user->activeTenants()->count() === 0) {
                $tenant = \App\Models\Tenant::create([
                    'name' => $name."'s Workspace",
                    'slug' => Str::slug($name.' '.Str::random(6)),
                    'is_active' => true,
                    'trial_ends_at' => \Carbon\Carbon::now()->addDays(3),
                ]);

                // Attach User to Tenant
                $user->tenants()->attach($tenant->id, [
                    'role_id' => 1, // Assuming 1 is Owner
                    'is_active' => true,
                    'joined_at' => now(),
                ]);

                // Set default
                $user->tenant_id = $tenant->id;
                $user->save();
            }

            $token = $user->createToken('google_auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Google login successful',
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user->load('activeTenants'),
            ]);

        } catch (\Exception $e) {
            Log::error('Google Login Error: '.$e->getMessage());

            return response()->json(['message' => 'Google login failed', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get User Profile
     */
    public function user(Request $request)
    {
        return response()->json($request->user());
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }
}
