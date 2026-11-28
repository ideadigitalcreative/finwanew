<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

class TelegramSetupWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:telegram-setup-webhook {--url= : Webhook URL (overrides config)} {--secret= : Webhook secret token (overrides config)} {--drop-pending : Buang semua update tertunda/basi di antrean Telegram}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Setup Telegram webhook integration';

    /**
     * Execute the console command.
     */
    public function handle(TelegramService $service): int
    {
        $url = $this->option('url') ?: config('services.telegram.webhook_url');
        $secret = $this->option('secret') ?: config('services.telegram.webhook_secret');

        if (! $url) {
            $this->error('Webhook URL not provided. Use --url option or set TELEGRAM_WEBHOOK_URL in .env');
            return self::FAILURE;
        }

        $dropPending = (bool) $this->option('drop-pending');

        $this->info('Setting up Telegram webhook...');
        $this->info("URL: {$url}");
        $this->info("Secret: " . ($secret ? str_repeat('*', strlen($secret)) : 'not set'));
        $this->info("Drop pending updates: " . ($dropPending ? 'YES' : 'no'));

        $result = $service->setWebhook($url, $secret, 100, $dropPending);

        if ($result['success'] ?? false) {
            $this->info('Webhook setup successful:');
            $this->info(json_encode($result, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->error('Webhook setup failed:');
        $this->error(json_encode($result, JSON_PRETTY_PRINT));
        return self::FAILURE;
    }
}
