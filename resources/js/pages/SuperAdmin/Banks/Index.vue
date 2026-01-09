<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Wallet, Plus, Edit, Trash2, Search } from 'lucide-vue-next';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { useSweetAlert } from '@/composables/useSweetAlert';
import superadminRoutes from '@/routes/superadmin/index';

const superadmin = {
    banks: {
        index: () => superadminRoutes.banks.index.url(),
        store: () => superadminRoutes.banks.store.url(),
        update: (id: number) => superadminRoutes.banks.update.url({ bank: id }),
        destroy: (id: number) => superadminRoutes.banks.destroy.url({ bank: id }),
    },
};

interface Bank {
    id: number;
    name: string;
    account_number: string;
    account_name: string;
    description: string | null;
    is_active: boolean;
    created_at: string;
}

interface Props {
    banks: {
        data: Bank[];
        links: any;
        meta: any;
    };
    filters: {
        search?: string;
        is_active?: string;
    };
}

const props = defineProps<Props>();
const { showError, showSuccess, showDeleteConfirm } = useSweetAlert();

const showCreateDialog = ref(false);
const showEditDialog = ref(false);
const selectedBank = ref<Bank | null>(null);

const searchForm = useForm({
    search: props.filters.search || '',
    is_active: props.filters.is_active || '',
});

const createForm = useForm({
    name: '',
    account_number: '',
    account_name: '',
    description: '',
    is_active: true,
});

const editForm = useForm({
    name: '',
    account_number: '',
    account_name: '',
    description: '',
    is_active: true,
});

const applyFilters = () => {
    searchForm.get(superadmin.banks.index(), {
        preserveState: true,
        preserveScroll: true,
    });
};

const openCreateDialog = () => {
    createForm.reset();
    createForm.clearErrors();
    showCreateDialog.value = true;
};

const openEditDialog = (bank: Bank) => {
    selectedBank.value = bank;
    editForm.name = bank.name;
    editForm.account_number = bank.account_number;
    editForm.account_name = bank.account_name;
    editForm.description = bank.description || '';
    editForm.is_active = bank.is_active;
    editForm.clearErrors();
    showEditDialog.value = true;
};

const handleCreate = () => {
    createForm.post(superadmin.banks.store(), {
        onSuccess: () => {
            showSuccess('Berhasil', 'Bank berhasil ditambahkan');
            showCreateDialog.value = false;
            createForm.reset();
        },
        onError: () => {
            showError('Error', 'Gagal menambahkan bank');
        },
    });
};

const handleUpdate = () => {
    if (!selectedBank.value) return;
    
    editForm.put(superadmin.banks.update(selectedBank.value.id), {
        onSuccess: () => {
            showSuccess('Berhasil', 'Bank berhasil diperbarui');
            showEditDialog.value = false;
            selectedBank.value = null;
        },
        onError: () => {
            showError('Error', 'Gagal memperbarui bank');
        },
    });
};

const handleDelete = (bank: Bank) => {
    showDeleteConfirm('Hapus Bank', `Apakah Anda yakin ingin menghapus bank ${bank.name}?`, () => {
        router.delete(superadmin.banks.destroy(bank.id), {
            onSuccess: () => {
                showSuccess('Berhasil', 'Bank berhasil dihapus');
            },
            onError: () => {
                showError('Error', 'Gagal menghapus bank');
            },
        });
    });
};
</script>

<template>
    <Head title="Bank Management" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold">Bank Management</h1>
                    <p class="text-muted-foreground mt-1">Kelola rekening bank untuk pembayaran manual</p>
                </div>
                <Button @click="openCreateDialog" class="text-white hover:opacity-90 transition-opacity" style="background-color: oklch(0.65 0.19 137.46);">
                    <Plus class="mr-2 h-4 w-4" />
                    Tambah Bank
                </Button>
            </div>

            <!-- Filters -->
            <div class="rounded-lg border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <Label for="search">Cari</Label>
                        <Input
                            id="search"
                            v-model="searchForm.search"
                            type="text"
                            placeholder="Cari nama bank, rekening..."
                            class="mt-1"
                            @keyup.enter="applyFilters"
                        />
                    </div>
                    <div>
                        <Label for="is_active">Status</Label>
                        <select
                            id="is_active"
                            v-model="searchForm.is_active"
                            class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="">Semua Status</option>
                            <option value="1">Aktif</option>
                            <option value="0">Tidak Aktif</option>
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

            <!-- Banks Table -->
            <div class="rounded-lg border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="border-b border-sidebar-border/70">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Nama Bank</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Nomor Rekening</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Nama Pemilik</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Keterangan</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sidebar-border/70">
                            <tr v-for="bank in banks.data" :key="bank.id" class="hover:bg-accent/50">
                                <td class="px-6 py-4 whitespace-nowrap font-medium">{{ bank.name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground">{{ bank.account_number }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground">{{ bank.account_name }}</td>
                                <td class="px-6 py-4 text-sm text-muted-foreground">{{ bank.description || '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span
                                        :class="bank.is_active ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-700 dark:bg-gray-900/30 dark:text-gray-400'"
                                        class="px-2 py-1 text-xs font-medium rounded"
                                    >
                                        {{ bank.is_active ? 'Aktif' : 'Tidak Aktif' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <div class="flex items-center gap-2">
                                        <Button variant="outline" size="sm" @click="openEditDialog(bank)">
                                            <Edit class="h-4 w-4" />
                                        </Button>
                                        <Button variant="outline" size="sm" @click="handleDelete(bank)">
                                            <Trash2 class="h-4 w-4 text-red-600" />
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="banks.data.length === 0">
                                <td colspan="6" class="px-6 py-4 text-center text-muted-foreground">
                                    Tidak ada bank ditemukan
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="banks.links && banks.links.length > 3" class="border-t border-sidebar-border/70 px-6 py-4">
                    <div class="flex items-center justify-center gap-2">
                        <Link
                            v-for="link in banks.links"
                            :key="link.label"
                            :href="link.url || '#'"
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

            <!-- Create Dialog -->
            <Dialog v-model:open="showCreateDialog">
                <DialogContent class="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Tambah Bank</DialogTitle>
                        <DialogDescription>Tambahkan rekening bank baru</DialogDescription>
                    </DialogHeader>
                    <form @submit.prevent="handleCreate" class="space-y-4">
                        <div>
                            <Label for="create_name">Nama Bank</Label>
                            <Input id="create_name" v-model="createForm.name" type="text" required />
                            <InputError :message="createForm.errors.name" />
                        </div>
                        <div>
                            <Label for="create_account_number">Nomor Rekening</Label>
                            <Input id="create_account_number" v-model="createForm.account_number" type="text" required />
                            <InputError :message="createForm.errors.account_number" />
                        </div>
                        <div>
                            <Label for="create_account_name">Nama Pemilik Rekening</Label>
                            <Input id="create_account_name" v-model="createForm.account_name" type="text" required />
                            <InputError :message="createForm.errors.account_name" />
                        </div>
                        <div>
                            <Label for="create_description">Keterangan</Label>
                            <textarea
                                id="create_description"
                                v-model="createForm.description"
                                class="mt-1 flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                            />
                            <InputError :message="createForm.errors.description" />
                        </div>
                        <div class="flex items-center gap-2">
                            <input
                                id="create_is_active"
                                v-model="createForm.is_active"
                                type="checkbox"
                                class="rounded border-gray-300"
                            />
                            <Label for="create_is_active">Aktif</Label>
                        </div>
                        <div class="flex justify-end gap-2">
                            <Button type="button" variant="outline" @click="showCreateDialog = false">
                                Batal
                            </Button>
                            <Button type="submit" :disabled="createForm.processing" class="text-white hover:opacity-90 transition-opacity disabled:opacity-50" style="background-color: oklch(0.65 0.19 137.46);">
                                {{ createForm.processing ? 'Menyimpan...' : 'Simpan' }}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            <!-- Edit Dialog -->
            <Dialog v-model:open="showEditDialog">
                <DialogContent class="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Edit Bank</DialogTitle>
                        <DialogDescription>Edit informasi bank</DialogDescription>
                    </DialogHeader>
                    <form @submit.prevent="handleUpdate" class="space-y-4">
                        <div>
                            <Label for="edit_name">Nama Bank</Label>
                            <Input id="edit_name" v-model="editForm.name" type="text" required />
                            <InputError :message="editForm.errors.name" />
                        </div>
                        <div>
                            <Label for="edit_account_number">Nomor Rekening</Label>
                            <Input id="edit_account_number" v-model="editForm.account_number" type="text" required />
                            <InputError :message="editForm.errors.account_number" />
                        </div>
                        <div>
                            <Label for="edit_account_name">Nama Pemilik Rekening</Label>
                            <Input id="edit_account_name" v-model="editForm.account_name" type="text" required />
                            <InputError :message="editForm.errors.account_name" />
                        </div>
                        <div>
                            <Label for="edit_description">Keterangan</Label>
                            <textarea
                                id="edit_description"
                                v-model="editForm.description"
                                class="mt-1 flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                            />
                            <InputError :message="editForm.errors.description" />
                        </div>
                        <div class="flex items-center gap-2">
                            <input
                                id="edit_is_active"
                                v-model="editForm.is_active"
                                type="checkbox"
                                class="rounded border-gray-300"
                            />
                            <Label for="edit_is_active">Aktif</Label>
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
        </div>
    </AppLayout>
</template>

