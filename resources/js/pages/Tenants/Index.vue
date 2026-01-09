<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';

interface Tenant {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
    role: {
        id: number;
        name: string;
        slug: string;
    } | null;
    joined_at: string;
    is_current: boolean;
}

interface Props {
    tenants: Tenant[];
    current_tenant_id: number;
}

const props = defineProps<Props>();

const formatDate = (date: string) => {
    return format(new Date(date), 'dd MMMM yyyy', { locale: id });
};

const switchTenant = (tenantId: number) => {
    router.post(`/tenants/${tenantId}/switch`, {}, {
        preserveState: false,
        preserveScroll: false,
        onSuccess: () => {
            // Redirect will be handled by controller
        },
    });
};
</script>

<template>
    <Head title="Tenant" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold">Tenant Saya</h2>
                    <p class="text-sm text-muted-foreground">Kelola dan pindah antar tenant</p>
                </div>
                <Link
                    href="/tenants/invitations"
                    class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                >
                    Invite User
                </Link>
            </div>

            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="tenant in tenants"
                    :key="tenant.id"
                    class="rounded-lg border p-6 transition-all hover:shadow-md"
                    :class="[
                        tenant.is_current
                            ? 'border-primary bg-primary/5 dark:bg-primary/10'
                            : 'border-sidebar-border/70 bg-card dark:border-sidebar-border',
                        !tenant.is_active ? 'opacity-50' : ''
                    ]"
                >
                    <div class="mb-4 flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-semibold">{{ tenant.name }}</h3>
                            <p class="text-sm text-muted-foreground">{{ tenant.slug }}</p>
                        </div>
                        <span
                            v-if="tenant.is_current"
                            class="rounded-full bg-primary px-2 py-1 text-xs font-medium text-primary-foreground"
                        >
                            Aktif
                        </span>
                    </div>

                    <div class="mb-4 space-y-2">
                        <div>
                            <span class="text-xs text-muted-foreground">Role</span>
                            <p class="text-sm font-medium">{{ tenant.role?.name || '-' }}</p>
                        </div>
                        <div>
                            <span class="text-xs text-muted-foreground">Bergabung</span>
                            <p class="text-sm">{{ formatDate(tenant.joined_at) }}</p>
                        </div>
                    </div>

                    <button
                        v-if="!tenant.is_current"
                        @click="switchTenant(tenant.id)"
                        class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                    >
                        Pindah ke Tenant Ini
                    </button>
                    <div
                        v-else
                        class="w-full rounded-lg bg-muted px-4 py-2 text-center text-sm font-medium text-muted-foreground"
                    >
                        Tenant Aktif
                    </div>
                </div>
            </div>

            <div v-if="tenants.length === 0" class="text-center text-muted-foreground">
                <p>Anda belum memiliki tenant. Silakan buat tenant baru saat registrasi.</p>
            </div>
        </div>
    </AppLayout>
</template>

