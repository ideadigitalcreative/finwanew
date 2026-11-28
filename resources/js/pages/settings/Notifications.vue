<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem } from '@/types';

import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';

interface Device {
    id: number;
    name: string;
    last_seen: string | null;
}

const props = defineProps<{
    pushEnabled: boolean;
    pushHideAmount: boolean;
    devices: Device[];
    serverEnabled: boolean;
}>();

const breadcrumbItems: BreadcrumbItem[] = [{ title: 'Notifikasi', href: '/settings/notifications' }];

const form = useForm({
    push_enabled: props.pushEnabled,
    push_hide_amount: props.pushHideAmount,
});

const save = () => form.put('/settings/notifications', { preserveScroll: true });

const removeDevice = (id: number) =>
    router.delete(`/settings/notifications/devices/${id}`, { preserveScroll: true });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Notifikasi" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Notifikasi"
                    description="Popup di HP Android setiap ada transaksi baru di akun keuangan Anda"
                />

                <div
                    v-if="!serverEnabled"
                    class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200"
                >
                    Notifikasi push belum diaktifkan di server. Pengaturan tetap disimpan.
                </div>

                <form class="space-y-4" @submit.prevent="save">
                    <label class="flex cursor-pointer items-start gap-3">
                        <input v-model="form.push_enabled" type="checkbox" class="mt-1 h-4 w-4 accent-teal-600" />
                        <span>
                            <span class="block text-sm font-medium">Terima notifikasi transaksi baru</span>
                            <span class="block text-sm text-muted-foreground">
                                Termasuk transaksi yang dicatat anggota lain (keluarga / tim).
                            </span>
                        </span>
                    </label>

                    <label class="flex cursor-pointer items-start gap-3">
                        <input
                            v-model="form.push_hide_amount"
                            type="checkbox"
                            class="mt-1 h-4 w-4 accent-teal-600"
                            :disabled="!form.push_enabled"
                        />
                        <span>
                            <span class="block text-sm font-medium">Sembunyikan nominal</span>
                            <span class="block text-sm text-muted-foreground">
                                Nominal tidak tampil di layar kunci HP.
                            </span>
                        </span>
                    </label>

                    <Button type="submit" :disabled="form.processing">Simpan</Button>
                    <span v-if="form.recentlySuccessful" class="ml-3 text-sm text-muted-foreground">Tersimpan.</span>
                </form>

                <div class="space-y-2">
                    <h3 class="text-sm font-medium">Perangkat terdaftar</h3>
                    <p v-if="devices.length === 0" class="text-sm text-muted-foreground">
                        Belum ada. Buka aplikasi FinWa Android lalu login — perangkat terdaftar otomatis.
                    </p>
                    <ul v-else class="divide-y rounded-md border">
                        <li v-for="d in devices" :key="d.id" class="flex items-center justify-between gap-3 p-3 text-sm">
                            <span>
                                📱 {{ d.name }}
                                <span v-if="d.last_seen" class="block text-xs text-muted-foreground">aktif {{ d.last_seen }}</span>
                            </span>
                            <Button variant="ghost" size="sm" type="button" @click="removeDevice(d.id)">Hapus</Button>
                        </li>
                    </ul>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
