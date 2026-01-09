<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Users, Plus, Edit, Trash2, Search, Filter } from 'lucide-vue-next';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { useSweetAlert } from '@/composables/useSweetAlert';
import superadminRoutes from '@/routes/superadmin/index';

const superadmin = {
    users: {
        index: () => superadminRoutes.users.index.url(),
        store: () => superadminRoutes.users.store.url(),
        update: (id: number) => superadminRoutes.users.update.url({ user: id }),
        destroy: (id: number) => superadminRoutes.users.destroy.url({ user: id }),
    },
};

interface User {
    id: number;
    name: string;
    email: string;
    whatsapp_number: string | null;
    is_super_admin: boolean;
    tenant: {
        id: number;
        name: string;
    } | null;
    role: {
        id: number;
        name: string;
    } | null;
    created_at: string;
}

interface Tenant {
    id: number;
    name: string;
}

interface Props {
    users: {
        data: User[];
        links: any;
        meta: any;
    };
    tenants: Tenant[];
    filters: {
        search?: string;
        tenant_id?: string;
        is_super_admin?: string;
    };
}

const props = defineProps<Props>();
const { showError, showSuccess, showDeleteConfirm } = useSweetAlert();

const showCreateDialog = ref(false);
const showEditDialog = ref(false);
const selectedUser = ref<User | null>(null);

const searchForm = useForm({
    search: props.filters.search || '',
    tenant_id: props.filters.tenant_id || '',
    is_super_admin: props.filters.is_super_admin || '',
});

const createForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    whatsapp_number: '',
    tenant_id: '',
    is_super_admin: false,
});

const editForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    whatsapp_number: '',
    tenant_id: '',
    is_super_admin: false,
});

const applyFilters = () => {
    searchForm.get(superadmin.users.index(), {
        preserveState: true,
        preserveScroll: true,
    });
};

const openCreateDialog = () => {
    createForm.reset();
    createForm.clearErrors();
    showCreateDialog.value = true;
};

const openEditDialog = (user: User) => {
    selectedUser.value = user;
    editForm.name = user.name;
    editForm.email = user.email;
    editForm.whatsapp_number = user.whatsapp_number || '';
    editForm.tenant_id = user.tenant?.id?.toString() || '';
    editForm.is_super_admin = user.is_super_admin;
    editForm.password = '';
    editForm.password_confirmation = '';
    editForm.clearErrors();
    showEditDialog.value = true;
};

const handleCreate = () => {
    createForm.post(superadmin.users.store(), {
        onSuccess: () => {
            showSuccess('Berhasil', 'User berhasil dibuat');
            showCreateDialog.value = false;
            createForm.reset();
        },
        onError: (errors) => {
            showError('Error', 'Gagal membuat user. Silakan periksa form.');
        },
    });
};

const handleUpdate = () => {
    if (!selectedUser.value) return;
    
    editForm.put(superadmin.users.update(selectedUser.value.id), {
        onSuccess: () => {
            showSuccess('Berhasil', 'User berhasil diperbarui');
            showEditDialog.value = false;
            selectedUser.value = null;
        },
        onError: (errors) => {
            showError('Error', 'Gagal memperbarui user. Silakan periksa form.');
        },
    });
};

const handleDelete = async (user: User) => {
    const confirmed = await showDeleteConfirm(
        'Hapus User',
        `Apakah Anda yakin ingin menghapus user ${user.name}? Tindakan ini tidak dapat dibatalkan.`
    );
    
    if (confirmed) {
        router.delete(superadmin.users.destroy(user.id), {
            onSuccess: () => {
                showSuccess('Berhasil', 'User berhasil dihapus');
            },
            onError: () => {
                showError('Error', 'Gagal menghapus user');
            },
        });
    }
};
</script>

<template>
    <Head title="User Management" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold">User Management</h1>
                    <p class="text-muted-foreground mt-1">Kelola semua pengguna aplikasi</p>
                </div>
                <Button @click="openCreateDialog" class="text-white hover:opacity-90 transition-opacity" style="background-color: oklch(0.65 0.19 137.46);">
                    <Plus class="mr-2 h-4 w-4" />
                    Tambah User
                </Button>
            </div>

            <!-- Filters -->
            <div class="rounded-lg border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <Label for="search">Cari</Label>
                        <Input
                            id="search"
                            v-model="searchForm.search"
                            type="text"
                            placeholder="Cari nama, email..."
                            class="mt-1"
                            @keyup.enter="applyFilters"
                        />
                    </div>
                    <div>
                        <Label for="tenant_id">Tenant</Label>
                        <select
                            id="tenant_id"
                            v-model="searchForm.tenant_id"
                            class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <option value="">Semua Tenant</option>
                            <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">
                                {{ tenant.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <Label for="is_super_admin">Role</Label>
                        <select
                            id="is_super_admin"
                            v-model="searchForm.is_super_admin"
                            class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <option value="">Semua Role</option>
                            <option value="1">Super Admin</option>
                            <option value="0">User Biasa</option>
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

            <!-- Users Table -->
            <div class="rounded-lg border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="border-b border-sidebar-border/70">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Nama</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Email</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">WhatsApp</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Tenant</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Role</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sidebar-border/70">
                            <tr v-for="user in users.data" :key="user.id" class="hover:bg-accent/50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium">{{ user.name }}</span>
                                        <span v-if="user.is_super_admin" class="px-2 py-1 text-xs font-medium rounded bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400">
                                            Super Admin
                                        </span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground">{{ user.email }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground">{{ user.whatsapp_number || '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground">{{ user.tenant?.name || '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-muted-foreground">{{ user.role?.name || '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <div class="flex items-center gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            @click="openEditDialog(user)"
                                        >
                                            <Edit class="h-4 w-4" />
                                        </Button>
                                        <Button
                                            v-if="!user.is_super_admin"
                                            variant="outline"
                                            size="sm"
                                            @click="handleDelete(user)"
                                        >
                                            <Trash2 class="h-4 w-4 text-red-600" />
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="users.data.length === 0">
                                <td colspan="6" class="px-6 py-4 text-center text-muted-foreground">
                                    Tidak ada user ditemukan
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="users.links && users.links.length > 3" class="border-t border-sidebar-border/70 px-6 py-4">
                    <div class="flex items-center justify-center gap-2">
                        <Link
                            v-for="link in users.links"
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
                        <DialogTitle>Tambah User</DialogTitle>
                        <DialogDescription>Buat user baru untuk aplikasi</DialogDescription>
                    </DialogHeader>
                    <form @submit.prevent="handleCreate" class="space-y-4">
                        <div>
                            <Label for="create_name">Nama</Label>
                            <Input id="create_name" v-model="createForm.name" type="text" required />
                            <InputError :message="createForm.errors.name" />
                        </div>
                        <div>
                            <Label for="create_email">Email</Label>
                            <Input id="create_email" v-model="createForm.email" type="email" required />
                            <InputError :message="createForm.errors.email" />
                        </div>
                        <div>
                            <Label for="create_password">Password</Label>
                            <Input id="create_password" v-model="createForm.password" type="password" required />
                            <InputError :message="createForm.errors.password" />
                        </div>
                        <div>
                            <Label for="create_password_confirmation">Konfirmasi Password</Label>
                            <Input id="create_password_confirmation" v-model="createForm.password_confirmation" type="password" required />
                            <InputError :message="createForm.errors.password_confirmation" />
                        </div>
                        <div>
                            <Label for="create_whatsapp_number">WhatsApp Number</Label>
                            <Input id="create_whatsapp_number" v-model="createForm.whatsapp_number" type="text" />
                            <InputError :message="createForm.errors.whatsapp_number" />
                        </div>
                        <div>
                            <Label for="create_tenant_id">Tenant</Label>
                            <select
                                id="create_tenant_id"
                                v-model="createForm.tenant_id"
                                class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option value="">Pilih Tenant</option>
                                <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">
                                    {{ tenant.name }}
                                </option>
                            </select>
                            <InputError :message="createForm.errors.tenant_id" />
                        </div>
                        <div class="flex items-center gap-2">
                            <input
                                id="create_is_super_admin"
                                v-model="createForm.is_super_admin"
                                type="checkbox"
                                class="rounded border-gray-300"
                            />
                            <Label for="create_is_super_admin">Super Admin</Label>
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
                        <DialogTitle>Edit User</DialogTitle>
                        <DialogDescription>Edit informasi user</DialogDescription>
                    </DialogHeader>
                    <form @submit.prevent="handleUpdate" class="space-y-4">
                        <div>
                            <Label for="edit_name">Nama</Label>
                            <Input id="edit_name" v-model="editForm.name" type="text" required />
                            <InputError :message="editForm.errors.name" />
                        </div>
                        <div>
                            <Label for="edit_email">Email</Label>
                            <Input id="edit_email" v-model="editForm.email" type="email" required />
                            <InputError :message="editForm.errors.email" />
                        </div>
                        <div>
                            <Label for="edit_password">Password (Kosongkan jika tidak ingin mengubah)</Label>
                            <Input id="edit_password" v-model="editForm.password" type="password" />
                            <InputError :message="editForm.errors.password" />
                        </div>
                        <div>
                            <Label for="edit_password_confirmation">Konfirmasi Password</Label>
                            <Input id="edit_password_confirmation" v-model="editForm.password_confirmation" type="password" />
                            <InputError :message="editForm.errors.password_confirmation" />
                        </div>
                        <div>
                            <Label for="edit_whatsapp_number">WhatsApp Number</Label>
                            <Input id="edit_whatsapp_number" v-model="editForm.whatsapp_number" type="text" />
                            <InputError :message="editForm.errors.whatsapp_number" />
                        </div>
                        <div>
                            <Label for="edit_tenant_id">Tenant</Label>
                            <select
                                id="edit_tenant_id"
                                v-model="editForm.tenant_id"
                                class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option value="">Pilih Tenant</option>
                                <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">
                                    {{ tenant.name }}
                                </option>
                            </select>
                            <InputError :message="editForm.errors.tenant_id" />
                        </div>
                        <div class="flex items-center gap-2">
                            <input
                                id="edit_is_super_admin"
                                v-model="editForm.is_super_admin"
                                type="checkbox"
                                class="rounded border-gray-300"
                            />
                            <Label for="edit_is_super_admin">Super Admin</Label>
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

