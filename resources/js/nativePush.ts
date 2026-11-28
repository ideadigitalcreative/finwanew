/**
 * Jembatan push notification aplikasi Android (WebView) ↔ web FinWa.
 *
 * Aplikasi Android (lihat docs/ANDROID_PUSH_FCM.md) menyediakan salah satu:
 *   1. window.FinwaAndroid.getFcmToken(): string        — dipanggil web saat halaman dimuat
 *   2. window.onFinwaFcmToken(token)                     — dipanggil Android saat token baru/berubah
 * Token lalu dikirim ke POST /device-tokens memakai sesi login web.
 *
 * Di browser biasa (tanpa jembatan) modul ini tidak melakukan apa-apa.
 */

declare global {
    interface Window {
        FinwaAndroid?: {
            getFcmToken?: () => string | null;
            getDeviceName?: () => string | null;
            requestNotificationPermission?: () => void;
        };
        onFinwaFcmToken?: (token: string) => void;
    }
}

const SENT_KEY = 'finwa_fcm_token_sent';

/**
 * Header CSRF. Utamakan cookie XSRF-TOKEN (selalu segar) — meta csrf-token
 * basi setelah login via Inertia (sesi diregenerasi tanpa reload halaman) → 419.
 */
function csrfHeader(): Record<string, string> {
    const cookie = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    if (cookie) {
        return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.substring('XSRF-TOKEN='.length)) };
    }
    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;

    return meta ? { 'X-CSRF-TOKEN': meta } : {};
}

async function sendToken(token: string): Promise<void> {
    if (!token || token.length < 20) {
        return;
    }
    try {
        if (localStorage.getItem(SENT_KEY) === token) {
            return; // sudah terdaftar untuk sesi ini
        }
    } catch {
        // localStorage tidak tersedia — tetap kirim
    }

    const headers: Record<string, string> = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...csrfHeader(),
    };

    try {
        const res = await fetch('/device-tokens', {
            method: 'POST',
            credentials: 'same-origin',
            headers,
            body: JSON.stringify({
                token,
                platform: 'android',
                device_name: window.FinwaAndroid?.getDeviceName?.() ?? null,
            }),
        });
        // 401/419 = belum login → coba lagi di halaman berikutnya setelah login
        if (res.ok) {
            try {
                localStorage.setItem(SENT_KEY, token);
            } catch {
                // abaikan
            }
        }
    } catch {
        // jaringan putus — dicoba lagi saat halaman dimuat berikutnya
    }
}

export function initNativePush(isLoggedIn: () => boolean): void {
    if (typeof window === 'undefined') {
        return;
    }

    // Android memanggil ini saat token tersedia / diperbarui
    window.onFinwaFcmToken = (token: string) => {
        if (isLoggedIn()) {
            void sendToken(token);
        } else {
            pendingToken = token;
        }
    };

    trySync(isLoggedIn);
}

let pendingToken: string | null = null;

/** Panggil setiap navigasi Inertia: kirim token begitu user sudah login. */
export function trySync(isLoggedIn: () => boolean): void {
    if (typeof window === 'undefined' || !isLoggedIn()) {
        return;
    }
    const token = pendingToken ?? window.FinwaAndroid?.getFcmToken?.() ?? null;
    pendingToken = null;
    if (token) {
        void sendToken(token);
    }
}

/** Saat logout: lupakan token yang tercatat agar akun berikutnya mendaftar ulang. */
export function forgetSentToken(): void {
    try {
        localStorage.removeItem(SENT_KEY);
    } catch {
        // abaikan
    }
}
