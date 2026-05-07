/**
 * Daftarkan Service Worker untuk PWA (hanya di production build).
 * Tanpa menunggu `load` — lebih cepat aktif, syarat beforeinstallprompt di Chrome lebih mungkin terpenuhi.
 */
export function registerPwa(): void {
    if (typeof window === 'undefined' || !('serviceWorker' in navigator)) {
        return;
    }

    if (!import.meta.env.PROD) {
        return;
    }

    const register = (): void => {
        void navigator.serviceWorker
            .register('/sw.js', { scope: '/' })
            .then((registration) => {
                registration.addEventListener('updatefound', () => {
                    const installing = registration.installing;
                    if (!installing) {
                        return;
                    }
                    installing.addEventListener('statechange', () => {
                        if (installing.state === 'installed' && navigator.serviceWorker.controller) {
                            // Versi SW baru tersedia; bisa ditambahkan toast "Refresh untuk update" jika perlu.
                        }
                    });
                });
            })
            .catch(() => {
                /* SW gagal (mis. HTTP non-HTTPS) — aplikasi tetap jalan normal */
            });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', register, { once: true });
    } else {
        register();
    }
}
