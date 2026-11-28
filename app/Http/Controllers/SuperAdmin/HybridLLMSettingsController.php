<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\AI\OpenAIChatClient;
use App\Services\HybridLLMConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HybridLLMSettingsController extends Controller
{
    public function index(HybridLLMConfigService $hybridConfig): Response
    {
        return Inertia::render('SuperAdmin/HybridLLMSettings/Index', [
            'settings' => $hybridConfig->getAdminDisplayPayload(),
        ]);
    }

    public function update(Request $request, HybridLLMConfigService $hybridConfig): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => 'nullable|boolean',
            'base_url' => 'nullable|string|max:2048',
            'model' => 'required|string|max:255',
            'api_key' => 'nullable|string|max:2048',
            'timeout' => 'nullable|integer|min:1|max:60',
        ], [
            'base_url.required' => 'Request URL wajib diisi.',
            'model.required' => 'Model ID wajib diisi.',
        ]);

        $baseUrl = isset($validated['base_url']) ? trim((string) $validated['base_url']) : '';

        if ($baseUrl !== '' && !HybridLLMConfigService::isValidBaseUrl($baseUrl)) {
            return redirect()->back()->withErrors([
                'base_url' => 'Request URL harus HTTPS (http hanya diizinkan untuk localhost atau alamat IP).',
            ]);
        }

        $hybridConfig->save([
            'enabled' => $request->boolean('enabled'),
            'base_url' => $baseUrl,
            'model' => trim((string) $validated['model']),
            'api_key' => isset($validated['api_key']) ? trim((string) $validated['api_key']) : null,
            'timeout' => isset($validated['timeout']) ? (int) $validated['timeout'] : null,
        ]);

        return redirect()->route('superadmin.hybrid-llm-settings.index')
            ->with('success', 'Pengaturan Hybrid LLM berhasil disimpan.');
    }

    /**
     * Tes koneksi ke endpoint LLM tersimpan dengan prompt singkat.
     */
    public function test(HybridLLMConfigService $hybridConfig, OpenAIChatClient $client): JsonResponse
    {
        if (! $hybridConfig->isReady()) {
            return response()->json([
                'ok' => false,
                'message' => 'Konfigurasi belum lengkap atau belum diaktifkan.',
            ]);
        }

        $cfg = $hybridConfig->getMergedConfig();
        $startedAt = microtime(true);

        $reply = $client->complete(
            [['role' => 'user', 'content' => 'Balas dengan kata "OK" saja.']],
            maxTokens: 20,
            temperature: 0.0,
        );

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($reply === null) {
            return response()->json([
                'ok' => false,
                // Tampilkan penyebab sebenarnya (HTTP status/isi respons) — key tidak pernah ikut
                'message' => 'Koneksi gagal: '.mb_substr((string) (OpenAIChatClient::$lastError ?? 'tidak diketahui'), 0, 300),
                'latency_ms' => $latencyMs,
            ]);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Koneksi berhasil.',
            'model' => $cfg['model'],
            'reply' => mb_substr(trim($reply), 0, 200),
            'latency_ms' => $latencyMs,
        ]);
    }
}
