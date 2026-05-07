<script setup lang="ts">
import { usePwaInstall } from '@/composables/usePwaInstall';
import { Download, Share2, X } from 'lucide-vue-next';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const SESSION_KEY = 'finwa_pwa_install_prompt_dismissed';

function isMobileDevice(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }
    return window.matchMedia('(max-width: 768px)').matches || 'ontouchstart' in window;
}

const open = ref(false);
const mode = ref<'chrome' | 'ios' | null>(null);
const dismissed = ref(false);
const isMobile = ref(isMobileDevice());

function loadDismissed(): boolean {
    try {
        return sessionStorage.getItem(SESSION_KEY) === '1';
    } catch {
        return false;
    }
}

dismissed.value = loadDismissed();

const { isStandalone, isIos, canUseNativePrompt, promptInstall } = usePwaInstall();

const page = usePage();

const isDesktopAdmin = computed(() => {
    if (isMobile.value) return false;
    
    const user = (page.props.auth as any)?.user;
    if (!user) return false;
    
    const roleSlug = user.role?.slug;
    return user.is_super_admin || roleSlug === 'admin' || roleSlug === 'owner';
});

function dismissForSession(): void {
    dismissed.value = true;
    try {
        sessionStorage.setItem(SESSION_KEY, '1');
    } catch {
        /* ignore */
    }
}

function closeDialog(): void {
    open.value = false;
    dismissForSession();
}

watch(
    isStandalone,
    (v) => {
        if (v) {
            open.value = false;
        }
    },
    { immediate: true },
);

watch(
    canUseNativePrompt,
    async (can) => {
        if (!can || dismissed.value || isStandalone.value || isDesktopAdmin.value) {
            return;
        }
        mode.value = 'chrome';
        await nextTick();
        open.value = true;
    },
    { immediate: true },
);

async function onInstallClick(): Promise<void> {
    await promptInstall();
    open.value = false;
    dismissForSession();
}

function onNanti(): void {
    closeDialog();
}

function onBackdropClick(): void {
    closeDialog();
}

function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Escape' && open.value) {
        closeDialog();
    }
}

let iosHintTimer: ReturnType<typeof setTimeout> | undefined;

onMounted(() => {
    window.addEventListener('keydown', onKeydown);

    if (!dismissed.value && !isStandalone.value && isIos.value && isMobile.value) {
        iosHintTimer = window.setTimeout(() => {
            if (dismissed.value || isStandalone.value) {
                return;
            }
            if (canUseNativePrompt.value) {
                return;
            }
            mode.value = 'ios';
            open.value = true;
        }, 1200);
    }
});

onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown);
    if (iosHintTimer) {
        window.clearTimeout(iosHintTimer);
    }
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open && mode"
                class="fixed inset-0 z-[300] flex items-center justify-center p-5"
                role="dialog"
                aria-modal="true"
                aria-labelledby="pwa-install-title"
            >
                <div
                    class="absolute inset-0 bg-neutral-950/50 backdrop-blur-[2px]"
                    @click="onBackdropClick"
                />

                <div
                    class="relative w-full max-w-[340px] overflow-hidden rounded-2xl border border-neutral-200/80 bg-white px-6 pb-6 pt-7 shadow-2xl shadow-neutral-900/10 dark:border-neutral-700/80 dark:bg-neutral-900 dark:shadow-black/40"
                    @click.stop
                >
                    <button
                        type="button"
                        class="absolute right-3 top-3 rounded-full p-1.5 text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                        aria-label="Tutup"
                        @click="onNanti"
                    >
                        <X class="h-4 w-4" />
                    </button>

                    <div v-if="mode === 'chrome'" class="flex flex-col items-center text-center">
                        <div
                            class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-teal-50/90 ring-1 ring-teal-100 dark:bg-teal-950/50 dark:ring-teal-800"
                        >
                            <img src="/finwalogo.png" alt="" class="h-9 w-9 object-contain" width="36" height="36" />
                        </div>
                        <h2 id="pwa-install-title" class="text-lg font-semibold tracking-tight text-neutral-900 dark:text-white">
                            Pasang FinWa
                        </h2>
                        <p class="mt-1.5 text-sm leading-relaxed text-neutral-500 dark:text-neutral-400">
                            Akses cepat dari layar utama tanpa buka browser lagi.
                        </p>
                        <button
                            type="button"
                            class="mt-6 w-full rounded-xl bg-teal-600 py-3 text-sm font-medium text-white shadow-sm transition hover:bg-teal-700 active:scale-[0.98] dark:bg-teal-500 dark:hover:bg-teal-600"
                            @click="onInstallClick"
                        >
                            <span class="inline-flex items-center justify-center gap-2">
                                <Download class="h-4 w-4" />
                                Pasang aplikasi
                            </span>
                        </button>
                        <button
                            type="button"
                            class="mt-2 w-full py-2.5 text-sm text-neutral-500 transition hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-200"
                            @click="onNanti"
                        >
                            Nanti saja
                        </button>
                    </div>

                    <div v-else-if="mode === 'ios'" class="flex flex-col items-center text-center">
                        <div
                            class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-teal-50/90 ring-1 ring-teal-100 dark:bg-teal-950/50 dark:ring-teal-800"
                        >
                            <Share2 class="h-7 w-7 text-teal-600 dark:text-teal-400" />
                        </div>
                        <h2 id="pwa-install-title" class="text-lg font-semibold tracking-tight text-neutral-900 dark:text-white">
                            Tambahkan ke Layar Utama
                        </h2>
                        <p class="mt-1.5 text-left text-sm leading-relaxed text-neutral-500 dark:text-neutral-400">
                            Di Safari: ketuk <strong class="text-neutral-700 dark:text-neutral-300">Bagikan</strong> lalu
                            <strong class="text-neutral-700 dark:text-neutral-300">Tambahkan ke Layar Utama</strong>.
                        </p>
                        <button
                            type="button"
                            class="mt-6 w-full rounded-xl bg-teal-600 py-3 text-sm font-medium text-white shadow-sm transition hover:bg-teal-700 dark:bg-teal-500 dark:hover:bg-teal-600"
                            @click="onNanti"
                        >
                            Mengerti
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
