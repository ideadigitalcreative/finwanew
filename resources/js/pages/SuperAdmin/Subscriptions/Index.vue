<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { CreditCard, Edit, Search, Calendar, Plus, Eye, Trash2 } from 'lucide-vue-next';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { useSweetAlert } from '@/composables/useSweetAlert';
import superadminRoutes from '@/routes/superadmin/index';

const superadmin = {
    subscriptions: {
        index: () => superadminRoutes.subscriptions.index.url(),
        update: (id: number) => superadminRoutes.subscriptions.update.url({ subscription: id }),
        extend: (id: number) => superadminRoutes.subscriptions.extend.url({ subscription: id }),
        upgrade: (id: number) => superadminRoutes.subscriptions.upgrade.url({ subscription: id }),
    },
};

interface Subscription {
    id: number;
    tenant: { id: number; name: string } | null;
    plan: string;
    duration_months: number;
    price: number;
    status: string;
    starts_at: string | null;
    ends_at: string | null;
    payment_provider: string | null;
    payment_reference: string | null;
    payment_proof: string | null;
    created_at: string;
}

interface Tenant {
    id: number;
    name: string;
}

interface Props {
    premiumSubscriptions: Subscription[];
    freeSubscriptions: {
        data: Subscription[];
        links: any[];
        meta: {
            current_page: number;
            last_page: number;
            per_page: number;
            total: number;
            from: number | null;
            to: number | null;
        };
    };
    tenants: Tenant[];
    filters: {
        search?: string;
        status?: string;
        tenant_id?: string;
        tab?: string;
    };
}

const props = defineProps<Props>();
const { showError, showSuccess } = useSweetAlert();

const planLabels: Record<string, string> = {
    free: 'Free Trial',
    starter: 'Starter',
    growth: 'Growth',
    pro: 'Pro',
    enterprise: 'Enterprise',
};

const planOptions = [
    { value: 'starter', label: 'Starter' },
    { value: 'growth', label: 'Growth' },
    { value: 'pro', label: 'Pro' },
    { value: 'enterprise', label: 'Enterprise' },
];

const durationOptions = [
    { value: 1, label: '1 Bulan' },
    { value: 3, label: '3 Bulan' },
    { value: 6, label: '6 Bulan' },
    { value: 12, label: '12 Bulan' },
];

const statusOptions = [
    { value: 'pending', label: 'Pending' },
    { value: 'active', label: 'Active' },
];

const showEditDialog = ref(false);
const showExtendDialog = ref(false);
const showPaymentProofDialog = ref(false);
const showUpgradeDialog = ref(false);
const selectedSubscription = ref<Subscription | null>(null);
const paymentProofUrl = ref<string | null>(null);
const activeTab = ref<'premium' | 'free'>(props.filters.tab === 'free' ? 'free' : 'premium');

const searchForm = useForm({
    search: props.filters.search || '',
    status: props.filters.status || '',
    tenant_id: props.filters.tenant_id || '',
});

const editForm = useForm({
    status: 'active',
    starts_at: '',
    ends_at: '',
});

const extendForm = useForm({
    months: 1,
});

const upgradeForm = useForm({
    plan: 'starter',
    duration_months: 1,
    price: 0,
    status: 'pending',
    starts_at: '',
    notes: '',
});

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
};

const formatPlan = (plan: string) => {
    return planLabels[plan] || plan;
};

const formatDate = (date: string | null) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('id-ID', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
};

const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
        active: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
        pending: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
        expired: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
        cancelled: 'bg-gray-100 text-gray-700 dark:bg-gray-900/30 dark:text-gray-400',
    };
    return colors[status] || colors.pending;
};

const getPlanColor = (plan: string) => {
    const colors: Record<string, string> = {
        free: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/30 dark:text-cyan-300',
        starter: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
        growth: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
        pro: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
        enterprise: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
    };
    return colors[plan] || colors.starter;
};

/** Harga standar per plan (per bulan); growth = 20rb */
const planMonthlyPrice: Record<string, number> = {
    starter: 20000,
    growth: 20000,
    pro: 50000,
    enterprise: 100000,
};

const getSuggestedPrice = (plan: string, months: number): number => {
    const monthly = planMonthlyPrice[plan] ?? 20000;
    const subtotal = monthly * months;
    const discount = months >= 12 ? 15 : months >= 6 ? 10 : months >= 3 ? 5 : 0;
    return Math.round(subtotal - (subtotal * discount) / 100);
};

const suggestedPrice = computed(() =>
    getSuggestedPrice(upgradeForm.plan, Number(upgradeForm.duration_months) || 1)
);

const upgradePreviewEndsAt = computed(() => {
    if (!upgradeForm.starts_at || !upgradeForm.duration_months) {
        return '';
    }

    const startDate = new Date(`${upgradeForm.starts_at}T00:00:00`);
    if (Number.isNaN(startDate.getTime())) {
        return '';
    }

    const preview = new Date(startDate);
    preview.setMonth(preview.getMonth() + Number(upgradeForm.duration_months));

    return preview.toLocaleDateString('id-ID', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
});

const applyFilters = () => {
    searchForm.get(superadmin.subscriptions.index(), {
        preserveState: true,
        preserveScroll: true,
    });
};

const openEditDialog = (subscription: Subscription) => {
    selectedSubscription.value = subscription;
    editForm.status = subscription.status;
    editForm.starts_at = subscription.starts_at ? subscription.starts_at.split(' ')[0] : '';
    editForm.ends_at = subscription.ends_at ? subscription.ends_at.split(' ')[0] : '';
    editForm.clearErrors();
    showEditDialog.value = true;
};

const viewPaymentProof = (subscription: Subscription) => {
    if (subscription.payment_proof) {
        paymentProofUrl.value = subscription.payment_proof;
        showPaymentProofDialog.value = true;
    }
};

const openExtendDialog = (subscription: Subscription) => {
    selectedSubscription.value = subscription;
    extendForm.months = 1;
    extendForm.clearErrors();
    showExtendDialog.value = true;
};

const openUpgradeDialog = (subscription: Subscription) => {
    selectedSubscription.value = subscription;
    upgradeForm.reset();
    const newPlan = subscription.plan === 'free' ? 'growth' : subscription.plan;
    const newMonths = subscription.plan === 'free' ? 6 : (subscription.duration_months || 1);
    upgradeForm.plan = ['starter', 'growth', 'pro', 'enterprise'].includes(newPlan) ? newPlan : 'growth';
    upgradeForm.duration_months = newMonths;
    upgradeForm.price = subscription.plan === 'free' ? getSuggestedPrice(upgradeForm.plan, upgradeForm.duration_months) : Number(subscription.price || 0);
    upgradeForm.status = subscription.status === 'active' ? 'active' : 'pending';
    upgradeForm.starts_at = subscription.ends_at
        ? subscription.ends_at.split(' ')[0]
        : new Date().toISOString().slice(0, 10);
    upgradeForm.notes = '';
    upgradeForm.clearErrors();
    showUpgradeDialog.value = true;
};

const applySuggestedPrice = () => {
    upgradeForm.price = getSuggestedPrice(upgradeForm.plan, Number(upgradeForm.duration_months) || 1);
};

const handleUpdate = () => {
    if (!selectedSubscription.value) return;
    
    editForm.put(superadmin.subscriptions.update(selectedSubscription.value.id), {
        onSuccess: () => {
            showSuccess('Berhasil', 'Subscription berhasil diperbarui');
            showEditDialog.value = false;
            selectedSubscription.value = null;
        },
        onError: () => {
            showError('Error', 'Gagal memperbarui subscription');
        },
    });
};

const handleExtend = () => {
    if (!selectedSubscription.value) return;
    
    extendForm.post(superadmin.subscriptions.extend(selectedSubscription.value.id), {
        onSuccess: () => {
            showSuccess('Berhasil', `Subscription diperpanjang ${extendForm.months} bulan`);
            showExtendDialog.value = false;
            selectedSubscription.value = null;
        },
        onError: () => {
            showError('Error', 'Gagal memperpanjang subscription');
        },
    });
};

const handleUpgrade = () => {
    if (!selectedSubscription.value) return;

    upgradeForm.post(superadmin.subscriptions.upgrade(selectedSubscription.value.id), {
        onSuccess: () => {
            showSuccess('Berhasil', 'Subscription berhasil diupgrade');
            showUpgradeDialog.value = false;
            selectedSubscription.value = null;
        },
        onError: (errors) => {
            const firstError = Object.values(errors)[0] as string | undefined;
            showError('Error', firstError || 'Gagal mengupgrade subscription');
        },
    });
};

const handleDelete = async (subscription: Subscription) => {
    const { showDeleteConfirm } = useSweetAlert();
    
    const confirmed = await showDeleteConfirm(
        'Hapus Subscription?',
        `Apakah Anda yakin ingin menghapus subscription untuk "${subscription.tenant?.name || 'Unknown'}"? Tindakan ini tidak dapat dibatalkan.`
    );
    
    if (confirmed) {
        router.delete(`/superadmin/subscriptions/${subscription.id}`, {
            onSuccess: () => {
                showSuccess('Berhasil', 'Subscription berhasil dihapus');
            },
            onError: () => {
                showError('Error', 'Gagal menghapus subscription');
            },
        });
    }
};
</script>

<template>
    <Head title="Subscription Management" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
            <div>
                <h1 class="text-3xl font-bold">Subscription Management</h1>
                <p class="text-muted-foreground mt-1">Kelola paket langganan pengguna</p>
            </div>

            <!-- Filters -->
            <div class="rounded-lg border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div>
                        <Label for="search">Cari</Label>
                        <Input
                            id="search"
                            v-model="searchForm.search"
                            type="text"
                            placeholder="Cari tenant..."
                            class="mt-1"
                            @keyup.enter="applyFilters"
                        />
                    </div>
                    <div>
                        <Label for="status">Status</Label>
                        <select
                            id="status"
                            v-model="searchForm.status"
                            class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="">Semua Status</option>
                            <option value="active">Active</option>
                            <option value="pending">Pending</option>
                            <option value="expired">Expired</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div>
                        <Label for="tenant_id">Tenant</Label>
                        <select
                            id="tenant_id"
                            v-model="searchForm.tenant_id"
                            class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="">Semua Tenant</option>
                            <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">
                                {{ tenant.name }}
                            </option>
                        </select>
                    </div>
                    <div class="flex items-end">
                        <Button @click="applyFilters" class="w-full text-white hover:opacity-90 transition-opacity" style="background-color: oklch(0.65 0.19 137.46);">
                            <Search class="mr-2 h-4 w-4" />
                            Filter
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Tabs Navigation -->
            <div class="rounded-lg border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                <div class="border-b border-sidebar-border/70">
                    <nav class="flex">
                        <button
                            @click="activeTab = 'premium'"
                            :class="[
                                'flex-1 px-6 py-4 text-sm font-medium transition-colors border-b-2',
                                activeTab === 'premium'
                                    ? 'border-emerald-500 text-emerald-600 dark:text-emerald-400 bg-emerald-50/50 dark:bg-emerald-900/10'
                                    : 'border-transparent text-muted-foreground hover:text-foreground hover:bg-accent/50'
                            ]"
                        >
                            <div class="flex items-center justify-center gap-2">
                                <span class="inline-flex items-center justify-center min-w-6 h-6 px-2 rounded-full text-xs font-bold"
                                    :class="activeTab === 'premium' ? 'bg-emerald-500 text-white' : 'bg-muted text-muted-foreground'"
                                >
                                    {{ premiumSubscriptions.length }}
                                </span>
                                Premium
                            </div>
                        </button>
                        <button
                            @click="activeTab = 'free'"
                            :class="[
                                'flex-1 px-6 py-4 text-sm font-medium transition-colors border-b-2',
                                activeTab === 'free'
                                    ? 'border-cyan-500 text-cyan-600 dark:text-cyan-400 bg-cyan-50/50 dark:bg-cyan-900/10'
                                    : 'border-transparent text-muted-foreground hover:text-foreground hover:bg-accent/50'
                            ]"
                        >
                            <div class="flex items-center justify-center gap-2">
                                <span class="inline-flex items-center justify-center min-w-6 h-6 px-2 rounded-full text-xs font-bold"
                                    :class="activeTab === 'free' ? 'bg-cyan-500 text-white' : 'bg-muted text-muted-foreground'"
                                >
                                    {{ freeSubscriptions.meta?.total || 0 }}
                                </span>
                                Free Trial
                            </div>
                        </button>
                    </nav>
                </div>

                <!-- Premium Table -->
                <div v-show="activeTab === 'premium'" class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="border-b border-sidebar-border/70 bg-muted/30">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Tenant</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Plan</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Durasi</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Harga</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Berakhir</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sidebar-border/70">
                            <tr v-for="subscription in premiumSubscriptions" :key="subscription.id" class="hover:bg-accent/50">
                                <td class="px-6 py-4 whitespace-nowrap font-medium">{{ subscription.tenant?.name || '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span :class="getPlanColor(subscription.plan)" class="px-2 py-1 text-xs font-medium rounded">
                                        {{ formatPlan(subscription.plan) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground">{{ subscription.duration_months }} bulan</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground">{{ formatCurrency(subscription.price) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span :class="getStatusColor(subscription.status)" class="px-2 py-1 text-xs font-medium rounded">
                                        {{ subscription.status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground">{{ formatDate(subscription.ends_at) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <div class="flex items-center gap-2">
                                        <Button 
                                            v-if="subscription.status !== 'cancelled'"
                                            variant="outline"
                                            size="sm"
                                            @click="openUpgradeDialog(subscription)"
                                            title="Ubah Plan"
                                            class="text-emerald-600 hover:text-emerald-700 hover:border-emerald-300"
                                        >
                                            <CreditCard class="h-4 w-4 mr-1" />
                                            Ubah Plan
                                        </Button>
                                        <Button 
                                            v-if="subscription.payment_proof" 
                                            variant="outline" 
                                            size="sm" 
                                            @click="viewPaymentProof(subscription)"
                                            title="Lihat Bukti Pembayaran"
                                        >
                                            <Eye class="h-4 w-4" />
                                        </Button>
                                        <Button variant="outline" size="sm" @click="openEditDialog(subscription)">
                                            <Edit class="h-4 w-4" />
                                        </Button>
                                        <Button v-if="subscription.status === 'active'" variant="outline" size="sm" @click="openExtendDialog(subscription)">
                                            <Calendar class="h-4 w-4" />
                                        </Button>
                                        <Button 
                                            variant="outline" 
                                            size="sm" 
                                            @click="handleDelete(subscription)"
                                            title="Hapus Subscription"
                                            class="text-red-500 hover:text-red-700 hover:border-red-300"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="premiumSubscriptions.length === 0">
                                <td colspan="7" class="px-6 py-12 text-center text-muted-foreground">
                                    <div class="flex flex-col items-center gap-2">
                                        <CreditCard class="h-8 w-8 opacity-50" />
                                        <p>Belum ada user premium</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Free Table -->
                <div v-show="activeTab === 'free'" class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="border-b border-sidebar-border/70 bg-muted/30">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Tenant</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Plan</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Berakhir</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sidebar-border/70">
                            <tr v-for="subscription in freeSubscriptions.data" :key="subscription.id" class="hover:bg-accent/50">
                                <td class="px-6 py-4 whitespace-nowrap font-medium">{{ subscription.tenant?.name || '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span :class="getPlanColor(subscription.plan)" class="px-2 py-1 text-xs font-medium rounded">
                                        {{ formatPlan(subscription.plan) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span :class="getStatusColor(subscription.status)" class="px-2 py-1 text-xs font-medium rounded">
                                        {{ subscription.status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground">{{ formatDate(subscription.ends_at) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <div class="flex items-center gap-2">
                                        <Button 
                                            variant="outline"
                                            size="sm"
                                            @click="openUpgradeDialog(subscription)"
                                            title="Ubah Plan / Upgrade ke Premium"
                                            class="text-emerald-600 hover:text-emerald-700 hover:border-emerald-300"
                                        >
                                            <CreditCard class="h-4 w-4 mr-1" />
                                            Ubah Plan
                                        </Button>
                                        <Button variant="outline" size="sm" @click="openEditDialog(subscription)">
                                            <Edit class="h-4 w-4" />
                                        </Button>
                                        <Button 
                                            variant="outline" 
                                            size="sm" 
                                            @click="handleDelete(subscription)"
                                            title="Hapus Subscription"
                                            class="text-red-500 hover:text-red-700 hover:border-red-300"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="freeSubscriptions.data.length === 0">
                                <td colspan="5" class="px-6 py-12 text-center text-muted-foreground">
                                    <div class="flex flex-col items-center gap-2">
                                        <CreditCard class="h-8 w-8 opacity-50" />
                                        <p>Belum ada user free trial</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Free Pagination -->
                <div v-if="activeTab === 'free' && freeSubscriptions.links && freeSubscriptions.links.length > 3" class="border-t border-sidebar-border/70 px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-muted-foreground">
                            Menampilkan {{ freeSubscriptions.meta?.from || 0 }} - {{ freeSubscriptions.meta?.to || 0 }} dari {{ freeSubscriptions.meta?.total || 0 }}
                        </div>
                        <div class="flex items-center gap-2">
                            <Link
                                v-for="link in freeSubscriptions.links"
                                :key="link.label"
                                :href="link.url ? (link.url + (link.url.includes('?') ? '&' : '?') + 'tab=free') : '#'"
                                :class="[
                                    'rounded px-3 py-1 text-sm',
                                    link.active
                                        ? 'text-white'
                                        : 'bg-card hover:bg-accent',
                                    !link.url ? 'cursor-not-allowed opacity-50' : ''
                                ]"
                                :style="link.active ? 'background-color: oklch(0.65 0.19 137.46);' : ''"
                            >
                                <span v-html="link.label" />
                            </Link>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Ubah Plan / Upgrade Dialog -->
            <Dialog v-model:open="showUpgradeDialog">
                <DialogContent class="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Ubah Plan</DialogTitle>
                        <DialogDescription>
                            {{ selectedSubscription?.plan === 'free' ? 'Ubah dari trial ke paket berbayar dan tentukan periode langganan.' : 'Ganti paket langganan, durasi, dan status. Harga bisa disesuaikan.' }}
                        </DialogDescription>
                    </DialogHeader>
                    <form @submit.prevent="handleUpgrade" class="space-y-4">
                        <div class="rounded-lg border border-sidebar-border/70 bg-muted/40 p-4 text-sm dark:border-sidebar-border">
                            <p class="font-medium">Tenant: {{ selectedSubscription?.tenant?.name || '-' }}</p>
                            <p class="text-muted-foreground">
                                Paket saat ini: <span class="font-semibold">{{ selectedSubscription ? formatPlan(selectedSubscription.plan) : '-' }}</span>
                            </p>
                        </div>

                        <div>
                            <Label for="upgrade_plan">Paket Baru</Label>
                            <select
                                id="upgrade_plan"
                                v-model="upgradeForm.plan"
                                class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                required
                            >
                                <option disabled value="">Pilih paket</option>
                                <option v-for="plan in planOptions" :key="plan.value" :value="plan.value">
                                    {{ plan.label }}
                                </option>
                            </select>
                            <InputError :message="upgradeForm.errors.plan" />
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <Label for="upgrade_duration">Durasi</Label>
                                <select
                                    id="upgrade_duration"
                                    v-model.number="upgradeForm.duration_months"
                                    class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                    required
                                >
                                    <option disabled value="">Pilih durasi</option>
                                    <option v-for="duration in durationOptions" :key="duration.value" :value="duration.value">
                                        {{ duration.label }}
                                    </option>
                                </select>
                                <InputError :message="upgradeForm.errors.duration_months" />
                            </div>
                            <div>
                                <Label for="upgrade_status">Status</Label>
                                <select
                                    id="upgrade_status"
                                    v-model="upgradeForm.status"
                                    class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                >
                                    <option v-for="status in statusOptions" :key="status.value" :value="status.value">
                                        {{ status.label }}
                                    </option>
                                </select>
                                <InputError :message="upgradeForm.errors.status" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <Label for="upgrade_price">Harga Total</Label>
                                <Input
                                    id="upgrade_price"
                                    v-model.number="upgradeForm.price"
                                    type="number"
                                    min="0"
                                    step="1000"
                                    placeholder="Masukkan harga"
                                />
                                <InputError :message="upgradeForm.errors.price" />
                                <p class="text-xs text-muted-foreground mt-1">
                                    Tampilan: {{ formatCurrency(Number(upgradeForm.price) || 0) }}
                                    · Standar: {{ formatCurrency(suggestedPrice) }}
                                    <Button type="button" variant="link" class="h-auto p-0 text-xs ml-1" @click="applySuggestedPrice">
                                        Pakai standar
                                    </Button>
                                </p>
                            </div>
                            <div>
                                <Label for="upgrade_starts_at">Tanggal Mulai</Label>
                                <Input
                                    id="upgrade_starts_at"
                                    v-model="upgradeForm.starts_at"
                                    type="date"
                                    required
                                />
                                <InputError :message="upgradeForm.errors.starts_at" />
                                <p v-if="upgradePreviewEndsAt" class="text-xs text-muted-foreground mt-1">
                                    Perkiraan berakhir: {{ upgradePreviewEndsAt }}
                                </p>
                            </div>
                        </div>

                        <div>
                            <Label for="upgrade_notes">Catatan</Label>
                            <textarea
                                id="upgrade_notes"
                                v-model="upgradeForm.notes"
                                rows="3"
                                class="mt-1 flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                                placeholder="Catatan tambahan (opsional)"
                            ></textarea>
                            <InputError :message="upgradeForm.errors.notes" />
                        </div>

                        <div class="flex justify-end gap-2">
                            <Button type="button" variant="outline" @click="showUpgradeDialog = false">
                                Batal
                            </Button>
                            <Button type="submit" :disabled="upgradeForm.processing" class="text-white hover:opacity-90 transition-opacity disabled:opacity-50" style="background-color: oklch(0.65 0.19 137.46);">
                                {{ upgradeForm.processing ? 'Memproses...' : 'Simpan Ubah Plan' }}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            <!-- Edit Dialog -->
            <Dialog v-model:open="showEditDialog">
                <DialogContent class="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Edit Subscription</DialogTitle>
                        <DialogDescription>Edit status dan tanggal subscription</DialogDescription>
                    </DialogHeader>
                    <form @submit.prevent="handleUpdate" class="space-y-4">
                        <!-- Payment Proof Display -->
                        <div v-if="selectedSubscription?.payment_proof" class="rounded-lg border border-sidebar-border/70 p-4 bg-muted/50 dark:border-sidebar-border">
                            <Label class="mb-2 block text-sm font-medium">Bukti Pembayaran</Label>
                            <div class="space-y-2">
                                <Button 
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    @click="viewPaymentProof(selectedSubscription)"
                                    class="inline-flex items-center gap-2"
                                >
                                    <Eye class="w-4 h-4" />
                                    Lihat Bukti Pembayaran
                                </Button>
                                <p class="text-xs text-muted-foreground">
                                    Klik untuk melihat bukti pembayaran yang diupload oleh user
                                </p>
                            </div>
                        </div>
                        <div v-else class="rounded-lg border border-sidebar-border/70 p-4 bg-muted/50 dark:border-sidebar-border">
                            <p class="text-sm text-muted-foreground">
                                Belum ada bukti pembayaran yang diupload
                            </p>
                        </div>

                        <div>
                            <Label for="edit_status">Status</Label>
                            <select
                                id="edit_status"
                                v-model="editForm.status"
                                class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                required
                            >
                                <option value="pending">Pending</option>
                                <option value="active">Active</option>
                                <option value="expired">Expired</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                            <InputError :message="editForm.errors.status" />
                        </div>
                        <div>
                            <Label for="edit_starts_at">Tanggal Mulai</Label>
                            <Input id="edit_starts_at" v-model="editForm.starts_at" type="date" />
                            <InputError :message="editForm.errors.starts_at" />
                        </div>
                        <div>
                            <Label for="edit_ends_at">Tanggal Berakhir</Label>
                            <Input id="edit_ends_at" v-model="editForm.ends_at" type="date" />
                            <InputError :message="editForm.errors.ends_at" />
                        </div>
                        <div class="flex justify-end gap-2">
                            <Button type="button" variant="outline" @click="showEditDialog = false">
                                Batal
                            </Button>
                            <Button type="submit" :disabled="editForm.processing" class="text-white hover:opacity-90 transition-opacity disabled:opacity-50" style="background-color: oklch(0.65 0.19 137.46);">
                                {{ editForm.processing ? 'Menyimpan...' : 'Simpan' }}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            <!-- Extend Dialog -->
            <Dialog v-model:open="showExtendDialog">
                <DialogContent class="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Perpanjang Subscription</DialogTitle>
                        <DialogDescription>Tambahkan durasi subscription</DialogDescription>
                    </DialogHeader>
                    <form @submit.prevent="handleExtend" class="space-y-4">
                        <div>
                            <Label for="extend_months">Tambahkan Bulan</Label>
                            <Input id="extend_months" v-model.number="extendForm.months" type="number" min="1" max="12" required />
                            <InputError :message="extendForm.errors.months" />
                        </div>
                        <div class="flex justify-end gap-2">
                            <Button type="button" variant="outline" @click="showExtendDialog = false">
                                Batal
                            </Button>
                            <Button type="submit" :disabled="extendForm.processing" class="text-white hover:opacity-90 transition-opacity disabled:opacity-50" style="background-color: oklch(0.65 0.19 137.46);">
                                {{ extendForm.processing ? 'Memproses...' : 'Perpanjang' }}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            <!-- Payment Proof Dialog -->
            <Dialog v-model:open="showPaymentProofDialog">
                <DialogContent class="max-w-4xl">
                    <DialogHeader>
                        <DialogTitle>Bukti Pembayaran</DialogTitle>
                        <DialogDescription>Bukti transfer pembayaran dari tenant</DialogDescription>
                    </DialogHeader>
                    <div v-if="paymentProofUrl" class="space-y-4">
                        <div class="relative rounded-lg border border-sidebar-border/70 bg-muted/30 p-4 dark:border-sidebar-border">
                            <img 
                                :src="paymentProofUrl" 
                                alt="Bukti Pembayaran" 
                                class="w-full h-auto rounded-lg border max-h-[70vh] object-contain mx-auto" 
                            />
                        </div>
                        <div class="flex justify-between items-center">
                            <a
                                :href="paymentProofUrl"
                                target="_blank"
                                class="text-sm text-primary hover:underline inline-flex items-center gap-2"
                            >
                                <Eye class="w-4 h-4" />
                                Buka di tab baru
                            </a>
                            <Button 
                                variant="outline" 
                                @click="showPaymentProofDialog = false"
                            >
                                Tutup
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>

