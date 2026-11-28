<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CheckGatewayStatus extends Command
{
    protected $name = 'whatsapp:check-gateway';
    protected $description = 'Check WhatsApp Gateway connection status';

    public function handle()
    {
        $engineUrl = config('services.whatsapp.engine_url', env('WHATSAPP_ENGINE_URL'));
        $apiKey = config('services.whatsapp.engine_api_key', env('WHATSAPP_ENGINE_API_KEY'));

        $this->info("Engine URL: " . $engineUrl);
        $this->info("API Key: " . $apiKey);
        $this->info("Checking: " . $engineUrl . '/health');

        try {
            $response = Http::timeout(10)
                ->withHeaders(['X-API-Key' => $apiKey])
                ->get($engineUrl . '/health');

            $this->info("HTTP Code: " . $response->status());
            $this->info("Body: " . substr($response->body(), 0, 500));
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }
    }
}