/**
 * FinWa Service Worker — PWA (network-first).
 * Setelah mengubah file ini, user biasanya perlu refresh sekali agar browser mengambil SW baru.
 */

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

// Network-first: cocok untuk Inertia + API Laravel (tanpa stale shell).
self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') {
        return;
    }
    event.respondWith(fetch(event.request));
});
