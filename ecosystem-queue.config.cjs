const path = require('path');

module.exports = {
    apps: [{
        name: 'laravel-queue-worker',
        script: 'php',
        args: 'artisan queue:work --tries=3 --timeout=300',
        cwd: path.resolve(__dirname),
        instances: 3,  // Scale ke 3 instances untuk parallel processing
        exec_mode: 'cluster',  // Cluster mode untuk parallel job processing
        autorestart: true,
        watch: false,
        max_memory_restart: '512M',
        env: {
            APP_ENV: process.env.APP_ENV || 'production',
            APP_DEBUG: process.env.APP_DEBUG || 'false',
            QUEUE_CONNECTION: process.env.QUEUE_CONNECTION || 'database',
        },
        error_file: path.join(__dirname, 'storage', 'logs', 'queue-worker-error.log'),
        out_file: path.join(__dirname, 'storage', 'logs', 'queue-worker-out.log'),
        log_date_format: 'YYYY-MM-DD HH:mm:ss Z',
        merge_logs: true,
        time: true,
        min_uptime: '10s',
        max_restarts: 10,
        restart_delay: 5000,
        // Kill timeout untuk queue worker (300 detik = 5 menit)
        kill_timeout: 300000,
        // Wait untuk graceful shutdown
        wait_ready: false,
        listen_timeout: 10000,
    }]
};

