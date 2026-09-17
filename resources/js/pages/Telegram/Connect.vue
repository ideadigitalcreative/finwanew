<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, usePage, router } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import axios from 'axios';
import { useSweetAlert } from '@/composables/useSweetAlert';
import { type BreadcrumbItem } from '@/types';

const { showSuccess, showError } = useSweetAlert();
const page = usePage();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Integrasi Telegram',
        href: '/telegram/connect',
    },
];

const isLoading = ref(false);
const deepLinkUrl = ref('');
const alternateDeepLinkUrl = ref('');
const expiresAt = ref('');
const isWaitingForTelegram = ref(false);

// Bangun URL deep link Telegram; telegram.me lebih andal jika t.me diblokir DNS
const buildTelegramDeepLink = (botUsername: string, token: string, domain: 'telegram.me' | 't.me' = 'telegram.me') =>
    `https://${domain}/${botUsername}?start=link_${token}`;

const isMobileDevice = () => /android|iphone|ipad|ipod/i.test(navigator.userAgent);

const isStandalonePwa = () =>
    window.matchMedia('(display-mode: standalone)').matches ||
    (window.navigator as Navigator & { standalone?: boolean }).standalone === true;

// Buka link eksternal tanpa mengganti halaman PWA/webview (hindari ERR_UNKNOWN_URL_SCHEME)
const openExternalLink = (url: string) => {
    const link = document.createElement('a');
    link.href = url;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    document.body.appendChild(link);
    link.click();
    link.remove();
};

// Check if user already has telegram_chat_id
const telegramConnected = computed(() => {
    const user = page.props.auth?.user as any;
    return Boolean(user?.telegram_chat_id);
});

const generateTelegramLink = async () => {
    try {
        isLoading.value = true;
        const response = await axios.post('/telegram/generate-link');
        
        if (response.data.success) {
            const { bot_username: botUsername, token } = response.data.data;
            expiresAt.value = response.data.data.expires_at;

            const primaryLink = buildTelegramDeepLink(botUsername, token, 'telegram.me');
            const tMeLink = buildTelegramDeepLink(botUsername, token, 't.me');

            deepLinkUrl.value = primaryLink;
            alternateDeepLinkUrl.value = tMeLink;
            isWaitingForTelegram.value = true;

            // Jangan pakai window.location.href / tg:// — di PWA akan merusak webview.
            if (!isMobileDevice() && !isStandalonePwa()) {
                openExternalLink(primaryLink);
                showSuccess('Link Telegram berhasil dibuat! Membuka Telegram...');
            } else {
                showSuccess('Link siap! Ketuk "Buka di Telegram", lalu kembali ke FinWa setelah konfirmasi di bot.');
            }

            // Start polling untuk cek status
            startStatusPolling();
        } else {
            showError(response.data.error || 'Gagal membuat link Telegram');
        }
    } catch (error: any) {
        console.error('Error generating Telegram link:', error);
        
        let errorMessage = 'Terjadi kesalahan saat membuat link Telegram';
        if (error.response) {
            if (error.response.data?.message) {
                errorMessage = error.response.data.message;
            } else if (error.response.data?.error) {
                errorMessage = error.response.data.error;
            } else {
                errorMessage = `Error ${error.response.status}: ${error.response.statusText}`;
            }
        } else if (error.request) {
            errorMessage = 'Tidak dapat terhubung ke server. Periksa koneksi internet Anda.';
        } else {
            errorMessage = error.message || errorMessage;
        }
        
        showError(errorMessage);
    } finally {
        isLoading.value = false;
    }
};

const checkConnectionStatus = async () => {
    try {
        const response = await axios.get('/telegram/link-status');
        if (response.data.success && response.data.has_valid_token) {
            expiresAt.value = response.data.expires_at;
        }
    } catch (error: any) {
        console.error('Error checking status:', error);
    }
};

// Polling untuk cek status koneksi setelah generate link
let statusCheckInterval: ReturnType<typeof setInterval> | null = null;

const checkTelegramConnection = async (): Promise<boolean> => {
    try {
        const response = await axios.get('/telegram/link-status');

        if (response.data.success && response.data.telegram_connected) {
            if (statusCheckInterval) {
                clearInterval(statusCheckInterval);
                statusCheckInterval = null;
            }
            isWaitingForTelegram.value = false;
            showSuccess('Akun Telegram berhasil terhubung!');

            // Refresh props auth agar status koneksi langsung terupdate di UI
            router.reload({ only: ['auth'] });
            return true;
        }
    } catch (error) {
        console.error('Error checking Telegram connection:', error);
    }
    return false;
};

const startStatusPolling = () => {
    if (statusCheckInterval) {
        clearInterval(statusCheckInterval);
    }

    let attempts = 0;
    statusCheckInterval = setInterval(async () => {
        attempts++;
        await checkTelegramConnection();

        if (attempts >= 20) {
            if (statusCheckInterval) {
                clearInterval(statusCheckInterval);
                statusCheckInterval = null;
            }
        }
    }, 3000);
};

const handleVisibilityChange = () => {
    if (document.visibilityState === 'visible' && isWaitingForTelegram.value) {
        void checkTelegramConnection();
    }
};

onMounted(() => {
    document.addEventListener('visibilitychange', handleVisibilityChange);
});

onUnmounted(() => {
    document.removeEventListener('visibilitychange', handleVisibilityChange);
    if (statusCheckInterval) {
        clearInterval(statusCheckInterval);
        statusCheckInterval = null;
    }
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Integrasi Bot Telegram - FinWa" />

        <div class="flex flex-col gap-6 p-4 md:p-6 font-['Plus_Jakarta_Sans',sans-serif] min-h-full">
            <!-- Header Section -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl md:text-2xl font-bold text-[#1b1c19] dark:text-white flex items-center gap-2">
                        <span class="w-8 h-8 rounded-xl bg-[#0088cc]/15 text-[#0088cc] flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5 fill-current" viewBox="0 0 24 24">
                                <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"></path>
                            </svg>
                        </span>
                        Integrasi Bot Telegram
                    </h2>
                    <p class="text-xs md:text-sm text-[#4d4634]/60 dark:text-white/50 mt-1">
                        Hubungkan akun Telegram Anda untuk menerima laporan dan mencatat keuangan otomatis via bot Telegram FinWa
                    </p>
                </div>
            </div>

            <!-- Grid Konten -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                
                <!-- Kolom Kiri: Status & Langkah Penghubungan (Span 2) -->
                <div class="lg:col-span-2 flex flex-col gap-6">
                    
                    <!-- 1. Card Status Koneksi (Gaya Segar Finwa) -->
                    <div class="relative overflow-hidden rounded-2xl bg-white dark:bg-[#23231f] border border-[#eae8e2] dark:border-white/10 p-5 md:p-6 shadow-[0_4px_20px_rgb(0,0,0,0.03)] dark:shadow-none transition-all">
                        <!-- Decorative Glow -->
                        <div class="absolute -right-10 -top-10 w-36 h-36 rounded-full bg-[#0088cc]/15 blur-2xl pointer-events-none"></div>
                        
                        <div class="relative z-10 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div class="flex items-center gap-3.5">
                                <div 
                                    class="w-12 h-12 rounded-2xl flex items-center justify-center shrink-0"
                                    :class="telegramConnected ? 'bg-[#51fac1]/20 text-[#007152] dark:text-[#51fac1]' : 'bg-[#f5f3ee] text-[#4d4634]/50 dark:bg-white/5 dark:text-white/40'"
                                >
                                    <span class="material-symbols-outlined text-2xl">
                                        {{ telegramConnected ? 'verified' : 'link_off' }}
                                    </span>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <h3 class="text-base font-bold text-[#1b1c19] dark:text-white">Status Koneksi</h3>
                                        <span 
                                            class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold"
                                            :class="telegramConnected ? 'bg-[#51fac1] text-[#007152]' : 'bg-[#f5f3ee] dark:bg-white/10 text-[#4d4634]/70 dark:text-white/60'"
                                        >
                                            <span class="material-symbols-outlined text-[12px]">
                                                {{ telegramConnected ? 'check_circle' : 'pending' }}
                                            </span>
                                            {{ telegramConnected ? 'Terhubung' : 'Belum Terhubung' }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-[#4d4634]/60 dark:text-white/50 mt-1">
                                        {{ telegramConnected 
                                            ? 'Akun Telegram Anda aktif terhubung dengan sistem pencatatan FinWa.' 
                                            : 'Hubungkan akun Telegram agar bot dapat mengenali instruksi keuangan Anda.' 
                                        }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Card Hubungkan Bot & Langkah Panduan -->
                    <div class="relative overflow-hidden rounded-2xl bg-white dark:bg-[#23231f] border border-[#eae8e2] dark:border-white/10 p-5 md:p-6 shadow-[0_4px_20px_rgb(0,0,0,0.03)] dark:shadow-none transition-all">
                        <!-- Decorative Glow -->
                        <div class="absolute -left-10 -bottom-10 w-36 h-36 rounded-full bg-[#ffd23f]/20 blur-2xl pointer-events-none"></div>

                        <div class="relative z-10">
                            <div class="mb-5 border-b border-[#eae8e2] dark:border-white/10 pb-3 flex items-center gap-2.5">
                                <span class="w-8 h-8 rounded-xl bg-[#ffd23f]/20 text-[#574500] dark:text-[#ffd23f] flex items-center justify-center shrink-0">
                                    <span class="material-symbols-outlined text-lg">alt_route</span>
                                </span>
                                <div>
                                    <h3 class="text-base font-bold text-[#1b1c19] dark:text-white">Langkah Menghubungkan</h3>
                                    <p class="text-xs text-[#4d4634]/60 dark:text-white/50">Cukup 3 langkah singkat untuk mengaktifkan bot.</p>
                                </div>
                            </div>

                            <!-- Steps List -->
                            <div class="grid gap-3.5 mb-6">
                                <div class="flex items-start gap-3.5 p-3.5 rounded-xl bg-[#fbf9f3] dark:bg-white/5 border border-[#eae8e2] dark:border-white/10">
                                    <div class="w-7 h-7 rounded-xl bg-[#ffd23f] text-[#574500] font-bold text-xs flex items-center justify-center shrink-0 shadow-xs">
                                        1
                                    </div>
                                    <div>
                                        <h4 class="text-xs sm:text-sm font-bold text-[#1b1c19] dark:text-white">Buat Link Penghubung</h4>
                                        <p class="text-xs text-[#4d4634]/60 dark:text-white/50 mt-0.5">
                                            Tekan tombol <span class="font-bold text-[#1b1c19] dark:text-white">"Buat Link Telegram"</span> di bawah untuk membuat token unik.
                                        </p>
                                    </div>
                                </div>

                                <div class="flex items-start gap-3.5 p-3.5 rounded-xl bg-[#fbf9f3] dark:bg-white/5 border border-[#eae8e2] dark:border-white/10">
                                    <div class="w-7 h-7 rounded-xl bg-[#ffd23f] text-[#574500] font-bold text-xs flex items-center justify-center shrink-0 shadow-xs">
                                        2
                                    </div>
                                    <div>
                                        <h4 class="text-xs sm:text-sm font-bold text-[#1b1c19] dark:text-white">Buka Chat Bot Telegram</h4>
                                        <p class="text-xs text-[#4d4634]/60 dark:text-white/50 mt-0.5">
                                            Buka tautan yang muncul dan tekan tombol <span class="font-bold text-[#0088cc]">"START"</span> di aplikasi Telegram Anda.
                                        </p>
                                    </div>
                                </div>

                                <div class="flex items-start gap-3.5 p-3.5 rounded-xl bg-[#fbf9f3] dark:bg-white/5 border border-[#eae8e2] dark:border-white/10">
                                    <div class="w-7 h-7 rounded-xl bg-[#ffd23f] text-[#574500] font-bold text-xs flex items-center justify-center shrink-0 shadow-xs">
                                        3
                                    </div>
                                    <div>
                                        <h4 class="text-xs sm:text-sm font-bold text-[#1b1c19] dark:text-white">Selesai &amp; Siap Dipakai</h4>
                                        <p class="text-xs text-[#4d4634]/60 dark:text-white/50 mt-0.5">
                                            Kembali ke halaman FinWa ini. Status koneksi akan otomatis terverifikasi dan menjadi centang hijau.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Action Button -->
                            <div class="pt-2">
                                <Button 
                                    @click="generateTelegramLink" 
                                    :disabled="isLoading"
                                    class="w-full sm:w-auto bg-[#ffd23f] hover:bg-[#ffe089] text-[#574500] font-bold text-xs sm:text-sm rounded-xl px-5 py-2.5 h-auto shadow-none border-0 inline-flex items-center justify-center gap-2"
                                >
                                    <Spinner v-if="isLoading" />
                                    <span v-else class="material-symbols-outlined text-lg">link</span>
                                    {{ isLoading ? 'Menyiapkan Tautan...' : 'Buat Link Telegram' }}
                                </Button>
                            </div>

                            <!-- Link Ready Card (Muncul saat link dibuat) -->
                            <div v-if="deepLinkUrl" class="mt-5 p-4 rounded-xl bg-[#0088cc]/10 border border-[#0088cc]/25 space-y-3.5">
                                <div class="flex items-start gap-3">
                                    <span class="material-symbols-outlined text-[#0088cc] text-xl shrink-0 mt-0.5">check_circle</span>
                                    <div class="flex-1 space-y-3 min-w-0">
                                        <div>
                                            <h4 class="text-xs sm:text-sm font-bold text-[#0088cc]">Tautan Berhasil Dibuat!</h4>
                                            <p class="text-xs text-[#4d4634] dark:text-white/80 mt-0.5">
                                                Tekan tombol di bawah untuk membuka Telegram. FinWa akan tetap menunggu dan mendeteksi koneksi secara otomatis.
                                            </p>
                                        </div>

                                        <Button
                                            class="w-full bg-[#0088cc] hover:bg-[#0077b5] text-white font-bold text-xs sm:text-sm rounded-xl py-2.5 h-auto shadow-none inline-flex items-center justify-center gap-2"
                                            @click="openExternalLink(deepLinkUrl)"
                                        >
                                            <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24">
                                                <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"></path>
                                            </svg>
                                            Buka di Aplikasi Telegram
                                        </Button>

                                        <!-- Direct Link Info -->
                                        <div class="space-y-1.5 pt-1 text-xs">
                                            <p class="font-bold text-[#1b1c19] dark:text-white">Tautan langsung:</p>
                                            <a
                                                :href="deepLinkUrl"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="text-[#0088cc] underline font-medium break-all block"
                                            >
                                                {{ deepLinkUrl }}
                                            </a>
                                            <div v-if="alternateDeepLinkUrl">
                                                <p class="font-bold text-[#1b1c19] dark:text-white mt-2">Tautan alternatif (t.me):</p>
                                                <a
                                                    :href="alternateDeepLinkUrl"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="text-[#0088cc] underline font-medium break-all block"
                                                >
                                                    {{ alternateDeepLinkUrl }}
                                                </a>
                                            </div>
                                        </div>

                                        <p v-if="expiresAt" class="text-[11px] font-semibold text-[#4d4634]/60 dark:text-white/50 pt-1">
                                            Masa berlaku token: {{ new Date(expiresAt).toLocaleString('id-ID') }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Kolom Kanan: Manfaat & Fitur Bot (Span 1) -->
                <div class="lg:col-span-1 flex flex-col gap-6">
                    <div class="relative overflow-hidden rounded-2xl bg-white dark:bg-[#23231f] border border-[#eae8e2] dark:border-white/10 p-5 md:p-6 shadow-[0_4px_20px_rgb(0,0,0,0.03)] dark:shadow-none transition-all">
                        <div class="mb-4 border-b border-[#eae8e2] dark:border-white/10 pb-3 flex items-center gap-2.5">
                            <span class="w-8 h-8 rounded-xl bg-[#51fac1]/20 text-[#007152] dark:text-[#51fac1] flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-lg">smart_toy</span>
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-[#1b1c19] dark:text-white">Keunggulan Bot</h3>
                                <p class="text-xs text-[#4d4634]/60 dark:text-white/50">Fitur yang bisa dinikmati di Telegram.</p>
                            </div>
                        </div>

                        <ul class="space-y-3.5">
                            <li class="flex items-start gap-3 p-3 rounded-xl bg-[#fbf9f3] dark:bg-white/5 border border-[#eae8e2] dark:border-white/10">
                                <span class="material-symbols-outlined text-base text-[#006c4f] dark:text-[#51fac1] shrink-0 mt-0.5">notifications_active</span>
                                <div>
                                    <p class="text-xs font-bold text-[#1b1c19] dark:text-white">Notifikasi Real-time</p>
                                    <p class="text-[11px] text-[#4d4634]/70 dark:text-white/60 mt-0.5">Dapatkan laporan instan setiap ada pergerakan transaksi.</p>
                                </div>
                            </li>

                            <li class="flex items-start gap-3 p-3 rounded-xl bg-[#fbf9f3] dark:bg-white/5 border border-[#eae8e2] dark:border-white/10">
                                <span class="material-symbols-outlined text-base text-[#006c4f] dark:text-[#51fac1] shrink-0 mt-0.5">account_balance_wallet</span>
                                <div>
                                    <p class="text-xs font-bold text-[#1b1c19] dark:text-white">Cek Saldo Cepat</p>
                                    <p class="text-[11px] text-[#4d4634]/70 dark:text-white/60 mt-0.5">Ketik perintah singkat untuk melihat saldo aktif kantong dompetmu.</p>
                                </div>
                            </li>

                            <li class="flex items-start gap-3 p-3 rounded-xl bg-[#fbf9f3] dark:bg-white/5 border border-[#eae8e2] dark:border-white/10">
                                <span class="material-symbols-outlined text-base text-[#006c4f] dark:text-[#51fac1] shrink-0 mt-0.5">edit_calendar</span>
                                <div>
                                    <p class="text-xs font-bold text-[#1b1c19] dark:text-white">Catat Pengeluaran Bebas</p>
                                    <p class="text-[11px] text-[#4d4634]/70 dark:text-white/60 mt-0.5">Kirim pesan teks biasa ke bot untuk mencatat jajan atau pemasukan.</p>
                                </div>
                            </li>

                            <li class="flex items-start gap-3 p-3 rounded-xl bg-[#fbf9f3] dark:bg-white/5 border border-[#eae8e2] dark:border-white/10">
                                <span class="material-symbols-outlined text-base text-[#006c4f] dark:text-[#51fac1] shrink-0 mt-0.5">insights</span>
                                <div>
                                    <p class="text-xs font-bold text-[#1b1c19] dark:text-white">Rekap &amp; Insight Arus Kas</p>
                                    <p class="text-[11px] text-[#4d4634]/70 dark:text-white/60 mt-0.5">Ringkasan mingguan &amp; bulanan langsung dikirim ke chat Telegram.</p>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>

            </div>
        </div>
    </AppLayout>
</template>
