<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';

interface Props {
    tenant?: {
        id: number;
        name: string;
    };
    latestSubscription?: {
        plan: string;
        status: string;
        ends_at: string | null;
    } | null;
    pendingSubscription?: {
        id: number;
        plan: string;
        status: string;
    } | null;
}

const props = withDefaults(defineProps<Props>(), {
    tenant: () => ({ id: 0, name: '' }),
    latestSubscription: null,
    pendingSubscription: null,
});

const page = usePage();
const auth = page.props.auth as { user: { name: string } } | undefined;

const formatDate = (dateString: string | null) => {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString('id-ID', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
};

const getPlanName = (plan: string) => {
    const planNames: Record<string, string> = {
        'free': 'Gratis',
        'trial': 'Trial',
        'growth': 'Paket Lengkap',
        'premium': 'Premium',
        'basic': 'Basic',
    };
    return planNames[plan] || plan;
};
</script>

<template>
    <Head title="Subscription Expired" />
    
    <div class="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-900 dark:to-gray-800 flex items-center justify-center p-4">
        <div class="max-w-lg w-full">
            <!-- Main Card -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden">
                <!-- Header with Warning Icon -->
                <div class="bg-gradient-to-r from-orange-500 via-orange-400 to-amber-400 p-8 text-center">
                    <div class="mx-auto w-20 h-20 bg-white/20 rounded-full flex items-center justify-center mb-4">
                        <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <h1 class="text-2xl font-bold text-white mb-2">Subscription Expired</h1>
                    <p class="text-white/90">Langganan Anda telah berakhir</p>
                </div>

                <!-- Content -->
                <div class="p-8">
                    <!-- User Info -->
                    <div class="text-center mb-6">
                        <p class="text-gray-600 dark:text-gray-400">
                            Halo, <span class="font-semibold text-gray-900 dark:text-white">{{ auth?.user?.name }}</span>
                        </p>
                    </div>

                    <!-- Subscription Info -->
                    <div v-if="latestSubscription" class="bg-gray-50 dark:bg-gray-700/50 rounded-xl p-4 mb-6">
                        <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">Detail Langganan Terakhir</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between">
                                <span class="text-gray-600 dark:text-gray-400">Paket</span>
                                <span class="font-medium text-gray-900 dark:text-white">{{ getPlanName(latestSubscription.plan) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600 dark:text-gray-400">Berakhir</span>
                                <span class="font-medium text-red-600 dark:text-red-400">{{ formatDate(latestSubscription.ends_at) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600 dark:text-gray-400">Status</span>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400">
                                    Expired
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Request Notice -->
                    <div v-if="pendingSubscription" class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl p-4 mb-6">
                        <div class="flex items-start gap-3">
                            <div class="flex-shrink-0">
                                <svg class="w-5 h-5 text-amber-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div>
                                <h4 class="text-sm font-medium text-amber-800 dark:text-amber-300">Pengajuan Perpanjangan</h4>
                                <p class="text-sm text-amber-700 dark:text-amber-400 mt-1">
                                    Anda memiliki pengajuan perpanjangan yang sedang menunggu konfirmasi admin.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Call to Action -->
                    <div class="space-y-3">
                        <p class="text-center text-gray-600 dark:text-gray-400 text-sm mb-4">
                            Untuk melanjutkan menggunakan semua fitur aplikasi, silakan perpanjang langganan Anda.
                        </p>
                        
                        <Link
                            href="/subscriptions/new"
                            class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-orange-500 to-amber-500 px-6 py-3 text-base font-semibold text-white shadow-lg hover:from-orange-600 hover:to-amber-600 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 transition-all"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                            Perpanjang Sekarang
                        </Link>

                        <Link
                            href="/subscriptions/new"
                            class="w-full inline-flex items-center justify-center gap-2 rounded-xl border-2 border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 px-6 py-3 text-base font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition-all"
                        >
                            Lihat Paket Harga
                        </Link>
                    </div>

                    <!-- Contact Support -->
                    <div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700 text-center">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Butuh bantuan? 
                            <a href="https://wa.me/6281234567890" target="_blank" class="text-green-600 hover:text-green-700 font-medium">
                                Hubungi Kami via WhatsApp
                            </a>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Logout Link -->
            <div class="mt-4 text-center">
                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300"
                >
                    Keluar dari akun
                </Link>
            </div>
        </div>
    </div>
</template>
