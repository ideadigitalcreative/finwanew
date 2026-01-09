<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';

interface Props {
    transaction: {
        id: number;
        type: string;
        amount: number;
        transaction_date: string;
        description: string;
        source: string | null;
        reference_number: string | null;
        status: string;
        confidence_score: number;
        category: {
            id: number;
            name: string;
            type: string;
        };
        message: {
            id: number;
            content: string;
            type: string;
            channel: string;
        } | null;
        reviewer: {
            id: number;
            name: string;
            email: string;
        } | null;
        reviewed_at: string | null;
        created_at: string;
        updated_at: string;
    };
}

const props = defineProps<Props>();

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
};

const formatDate = (date: string) => {
    return format(new Date(date), 'dd MMMM yyyy', { locale: id });
};

const formatDateTime = (date: string) => {
    return format(new Date(date), 'dd MMMM yyyy HH:mm', { locale: id });
};

const updateStatus = (status: string) => {
    router.patch(`/transactions/${props.transaction.id}/status`, {
        status,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="`Transaksi #${transaction.id}`" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <Link
                        href="/transactions"
                        class="mb-2 text-sm text-muted-foreground hover:underline"
                    >
                        ← Kembali ke Daftar Transaksi
                    </Link>
                    <h2 class="text-2xl font-bold">Detail Transaksi</h2>
                    <p class="text-sm text-muted-foreground">ID: #{{ transaction.id }}</p>
                </div>
                <div v-if="transaction.status === 'review'" class="flex gap-2">
                    <button
                        @click="updateStatus('rejected')"
                        class="rounded-lg border border-red-300 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100 dark:border-red-700 dark:bg-red-900/20 dark:text-red-400"
                    >
                        Tolak
                    </button>
                    <button
                        @click="updateStatus('confirmed')"
                        class="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700"
                    >
                        Setujui
                    </button>
                </div>
            </div>

            <!-- Status Badge -->
            <div class="flex items-center gap-4">
                <span
                    class="rounded-full px-3 py-1 text-sm font-medium"
                    :class="{
                        'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400': transaction.status === 'confirmed',
                        'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400': transaction.status === 'review',
                        'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400': transaction.status === 'rejected',
                    }"
                >
                    {{ transaction.status }}
                </span>
                <span class="text-sm text-muted-foreground">
                    Confidence: {{ (transaction.confidence_score * 100).toFixed(1) }}%
                </span>
            </div>

            <!-- Main Info Cards -->
            <div class="grid gap-6 md:grid-cols-2">
                <!-- Transaction Info -->
                <div class="rounded-lg border border-sidebar-border/70 bg-card p-6 dark:border-sidebar-border">
                    <h3 class="mb-4 text-lg font-semibold">Informasi Transaksi</h3>
                    <div class="space-y-4">
                        <div>
                            <label class="text-sm font-medium text-muted-foreground">Tanggal</label>
                            <p class="mt-1 text-base font-medium">{{ formatDate(transaction.transaction_date) }}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-muted-foreground">Tipe</label>
                            <p class="mt-1 text-base font-medium capitalize">
                                <span :class="transaction.type === 'income' ? 'text-green-600' : 'text-red-600'">
                                    {{ transaction.type === 'income' ? 'Pendapatan' : 'Pengeluaran' }}
                                </span>
                            </p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-muted-foreground">Jumlah</label>
                            <p
                                class="mt-1 text-2xl font-bold"
                                :class="transaction.type === 'income' ? 'text-green-600' : 'text-red-600'"
                            >
                                {{ transaction.type === 'income' ? '+' : '-' }}{{ formatCurrency(transaction.amount) }}
                            </p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-muted-foreground">Kategori</label>
                            <p class="mt-1 text-base font-medium">{{ transaction.category.name }}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-muted-foreground">Deskripsi</label>
                            <p class="mt-1 text-base">{{ transaction.description }}</p>
                        </div>
                        <div v-if="transaction.source">
                            <label class="text-sm font-medium text-muted-foreground">Sumber/Tujuan</label>
                            <p class="mt-1 text-base">{{ transaction.source }}</p>
                        </div>
                        <div v-if="transaction.reference_number">
                            <label class="text-sm font-medium text-muted-foreground">No. Referensi</label>
                            <p class="mt-1 text-base font-mono">{{ transaction.reference_number }}</p>
                        </div>
                    </div>
                </div>

                <!-- Review Info -->
                <div class="rounded-lg border border-sidebar-border/70 bg-card p-6 dark:border-sidebar-border">
                    <h3 class="mb-4 text-lg font-semibold">Informasi Review</h3>
                    <div class="space-y-4">
                        <div v-if="transaction.reviewer">
                            <label class="text-sm font-medium text-muted-foreground">Ditinjau Oleh</label>
                            <p class="mt-1 text-base">{{ transaction.reviewer.name }}</p>
                            <p class="text-sm text-muted-foreground">{{ transaction.reviewer.email }}</p>
                        </div>
                        <div v-if="transaction.reviewed_at">
                            <label class="text-sm font-medium text-muted-foreground">Tanggal Review</label>
                            <p class="mt-1 text-base">{{ formatDateTime(transaction.reviewed_at) }}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-muted-foreground">Dibuat</label>
                            <p class="mt-1 text-base">{{ formatDateTime(transaction.created_at) }}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-muted-foreground">Diperbarui</label>
                            <p class="mt-1 text-base">{{ formatDateTime(transaction.updated_at) }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Source Message -->
            <div v-if="transaction.message" class="rounded-lg border border-sidebar-border/70 bg-card p-6 dark:border-sidebar-border">
                <h3 class="mb-4 text-lg font-semibold">Pesan Asli</h3>
                <div class="rounded-lg bg-muted p-4">
                    <div class="mb-2 flex items-center gap-2 text-sm text-muted-foreground">
                        <span class="capitalize">{{ transaction.message.channel }}</span>
                        <span>•</span>
                        <span class="capitalize">{{ transaction.message.type }}</span>
                    </div>
                    <p class="whitespace-pre-wrap">{{ transaction.message.content }}</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

