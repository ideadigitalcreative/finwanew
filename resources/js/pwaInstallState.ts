import { shallowRef } from 'vue';

/** Event Chrome/Edge/Android — harus ditangkap dengan preventDefault agar prompt() bisa dipanggil */
export interface BeforeInstallPromptEvent extends Event {
    readonly platforms: string[];
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>;
}

/**
 * State global agar event tidak hilang sebelum komponen Vue mount.
 * `beforeinstallprompt` bisa datang sekali per load — listener harus pasang di bootstrap app.
 */
export const deferredInstallPrompt = shallowRef<BeforeInstallPromptEvent | null>(null);

let captureInitialized = false;

export function initPwaInstallCapture(): void {
    if (typeof window === 'undefined' || captureInitialized) {
        return;
    }
    captureInitialized = true;

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredInstallPrompt.value = e as BeforeInstallPromptEvent;
    });
}
