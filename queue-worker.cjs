/**
 * Queue worker wrapper untuk PM2.
 * PM2 menjalankan file ini sebagai Node.js, yang langsung spawn php.exe
 * sehingga argumen tidak terpotong oleh PM2 CLI parser.
 */
const { spawn } = require('child_process');
const path = require('path');

const phpBin = 'C:\\Users\\melis\\.config\\herd\\bin\\php84\\php.exe';
const artisan = path.join(__dirname, 'artisan');

const worker = spawn(phpBin, [artisan, 'queue:work', '--tries=3', '--timeout=300', '--sleep=1'], {
    cwd: __dirname,
    stdio: 'inherit',
    env: { ...process.env },
});

worker.on('close', (code) => {
    // Keluar dengan kode sama agar PM2 autorestart bekerja
    process.exit(code ?? 1);
});

process.on('SIGINT', () => worker.kill('SIGINT'));
process.on('SIGTERM', () => worker.kill('SIGTERM'));
