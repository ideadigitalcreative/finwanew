# ROLLBACK STRATEGY: TELEGRAM INTEGRATION

---

## 🎯 OVERVIEW

Dokumen ini menjelaskan langkah-langkah rollback jika integrasi Telegram mengalami masalah kritis di production.

---

## 🚨 CRITICAL FAILURE SCENARIOS

| Scenario | Impact | Rollback Trigger |
|----------|--------|------------------|
| Webhook error rate > 50% dalam 5 menit | Inbound message gagal | Alert monitoring |
| Bot token revoked/leaked | Security breach | Security team |
| Database migration failed | Data inconsistency | Deploy failure |
| High latency (> 2s p95) | User experience | Pulse dashboard |

---

## 🛠 ROLLBACK PROCEDURES

### 1. Webhook Disable (5 detik)

**Command:**
```bash
php artisan telegram:webhook:disable
```

**Manual via Telegram Bot API:**
```bash
curl -X POST "https://api.telegram.org/bot{TOKEN}/deleteWebhook?drop_pending_updates=true"
```

**Expected:**
```json
{
  "ok": true,
  "result": true,
  "description": "Webhook was deleted"
}
```

---

### 2. Disable Telegram Channel di Database

```sql
-- Disable semua Telegram channel
UPDATE channels SET is_active = 0 WHERE type = 'telegram';

-- Disable Telegram notification driver
-- Update config/app.php (lihat section 4)
```

---

### 3. Rollback Database Migration

```bash
# Rollback migration user_telegram_mappings
php artisan migrate:rollback --step=1

# Rollback channel migration jika ada
php artisan migrate:rollback --step=1
```

**Rollback specific migration:**
```php
// Jika migration tidak bisa rollback otomatis
Schema::dropIfExists('user_telegram_mappings');
```

---

### 4. Disable Telegram Service di App

**File: `config/app.php`**

```php
// Comment out TelegramServiceProvider
// App\Providers\TelegramServiceProvider::class,

// Jika menggunakan ChannelServiceResolver
// Update MessageReplyService untuk fallback ke WhatsApp saja
```

**File: `routes/api.php`**

```php
// Comment out Telegram webhook routes
// Route::prefix('webhooks/telegram')->group(function () {
//     Route::post('/message', [\App\Http\Controllers\Webhook\TelegramWebhookController::class, 'handleMessage']);
// });

// Comment out health check
// Route::get('/health/telegram', [TelegramHealthController::class, 'check']);
```

---

### 5. Revert Code Changes

**Files to revert:**
```bash
# Backup current changes
git diff > telegram_changes.diff

# Revert specific files
git checkout HEAD -- app/Services/TelegramService.php
git checkout HEAD -- app/Http/Controllers/Webhook/TelegramWebhookController.php
git checkout HEAD -- app/Http/Controllers/Api/TelegramHealthController.php
git checkout HEAD -- app/Providers/TelegramMetricsServiceProvider.php
git checkout HEAD -- app/Records/TelegramMessages.php

# Or if using git tag
git tag -d v2025.11.06-telegram
git checkout v2025.11.05-stable
composer install --no-dev
```

---

### 6. Clear Cache & Config

```bash
# Clear all Laravel caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Clear Telegram throttle cache
php artisan cache:prune-stale-tags
```

---

### 7. Verify Rollback

```bash
# Test webhook endpoint returns 404
curl -s -o /dev/null -w "%{http_code}" \
  -H "X-Telegram-Bot-Api-Secret-Token: {SECRET}" \
  -X POST -H "Content-Type: application/json" \
  -d '{"update_id":123,"message":{"chat":{"id":123}}}' \
  https://api.finwa.id/api/webhooks/telegram/message
# Expected: 404

# Test health check returns 404
curl -s -o /dev/null -w "%{http_code}" \
  https://api.finwa.id/api/health/telegram
# Expected: 404

# Test WhatsApp webhook still works
curl -s -o /dev/null -w "%{http_code}" \
  -H "X-WHATSAPP-KEY: {API_KEY}" \
  -X POST -H "Content-Type: application/json" \
  -d '{"messages":[{"from":"6281234567890","type":"text"}]}' \
  https://api.finwa.id/api/webhooks/whatsapp/message
# Expected: 200
```

---

## 🔄 POST-ROLLBACK ACTIONS

### 1. Notify Stakeholders

```
Subject: [CRITICAL] Telegram Integration Rolled Back

Body:
- Timestamp: 2025-11-06T13:00:00Z
- Reason: Webhook error rate > 50%
- Duration: 15 menit
- Impact: Telegram inbound messages lost during outage
- Action: Rolled back to v2025.11.05-stable

Next steps:
- Investigate root cause
- Plan re-rollout with fixes
- Implement better monitoring
```

### 2. Data Recovery

```php
// Recover lost Telegram messages from webhook logs
// Jika ada webhook retry mechanism
$recovered = \App\Models\TelegramWebhookLog::where('processed', false)
    ->where('created_at', '>=', now()->subHours(1))
    ->get();

foreach ($recovered as $log) {
    dispatch(new ProcessTelegramWebhook($log->payload));
}
```

### 3. Monitor WhatsApp Load

```sql
-- Check WhatsApp webhook traffic spike
SELECT 
    DATE_FORMAT(created_at, '%Y-%m-%d %H:00') as hour,
    COUNT(*) as messages,
    AVG(TIMESTAMPDIFF(MICROSECOND, created_at, updated_at))/1000 as avg_latency_ms
FROM messages 
WHERE channel = 'whatsapp' 
  AND created_at >= NOW() - INTERVAL 24 HOUR
GROUP BY hour;
```

### 4. Root Cause Analysis

**Timeline:**
```
12:55 - Deploy Telegram integration v2025.11.06
12:58 - Webhook error rate starts increasing
13:00 - Alert triggered (error rate > 50%)
13:01 - Rollback initiated
13:06 - Rollback completed
13:10 - WhatsApp traffic normalized
```

**Hypothesis:**
- Bot token config error (wrong environment variable)
- Webhook secret mismatch between Telegram and Laravel
- Database connection pool exhausted

**Prevention:**
- Add pre-deploy health check: `php artisan telegram:health:check`
- Add canary deployment: test dengan 1% traffic dulu
- Better monitoring: Telegram error rate, latency, queue depth

---

## 📊 ROLLBACK CHECKLIST

```bash
# Pre-rollback
[ ] Backup database (mysqldump finwa_production > backup_$(date +%Y%m%d).sql)
[ ] Note current commit hash (git rev-parse HEAD)
[ ] Notify team in Slack/Teams
[ ] Pause any scheduled Telegram jobs (queue:work)

# Rollback execution
[ ] Disable webhook (curl deleteWebhook)
[ ] Mark channel inactive (SQL update)
[ ] Revert code changes (git checkout)
[ ] Clear caches (artisan clear-commands)
[ ] Restart workers (supervisorctl restart all)

# Post-rollback verification
[ ] WhatsApp webhook responds 200
[ ] Telegram webhook responds 404
[ ] Health check returns 404
[ ] Database migration rolled back
[ ] No error spikes in Sentry
[ ] Queue depth normal
```

---

## 🔄 RE-ROLLOUT PREPAREDNESS

### 1. Fix Root Cause

```
Jika rollback karena webhook error:
- [ ] Test webhook dengan ngrok di local
- [ ] Verify secret token consistency
- [ ] Add more logging di webhook controller
- [ ] Add retry mechanism dengan exponential backoff
```

### 2. Better Testing

```bash
# Add to CI/CD pipeline
php artisan test --filter=Telegram
php artisan test --filter=Webhook

# Manual testing checklist:
[ ] getMe returns bot info
[ ] setWebhook succeeds
[ ] Webhook receives update
[ ] Message created in database
[ ] Reply works
```

### 3. Gradual Rollout

```php
// Use feature flag
Feature::active('telegram_integration') // controlled via database

// Gradual rollout strategy:
// Day 1: 5% users (internal)
// Day 3: 25% users (power users)
// Day 7: 50% users (all Pro plans)
// Day 14: 100% users (all)
```

### 4. Health Check Pre-deploy

```bash
# Add to deployment script
php artisan telegram:health:check

# Script:
#!/bin/bash
echo "Checking Telegram health before deploy..."

if ! php artisan telegram:health:check > /dev/null 2>&1; then
    echo "Telegram health check failed. Aborting deploy."
    exit 1
fi

echo "Telegram health check passed."
# Continue with deploy...
```

---

## 📞 EMERGENCY CONTACTS

| Role | Name | Slack | Phone |
|------|------|-------|-------|
| SRE | DevOps Team | #devops | +62xxx |
| Backend | Backend Team | #backend | +62xxx |
| Product | Product Manager | #product | +62xxx |
| Security | Security Team | #security | +62xxx |

---

*Document Version: 1.0*  
*Last Updated: 2025-11-06*  
*Project: FinWa - Telegram Integration Rollback Strategy*
