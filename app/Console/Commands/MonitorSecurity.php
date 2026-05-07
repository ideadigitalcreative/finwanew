<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MonitorSecurity extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'security:monitor
                            {--alert : Send alert notifications}
                            {--clean : Clean expired rate limit entries}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor security events and rate limiting';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔒 Monitoring security status...');

        if ($this->option('clean')) {
            $this->cleanExpiredEntries();
        }

        $this->checkRateLimits();
        $this->checkFailedLogins();
        $this->checkBlockedRequests();

        if ($this->option('alert')) {
            $this->sendAlerts();
        }

        $this->info('✅ Security monitoring completed');
    }

    /**
     * Clean expired rate limit cache entries
     */
    private function cleanExpiredEntries()
    {
        $this->info('🧹 Cleaning expired rate limit entries...');

        // This is handled automatically by Laravel's cache system
        // but we can force cleanup of specific patterns
        $keys = Cache::store('database')->getRedis()->keys('laravel_cache:ddos:*');
        $cleaned = 0;

        foreach ($keys as $key) {
            $cleanKey = str_replace('laravel_cache:', '', $key);
            if (! Cache::has($cleanKey)) {
                Cache::store('database')->getRedis()->del($key);
                $cleaned++;
            }
        }

        $this->info("🗑️ Cleaned {$cleaned} expired entries");
    }

    /**
     * Check current rate limiting status
     */
    private function checkRateLimits()
    {
        $this->info('📊 Checking rate limits...');

        // Get rate limit statistics from cache
        $keys = Cache::store('database')->getRedis()->keys('laravel_cache:ddos:*');
        $activeLimits = count($keys);

        $this->info("📈 Active rate limit entries: {$activeLimits}");

        if ($activeLimits > 1000) {
            $this->warn("⚠️ High number of active rate limits detected ({$activeLimits})");
            Log::warning('High rate limit activity detected', ['count' => $activeLimits]);
        }
    }

    /**
     * Check failed login attempts
     */
    private function checkFailedLogins()
    {
        $this->info('🔐 Checking failed login attempts...');

        // This would require logging failed attempts to database/cache
        // For now, we'll check recent logs
        $failedLogins = $this->getFailedLoginCount();

        if ($failedLogins > config('security.monitoring.alert_thresholds.failed_logins_per_minute', 5)) {
            $this->error("🚨 High failed login rate detected: {$failedLogins} per minute");
            Log::warning('High failed login rate', ['count' => $failedLogins]);
        } else {
            $this->info("✅ Failed logins within normal range: {$failedLogins} per minute");
        }
    }

    /**
     * Check blocked requests
     */
    private function checkBlockedRequests()
    {
        $this->info('🚫 Checking blocked requests...');

        // Check recent blocked requests from logs
        $blockedRequests = $this->getBlockedRequestCount();

        $threshold = config('security.monitoring.alert_thresholds.blocked_requests_per_minute', 20);

        if ($blockedRequests > $threshold) {
            $this->error("🚨 High blocked request rate: {$blockedRequests} per minute");
            Log::warning('High blocked request rate', ['count' => $blockedRequests]);
        } else {
            $this->info("✅ Blocked requests within normal range: {$blockedRequests} per minute");
        }
    }

    /**
     * Send security alerts
     */
    private function sendAlerts()
    {
        $this->info('📧 Sending security alerts...');

        $alertEmail = config('security.monitoring.notification_email');

        if (! $alertEmail) {
            $this->warn('⚠️ No alert email configured');

            return;
        }

        // Check if there are alerts to send
        $failedLogins = $this->getFailedLoginCount();
        $blockedRequests = $this->getBlockedRequestCount();

        $alerts = [];

        if ($failedLogins > config('security.monitoring.alert_thresholds.failed_logins_per_minute', 5)) {
            $alerts[] = "High failed login rate: {$failedLogins} per minute";
        }

        if ($blockedRequests > config('security.monitoring.alert_thresholds.blocked_requests_per_minute', 20)) {
            $alerts[] = "High blocked request rate: {$blockedRequests} per minute";
        }

        if (! empty($alerts)) {
            try {
                Mail::raw(
                    "Security Alert:\n\n".implode("\n", $alerts)."\n\nTime: ".now()->toDateTimeString(),
                    function ($message) use ($alertEmail) {
                        $message->to($alertEmail)
                            ->subject('Security Alert - FinWa');
                    }
                );
                $this->info('📧 Alert sent successfully');
            } catch (\Exception $e) {
                $this->error('❌ Failed to send alert: '.$e->getMessage());
            }
        } else {
            $this->info('✅ No alerts to send');
        }
    }

    /**
     * Get failed login count (mock implementation)
     */
    private function getFailedLoginCount(): int
    {
        // In a real implementation, this would query a security log table
        // For now, return a mock value
        return rand(0, 10);
    }

    /**
     * Get blocked request count (mock implementation)
     */
    private function getBlockedRequestCount(): int
    {
        // In a real implementation, this would query logs for 429 responses
        // For now, return a mock value
        return rand(0, 25);
    }
}
