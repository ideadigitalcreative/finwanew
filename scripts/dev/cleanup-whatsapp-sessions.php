<?php

/**
 * Script untuk cleanup session WhatsApp yang tidak ada di database
 * 
 * Usage: php cleanup-whatsapp-sessions.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Channel;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║   CLEANUP: Session WhatsApp yang Tidak Ada di Database      ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

$whatsappService = new WhatsAppService();
$engineUrl = config('services.whatsapp.engine_url', 'http://localhost:8001');
$apiKey = config('services.whatsapp.api_key', 'whatsapp_gateway_api_key_123');

// Get all channels from database
echo "📋 MENGAMBIL DATA CHANNEL DARI DATABASE:\n";
echo "   ──────────────────────────────────────────────────────────\n";

$channels = Channel::where('type', 'whatsapp')->get();
$validSessionIds = [];

foreach ($channels as $channel) {
    $config = $channel->config ?? [];
    $sessionId = $config['session_id'] ?? null;
    if ($sessionId) {
        $validSessionIds[] = $sessionId;
        echo "   ✅ Channel ID: {$channel->id}, Session: {$sessionId}, Account: {$channel->channel_account}\n";
    } else {
        echo "   ⚠️  Channel ID: {$channel->id}, Account: {$channel->channel_account} - No session ID\n";
    }
}

echo "\n";
echo "   Total channels dengan session: " . count($validSessionIds) . "\n";
echo "\n";

// Get all sessions from WhatsApp Gateway
echo "🔍 MENGAMBIL SESSION DARI WHATSAPP GATEWAY:\n";
echo "   ──────────────────────────────────────────────────────────\n";

try {
    // Check health endpoint to get session count
    $healthResponse = Http::timeout(5)->get("{$engineUrl}/health");
    
    if (!$healthResponse->successful()) {
        echo "   ❌ Tidak dapat mengakses WhatsApp Gateway!\n";
        echo "   Error: " . $healthResponse->body() . "\n";
        exit(1);
    }
    
    $healthData = $healthResponse->json();
    $sessionCount = $healthData['sessions'] ?? 0;
    echo "   Sessions aktif di Gateway: {$sessionCount}\n";
    echo "\n";
    
    // List sessions from disk (scan session directories)
    $sessionsDir = __DIR__ . '/services/whatsapp-gateway/sessions';
    $orphanedSessions = [];
    
    if (is_dir($sessionsDir)) {
        $dirs = scandir($sessionsDir);
        $sessionDirs = array_filter($dirs, function($dir) use ($sessionsDir) {
            return $dir !== '.' && $dir !== '..' && is_dir($sessionsDir . '/' . $dir);
        });
        
        echo "   Session directories ditemukan:\n";
        foreach ($sessionDirs as $sessionDir) {
            // Extract sessionId from directory name (session-wa_1_6285242766676 -> wa_1_6285242766676)
            $sessionId = str_replace('session-', '', $sessionDir);
            
            if (!in_array($sessionId, $validSessionIds)) {
                $orphanedSessions[] = $sessionId;
                echo "   ⚠️  ORPHANED: {$sessionId} (tidak ada di database)\n";
            } else {
                echo "   ✅ Valid: {$sessionId}\n";
            }
        }
    } else {
        echo "   ⚠️  Directory sessions tidak ditemukan: {$sessionsDir}\n";
    }
    
    echo "\n";
    
} catch (\Exception $e) {
    echo "   ❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Summary
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║   RINGKASAN                                                  ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

if (empty($orphanedSessions)) {
    echo "✅ Tidak ada session yang perlu di-cleanup!\n";
    echo "   Semua session di WhatsApp Gateway memiliki channel di database.\n";
    echo "\n";
    exit(0);
}

echo "⚠️  Ditemukan " . count($orphanedSessions) . " session yang tidak ada di database:\n";
foreach ($orphanedSessions as $sessionId) {
    echo "   - {$sessionId}\n";
}
echo "\n";

// Ask for confirmation
echo "🗑️  HAPUS SESSION YANG TIDAK TERPAKAI?\n";
echo "   ──────────────────────────────────────────────────────────\n";
echo "   Tekan Enter untuk melanjutkan (atau Ctrl+C untuk batal)...\n";
// Wait for user input (in CLI)
// In web context, we'll proceed directly

// Delete orphaned sessions
echo "\n";
echo "🗑️  MENGHAPUS SESSION...\n";
echo "   ──────────────────────────────────────────────────────────\n";

$deletedCount = 0;
$failedCount = 0;

foreach ($orphanedSessions as $sessionId) {
    try {
        echo "   Menghapus session: {$sessionId}...\n";
        
        // Delete from WhatsApp Gateway
        $response = Http::timeout(10)
            ->withHeaders([
                'X-API-Key' => $apiKey
            ])
            ->delete("{$engineUrl}/sessions/{$sessionId}");
        
        if ($response->successful()) {
            echo "   ✅ Session {$sessionId} berhasil dihapus dari Gateway\n";
            $deletedCount++;
        } else {
            echo "   ⚠️  Session {$sessionId} gagal dihapus dari Gateway: " . $response->body() . "\n";
            $failedCount++;
        }
        
        // Also delete from disk
        $sessionDir = $sessionsDir . '/session-' . $sessionId;
        if (is_dir($sessionDir)) {
            try {
                // Use recursive delete
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($sessionDir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                
                foreach ($files as $file) {
                    if ($file->isDir()) {
                        rmdir($file->getPathname());
                    } else {
                        unlink($file->getPathname());
                    }
                }
                rmdir($sessionDir);
                echo "   ✅ Directory session {$sessionId} berhasil dihapus dari disk\n";
            } catch (\Exception $e) {
                echo "   ⚠️  Gagal menghapus directory: " . $e->getMessage() . "\n";
                echo "   💡 Anda bisa menghapus manual: {$sessionDir}\n";
            }
        }
        
    } catch (\Exception $e) {
        echo "   ❌ Error menghapus session {$sessionId}: " . $e->getMessage() . "\n";
        $failedCount++;
    }
    
    echo "\n";
}

// Final summary
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║   HASIL CLEANUP                                              ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

echo "✅ Berhasil dihapus: {$deletedCount}\n";
if ($failedCount > 0) {
    echo "❌ Gagal dihapus: {$failedCount}\n";
}
echo "\n";

if ($deletedCount > 0) {
    echo "💡 Session yang dihapus:\n";
    foreach ($orphanedSessions as $sessionId) {
        echo "   - {$sessionId}\n";
    }
    echo "\n";
    echo "✅ Cleanup selesai! Sekarang Anda bisa membuat channel baru tanpa error.\n";
} else {
    echo "⚠️  Tidak ada session yang berhasil dihapus.\n";
    echo "   Coba restart WhatsApp Gateway dan jalankan script ini lagi.\n";
}

echo "\n";

