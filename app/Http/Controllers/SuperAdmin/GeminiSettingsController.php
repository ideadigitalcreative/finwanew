<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\GeminiConfigService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GeminiSettingsController extends Controller
{
    public function index(GeminiConfigService $geminiConfig): Response
    {
        return Inertia::render('SuperAdmin/GeminiSettings/Index', [
            'settings' => $geminiConfig->getAdminDisplayPayload(),
        ]);
    }

    public function update(Request $request, GeminiConfigService $geminiConfig): RedirectResponse
    {
        $validated = $request->validate([
            'model' => 'required|string|max:255',
            'base_url' => 'nullable|string|max:2048',
            'api_keys_text' => 'nullable|string|max:65535',
        ]);

        $payload = [
            'model' => trim($validated['model']),
            'base_url' => isset($validated['base_url']) ? trim((string) $validated['base_url']) : '',
            'api_keys' => [],
        ];

        $replaceKeys = $request->boolean('replace_api_keys');
        $text = isset($validated['api_keys_text']) ? trim((string) $validated['api_keys_text']) : '';

        if ($replaceKeys) {
            if ($text === '') {
                return redirect()->back()->withErrors([
                    'api_keys_text' => 'Isi daftar API key atau batalkan penggantian.',
                ]);
            }
            $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $payload['api_keys'][] = $line;
                }
            }
            if ($payload['api_keys'] === []) {
                return redirect()->back()->withErrors([
                    'api_keys_text' => 'Minimal satu API key valid diperlukan.',
                ]);
            }
        } else {
            $payload['api_keys'] = $geminiConfig->getMergedConfig()['api_keys'];
        }

        AppSetting::query()->updateOrCreate(
            ['key' => GeminiConfigService::SETTING_KEY],
            ['value' => $payload],
        );

        return redirect()->route('superadmin.gemini-settings.index')
            ->with('success', 'Pengaturan Gemini berhasil disimpan.');
    }
}
