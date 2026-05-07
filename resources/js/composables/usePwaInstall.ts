import { computed, onMounted, ref } from 'vue';
import { deferredInstallPrompt, type BeforeInstallPromptEvent } from '@/pwaInstallState';

export type { BeforeInstallPromptEvent };

function isStandaloneDisplay(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }
    const mm = window.matchMedia('(display-mode: standalone)').matches;
    const iosStandalone = (window.navigator as Navigator & { standalone?: boolean }).standalone === true;
    return mm || iosStandalone;
}

function detectIos(): boolean {
    if (typeof navigator === 'undefined') {
        return false;
    }
    return /iPad|iPhone|iPod/.test(navigator.userAgent);
}

/**
 * State instal PWA — prompt asli ada di `deferredInstallPrompt` (diisi dari initPwaInstallCapture di app.ts).
 */
export function usePwaInstall() {
    const isStandalone = ref(typeof window !== 'undefined' ? isStandaloneDisplay() : false);
    const isIos = ref(typeof window !== 'undefined' ? detectIos() : false);

    onMounted(() => {
        isStandalone.value = isStandaloneDisplay();
        isIos.value = detectIos();
    });

    const canUseNativePrompt = computed(() => deferredInstallPrompt.value !== null);

    async function promptInstall(): Promise<void> {
        const ev = deferredInstallPrompt.value;
        if (!ev) {
            return;
        }
        await ev.prompt();
        try {
            await ev.userChoice;
        } finally {
            deferredInstallPrompt.value = null;
        }
    }

    return {
        deferredInstallPrompt,
        isStandalone,
        isIos,
        canUseNativePrompt,
        promptInstall,
    };
}
