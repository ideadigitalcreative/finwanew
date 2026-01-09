<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { useSweetAlert } from '@/composables/useSweetAlert';

interface Props {
    invitations: Array<{
        id: number;
        email: string;
        token: string;
        expires_at: string;
        accepted_at: string | null;
        role: {
            id: number;
            name: string;
        } | null;
        inviter: {
            id: number;
            name: string;
        };
        created_at: string;
    }>;
    roles: Array<{
        id: number;
        name: string;
        slug: string;
    }>;
}

const props = defineProps<Props>();
const { showDeleteConfirm, showToast } = useSweetAlert();

const form = useForm({
    email: '',
    role_id: '',
});

const formatDate = (date: string) => {
    return format(new Date(date), 'dd MMM yyyy HH:mm', { locale: id });
};

const isExpired = (expiresAt: string) => {
    return new Date(expiresAt) < new Date();
};

const inviteUser = () => {
    form.post('/tenants/invitations', {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
        },
    });
};

const deleteInvitation = async (invitationId: number) => {
    const confirmed = await showDeleteConfirm(
        'Hapus Invitation?',
        'Apakah Anda yakin ingin menghapus invitation ini?'
    );
    if (confirmed) {
        router.delete(`/tenants/invitations/${invitationId}`, {
            preserveState: true,
            preserveScroll: true,
        });
    }
};

const getInvitationUrl = (token: string) => {
    return `${window.location.origin}/invitations/accept/${token}`;
};

const copyToClipboard = async (text: string) => {
    try {
        await navigator.clipboard.writeText(text);
        showToast('URL berhasil disalin!', 'success');
    } catch (error) {
        showToast('Gagal menyalin URL', 'error');
    }
};
</script>

<template>
    <Head title="Invite User" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
            <div>
                <h2 class="text-2xl font-bold">Invite User ke Tenant</h2>
                <p class="text-sm text-muted-foreground">Undang user lain untuk bergabung ke tenant Anda</p>
            </div>

            <!-- Invite Form -->
            <div class="rounded-lg border border-sidebar-border/70 bg-card p-6 dark:border-sidebar-border">
                <h3 class="mb-4 text-lg font-semibold">Kirim Invitation</h3>
                <form @submit.prevent="inviteUser" class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">Email</label>
                            <input
                                v-model="form.email"
                                type="email"
                                required
                                class="w-full rounded-lg border border-sidebar-border/70 bg-background px-3 py-2 text-sm dark:border-sidebar-border"
                                placeholder="user@example.com"
                            />
                            <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">
                                {{ form.errors.email }}
                            </p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">Role</label>
                            <select
                                v-model="form.role_id"
                                required
                                class="w-full rounded-lg border border-sidebar-border/70 bg-background px-3 py-2 text-sm dark:border-sidebar-border"
                            >
                                <option value="">Pilih Role</option>
                                <option
                                    v-for="role in roles"
                                    :key="role.id"
                                    :value="role.id"
                                >
                                    {{ role.name }}
                                </option>
                            </select>
                            <p v-if="form.errors.role_id" class="mt-1 text-xs text-red-600">
                                {{ form.errors.role_id }}
                            </p>
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                        >
                            {{ form.processing ? 'Mengirim...' : 'Kirim Invitation' }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- Invitations List -->
            <div class="rounded-lg border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                <div class="border-b border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h3 class="text-lg font-semibold">Daftar Invitation</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="border-b border-sidebar-border/70 dark:border-sidebar-border">
                            <tr>
                                <th class="px-4 py-3 text-left text-sm font-medium">Email</th>
                                <th class="px-4 py-3 text-left text-sm font-medium">Role</th>
                                <th class="px-4 py-3 text-left text-sm font-medium">Dikirim Oleh</th>
                                <th class="px-4 py-3 text-left text-sm font-medium">Kadaluarsa</th>
                                <th class="px-4 py-3 text-left text-sm font-medium">Status</th>
                                <th class="px-4 py-3 text-center text-sm font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="invitation in invitations"
                                :key="invitation.id"
                                class="border-b border-sidebar-border/50 dark:border-sidebar-border"
                            >
                                <td class="px-4 py-3 text-sm">{{ invitation.email }}</td>
                                <td class="px-4 py-3 text-sm">{{ invitation.role?.name || '-' }}</td>
                                <td class="px-4 py-3 text-sm">{{ invitation.inviter.name }}</td>
                                <td class="px-4 py-3 text-sm">{{ formatDate(invitation.expires_at) }}</td>
                                <td class="px-4 py-3">
                                    <span
                                        class="rounded-full px-2 py-1 text-xs font-medium"
                                        :class="{
                                            'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400': invitation.accepted_at,
                                            'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400': !invitation.accepted_at && !isExpired(invitation.expires_at),
                                            'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400': !invitation.accepted_at && isExpired(invitation.expires_at),
                                        }"
                                    >
                                        {{
                                            invitation.accepted_at
                                                ? 'Diterima'
                                                : isExpired(invitation.expires_at)
                                                ? 'Kadaluarsa'
                                                : 'Pending'
                                        }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex justify-center gap-2">
                                        <button
                                            v-if="!invitation.accepted_at && !isExpired(invitation.expires_at)"
                                            @click="copyToClipboard(getInvitationUrl(invitation.token))"
                                            class="rounded px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20"
                                            title="Copy invitation URL"
                                        >
                                            Copy URL
                                        </button>
                                        <button
                                            v-if="!invitation.accepted_at"
                                            @click="deleteInvitation(invitation.id)"
                                            class="rounded px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20"
                                        >
                                            Hapus
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="invitations.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-sm text-muted-foreground">
                                    Belum ada invitation
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

