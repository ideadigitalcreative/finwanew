const path = require('path');

module.exports = {
    apps: [
        {
            // === Queue Worker (Laravel) ===
            // Menggunakan queue-worker.cjs sebagai wrapper Node.js agar
            // argumen PHP (--tries, --timeout) tidak terpotong PM2 CLI parser.
            name: 'laravel-queue-worker',
            script: path.join(__dirname, 'queue-worker.cjs'),
            interpreter: 'node',
            cwd: path.resolve(__dirname),
            instances: 1,
            exec_mode: 'fork',
            autorestart: true,
            watch: false,
            max_memory_restart: '512M',
            min_uptime: '10s',
            max_restarts: 10,
            restart_delay: 5000,
            kill_timeout: 310000,
            wait_ready: false,
            error_file: path.join(__dirname, 'storage', 'logs', 'queue-worker-error.log'),
            out_file:   path.join(__dirname, 'storage', 'logs', 'queue-worker-out.log'),
            log_date_format: 'YYYY-MM-DD HH:mm:ss Z',
            merge_logs: true,
            time: true,
        },
        {
            // === WhatsApp Gateway (Baileys) ===
            name: 'whatsapp-gateway',
            script: 'index.js',
            interpreter: 'node',
            cwd: path.join(__dirname, 'services', 'whatsapp-gateway'),
            instances: 1,
            exec_mode: 'fork',
            autorestart: true,
            watch: false,
            max_memory_restart: '256M',
            min_uptime: '10s',
            max_restarts: 10,
            restart_delay: 5000,
            kill_timeout: 10000,
            wait_ready: false,
            error_file: path.join(__dirname, 'storage', 'logs', 'whatsapp-gateway-error.log'),
            out_file:   path.join(__dirname, 'storage', 'logs', 'whatsapp-gateway-out.log'),
            log_date_format: 'YYYY-MM-DD HH:mm:ss Z',
            merge_logs: true,
            time: true,
        },
    ],
};
