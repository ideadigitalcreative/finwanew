module.exports = {
    apps: [{
        name: 'laravel-queue',
        script: 'php',
        args: 'artisan queue:work --tries=3 --timeout=60',
        cwd: __dirname,
        instances: 1,
        autorestart: true,
        watch: false,
        max_memory_restart: '512M',
        env: {
            APP_ENV: 'local',
            QUEUE_CONNECTION: 'database'
        },
        error_file: './storage/logs/queue-error.log',
        out_file: './storage/logs/queue-out.log',
        log_date_format: 'YYYY-MM-DD HH:mm:ss Z'
    }]
};

