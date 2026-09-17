<script setup lang="ts">
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import PasswordController from '@/actions/App/Http/Controllers/Settings/PasswordController';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import { Form, Head, Link, usePage } from '@inertiajs/vue3';

import DeleteUser from '@/components/DeleteUser.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { ref, computed } from 'vue';

interface Props {
    mustVerifyEmail: boolean;
    status?: string;
}

defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Pengaturan Profil',
        href: edit().url,
    },
];

const page = usePage();
const user = computed(() => page.props.auth.user);

const form = ProfileController.update.form();
const fileInput = ref<HTMLInputElement | null>(null);
const preview = ref<string | null>(null);

const showCurrentPassword = ref(false);
const showNewPassword = ref(false);
const showConfirmPassword = ref(false);

const triggerFileInput = () => {
    fileInput.value?.click();
};

const handleFileChange = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files && target.files[0]) {
        const file = target.files[0];
        (form as any).avatar = file;
        (form as any).forceFormData = true;
        
        // Create preview
        const reader = new FileReader();
        reader.onload = (e) => {
            preview.value = e.target?.result as string;
        };
        reader.readAsDataURL(file);
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Pengaturan Profil - FinWa" />

        <div class="flex flex-col gap-6 p-4 md:p-6 font-['Plus_Jakarta_Sans',sans-serif] min-h-full">
            <!-- Header Section -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl md:text-2xl font-bold text-[#1b1c19] dark:text-white flex items-center gap-2">
                        <span class="material-symbols-outlined text-[#ffd23f] text-2xl md:text-3xl">manage_accounts</span>
                        Pengaturan Profil
                    </h2>
                    <p class="text-xs md:text-sm text-[#4d4634]/60 dark:text-white/50 mt-1">
                        Perbarui identitas profil, kata sandi, dan kelola keamanan akun FinWa Anda
                    </p>
                </div>
            </div>

            <!-- Main Content Grid -->
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 items-start">
                
                <!-- Left Column: Profile & Password Information (Span 2) -->
                <div class="xl:col-span-2 flex flex-col gap-6">
                    
                    <!-- 1. Profile Info Card (Gaya Finwa Segar) -->
                    <div class="relative overflow-hidden rounded-2xl bg-white dark:bg-[#23231f] border border-[#eae8e2] dark:border-white/10 p-5 md:p-6 shadow-[0_4px_20px_rgb(0,0,0,0.03)] dark:shadow-none transition-all">
                        <!-- Decorative Backdrop Glow Lembut -->
                        <div class="absolute -right-10 -top-10 w-36 h-36 rounded-full bg-[#ffd23f]/20 blur-2xl pointer-events-none"></div>
                        <div class="absolute -left-10 -bottom-10 w-36 h-36 rounded-full bg-[#51fac1]/20 blur-2xl pointer-events-none"></div>

                        <div class="relative z-10">
                            <div class="mb-5 border-b border-[#eae8e2] dark:border-white/10 pb-3 flex items-center gap-2.5">
                                <span class="w-8 h-8 rounded-xl bg-[#ffd23f]/20 text-[#574500] dark:text-[#ffd23f] flex items-center justify-center shrink-0">
                                    <span class="material-symbols-outlined text-lg">badge</span>
                                </span>
                                <div>
                                    <h3 class="text-base md:text-lg font-bold text-[#1b1c19] dark:text-white">Informasi Profil</h3>
                                    <p class="text-xs text-[#4d4634]/60 dark:text-white/50">Ubah foto avatar, nama, alamat email, dan nomor WhatsApp.</p>
                                </div>
                            </div>

                            <Form
                                v-bind="form"
                                class="space-y-5"
                                v-slot="{ errors, processing, recentlySuccessful }"
                            >
                                <!-- Avatar Upload -->
                                <div class="flex flex-col sm:flex-row sm:items-center gap-4 sm:gap-6 p-4 rounded-xl bg-[#fbf9f3] dark:bg-white/5 border border-[#eae8e2] dark:border-white/10">
                                    <div class="relative h-20 w-20 sm:h-22 sm:w-22 rounded-full overflow-hidden border-2 border-white dark:border-white/20 shadow-sm shrink-0">
                                        <img 
                                            :src="preview || user.avatar || `https://ui-avatars.com/api/?name=${encodeURIComponent(user.name)}&background=random`" 
                                            alt="Profile" 
                                            class="h-full w-full object-cover"
                                        />
                                    </div>
                                    <div class="flex flex-col gap-2">
                                        <div class="flex flex-wrap items-center gap-2.5">
                                            <Button 
                                                type="button" 
                                                @click="triggerFileInput" 
                                                class="bg-white hover:bg-[#f5f3ee] text-[#1b1c19] dark:bg-white/10 dark:hover:bg-white/15 dark:text-white border border-[#eae8e2] dark:border-white/10 font-bold text-xs h-9 rounded-xl shadow-none inline-flex items-center gap-1.5"
                                            >
                                                <span class="material-symbols-outlined text-base">photo_camera</span>
                                                Ganti Foto
                                            </Button>
                                        </div>
                                        <p class="text-[11px] text-[#4d4634]/60 dark:text-white/40">
                                            Format file didukung: JPG, GIF atau PNG. Ukuran maksimal 1MB.
                                        </p>
                                        <input 
                                            ref="fileInput" 
                                            type="file" 
                                            name="avatar"
                                            class="hidden" 
                                            accept="image/*" 
                                            @change="handleFileChange" 
                                        />
                                        <InputError :message="errors.avatar" />
                                    </div>
                                </div>

                                <div class="grid gap-4 md:grid-cols-2">
                                    <div class="grid gap-1.5">
                                        <Label for="name" class="text-xs font-bold text-[#1b1c19] dark:text-white">Nama Lengkap</Label>
                                        <Input
                                            id="name"
                                            class="bg-[#f5f3ee]/40 dark:bg-white/5 border-[#eae8e2] dark:border-white/10 text-[#1b1c19] dark:text-white rounded-xl px-3.5 py-2.5 text-xs sm:text-sm focus:border-[#ffd23f] focus:ring-2 focus:ring-[#ffd23f]/30"
                                            name="name"
                                            :default-value="user.name"
                                            required
                                            autocomplete="name"
                                            placeholder="Nama lengkap"
                                        />
                                        <InputError :message="errors.name" />
                                    </div>

                                    <div class="grid gap-1.5">
                                        <Label for="email" class="text-xs font-bold text-[#1b1c19] dark:text-white">Alamat Email</Label>
                                        <Input
                                            id="email"
                                            type="email"
                                            class="bg-[#f5f3ee]/40 dark:bg-white/5 border-[#eae8e2] dark:border-white/10 text-[#1b1c19] dark:text-white rounded-xl px-3.5 py-2.5 text-xs sm:text-sm focus:border-[#ffd23f] focus:ring-2 focus:ring-[#ffd23f]/30"
                                            name="email"
                                            :default-value="user.email"
                                            required
                                            autocomplete="username"
                                            placeholder="nama@email.com"
                                        />
                                        <InputError :message="errors.email" />
                                    </div>
                                </div>

                                <div class="grid gap-1.5">
                                    <Label for="whatsapp_number" class="text-xs font-bold text-[#1b1c19] dark:text-white">Nomor WhatsApp Utama</Label>
                                    <Input
                                        id="whatsapp_number"
                                        type="text"
                                        class="bg-[#f5f3ee]/40 dark:bg-white/5 border-[#eae8e2] dark:border-white/10 text-[#1b1c19] dark:text-white rounded-xl px-3.5 py-2.5 text-xs sm:text-sm focus:border-[#ffd23f] focus:ring-2 focus:ring-[#ffd23f]/30"
                                        name="whatsapp_number"
                                        :default-value="user.whatsapp_number"
                                        autocomplete="tel"
                                        placeholder="Contoh: 6281234567890"
                                    />
                                    <p class="text-[11px] text-[#4d4634]/60 dark:text-white/40">
                                        Nomor WhatsApp terhubung untuk notifikasi dan integrasi bot. Gunakan kode negara (contoh: 6281234567890).
                                    </p>
                                    <InputError :message="errors.whatsapp_number" />
                                </div>

                                <div v-if="mustVerifyEmail && !user.email_verified_at" class="rounded-xl bg-[#ffd23f]/15 border border-[#ffd23f]/40 p-3.5">
                                    <div class="flex items-start gap-2.5">
                                        <span class="material-symbols-outlined text-amber-600 dark:text-amber-400 text-lg shrink-0 mt-0.5">warning</span>
                                        <div class="text-xs">
                                            <h4 class="font-bold text-[#1b1c19] dark:text-white">Email Belum Terverifikasi</h4>
                                            <p class="text-[#4d4634]/80 dark:text-white/70 mt-0.5">Alamat email Anda belum diverifikasi.</p>
                                            <Link
                                                :href="send()"
                                                as="button"
                                                class="mt-1.5 font-bold text-[#006c4f] hover:underline dark:text-[#51fac1]"
                                            >
                                                Kirim ulang tautan verifikasi email.
                                            </Link>
                                        </div>
                                    </div>
                                    <div
                                        v-if="status === 'verification-link-sent'"
                                        class="mt-2.5 text-xs font-bold text-[#007152] dark:text-[#51fac1]"
                                    >
                                        Tautan verifikasi baru telah dikirimkan ke alamat email Anda.
                                    </div>
                                </div>

                                <div class="flex items-center gap-3 pt-3 border-t border-[#eae8e2] dark:border-white/10">
                                    <Button
                                        :disabled="processing"
                                        data-test="update-profile-button"
                                        class="bg-[#ffd23f] hover:bg-[#ffe089] text-[#574500] font-bold text-xs sm:text-sm rounded-xl px-4 py-2.5 shadow-none border-0 inline-flex items-center gap-1.5"
                                    >
                                        <Spinner v-if="processing" />
                                        <span v-else class="flex items-center gap-1.5">
                                            <span class="material-symbols-outlined text-base">save</span>
                                            Simpan Perubahan
                                        </span>
                                    </Button>

                                    <Transition
                                        enter-active-class="transition ease-in-out duration-200"
                                        enter-from-class="opacity-0"
                                        leave-active-class="transition ease-in-out duration-200"
                                        leave-to-class="opacity-0"
                                    >
                                        <p
                                            v-show="recentlySuccessful"
                                            class="text-xs text-[#007152] dark:text-[#51fac1] font-bold flex items-center gap-1"
                                        >
                                            <span class="material-symbols-outlined text-base">check_circle</span>
                                            Berhasil disimpan!
                                        </p>
                                    </Transition>
                                </div>
                            </Form>
                        </div>
                    </div>

                    <!-- 2. Update Password Card (Gaya Finwa Segar) -->
                    <div class="relative overflow-hidden rounded-2xl bg-white dark:bg-[#23231f] border border-[#eae8e2] dark:border-white/10 p-5 md:p-6 shadow-[0_4px_20px_rgb(0,0,0,0.03)] dark:shadow-none transition-all">
                        <!-- Decorative Glow -->
                        <div class="absolute -right-10 -top-10 w-36 h-36 rounded-full bg-[#51fac1]/15 blur-2xl pointer-events-none"></div>

                        <div class="relative z-10">
                            <div class="mb-5 border-b border-[#eae8e2] dark:border-white/10 pb-3 flex items-center gap-2.5">
                                <span class="w-8 h-8 rounded-xl bg-[#51fac1]/20 text-[#007152] dark:text-[#51fac1] flex items-center justify-center shrink-0">
                                    <span class="material-symbols-outlined text-lg">lock_reset</span>
                                </span>
                                <div>
                                    <h3 class="text-base md:text-lg font-bold text-[#1b1c19] dark:text-white">Ubah Kata Sandi</h3>
                                    <p class="text-xs text-[#4d4634]/60 dark:text-white/50">Pastikan akun menggunakan sandi yang panjang dan aman.</p>
                                </div>
                            </div>

                            <Form
                                v-bind="PasswordController.update.form()"
                                :options="{ preserveScroll: true }"
                                reset-on-success
                                :reset-on-error="['password', 'password_confirmation', 'current_password']"
                                class="space-y-4"
                                v-slot="{ errors, processing, recentlySuccessful }"
                            >
                                <div class="grid gap-1.5">
                                    <Label for="current_password" class="text-xs font-bold text-[#1b1c19] dark:text-white">Kata Sandi Saat Ini</Label>
                                    <div class="relative">
                                        <Input
                                            id="current_password"
                                            name="current_password"
                                            :type="showCurrentPassword ? 'text' : 'password'"
                                            class="bg-[#f5f3ee]/40 dark:bg-white/5 border-[#eae8e2] dark:border-white/10 text-[#1b1c19] dark:text-white rounded-xl px-3.5 py-2.5 text-xs sm:text-sm focus:border-[#ffd23f] focus:ring-2 focus:ring-[#ffd23f]/30 pr-10"
                                            autocomplete="current-password"
                                            placeholder="Masukkan kata sandi saat ini"
                                        />
                                        <button
                                            type="button"
                                            class="absolute right-2.5 top-1/2 -translate-y-1/2 p-1 rounded-lg text-[#4d4634]/50 hover:text-[#1b1c19] dark:text-white/50 dark:hover:text-white transition-colors"
                                            :aria-label="showCurrentPassword ? 'Sembunyikan password' : 'Tampilkan password'"
                                            @click="showCurrentPassword = !showCurrentPassword"
                                        >
                                            <span class="material-symbols-outlined text-lg leading-none select-none">
                                                {{ showCurrentPassword ? 'visibility_off' : 'visibility' }}
                                            </span>
                                        </button>
                                    </div>
                                    <InputError :message="errors.current_password" />
                                </div>

                                <div class="grid gap-4 md:grid-cols-2">
                                    <div class="grid gap-1.5">
                                        <Label for="password" class="text-xs font-bold text-[#1b1c19] dark:text-white">Kata Sandi Baru</Label>
                                        <div class="relative">
                                            <Input
                                                id="password"
                                                name="password"
                                                :type="showNewPassword ? 'text' : 'password'"
                                                class="bg-[#f5f3ee]/40 dark:bg-white/5 border-[#eae8e2] dark:border-white/10 text-[#1b1c19] dark:text-white rounded-xl px-3.5 py-2.5 text-xs sm:text-sm focus:border-[#ffd23f] focus:ring-2 focus:ring-[#ffd23f]/30 pr-10"
                                                autocomplete="new-password"
                                                placeholder="Minimal 8 karakter"
                                            />
                                            <button
                                                type="button"
                                                class="absolute right-2.5 top-1/2 -translate-y-1/2 p-1 rounded-lg text-[#4d4634]/50 hover:text-[#1b1c19] dark:text-white/50 dark:hover:text-white transition-colors"
                                                :aria-label="showNewPassword ? 'Sembunyikan password' : 'Tampilkan password'"
                                                @click="showNewPassword = !showNewPassword"
                                            >
                                                <span class="material-symbols-outlined text-lg leading-none select-none">
                                                    {{ showNewPassword ? 'visibility_off' : 'visibility' }}
                                                </span>
                                            </button>
                                        </div>
                                        <InputError :message="errors.password" />
                                    </div>

                                    <div class="grid gap-1.5">
                                        <Label for="password_confirmation" class="text-xs font-bold text-[#1b1c19] dark:text-white">Ulangi Kata Sandi Baru</Label>
                                        <div class="relative">
                                            <Input
                                                id="password_confirmation"
                                                name="password_confirmation"
                                                :type="showConfirmPassword ? 'text' : 'password'"
                                                class="bg-[#f5f3ee]/40 dark:bg-white/5 border-[#eae8e2] dark:border-white/10 text-[#1b1c19] dark:text-white rounded-xl px-3.5 py-2.5 text-xs sm:text-sm focus:border-[#ffd23f] focus:ring-2 focus:ring-[#ffd23f]/30 pr-10"
                                                autocomplete="new-password"
                                                placeholder="Konfirmasi kata sandi"
                                            />
                                            <button
                                                type="button"
                                                class="absolute right-2.5 top-1/2 -translate-y-1/2 p-1 rounded-lg text-[#4d4634]/50 hover:text-[#1b1c19] dark:text-white/50 dark:hover:text-white transition-colors"
                                                :aria-label="showConfirmPassword ? 'Sembunyikan password' : 'Tampilkan password'"
                                                @click="showConfirmPassword = !showConfirmPassword"
                                            >
                                                <span class="material-symbols-outlined text-lg leading-none select-none">
                                                    {{ showConfirmPassword ? 'visibility_off' : 'visibility' }}
                                                </span>
                                            </button>
                                        </div>
                                        <InputError :message="errors.password_confirmation" />
                                    </div>
                                </div>

                                <div class="flex items-center gap-3 pt-3 border-t border-[#eae8e2] dark:border-white/10">
                                    <Button
                                        :disabled="processing"
                                        data-test="update-password-button"
                                        class="bg-[#ffd23f] hover:bg-[#ffe089] text-[#574500] font-bold text-xs sm:text-sm rounded-xl px-4 py-2.5 shadow-none border-0 inline-flex items-center gap-1.5"
                                    >
                                        <Spinner v-if="processing" />
                                        <span v-else class="flex items-center gap-1.5">
                                            <span class="material-symbols-outlined text-base">key</span>
                                            Perbarui Sandi
                                        </span>
                                    </Button>

                                    <Transition
                                        enter-active-class="transition ease-in-out duration-200"
                                        enter-from-class="opacity-0"
                                        leave-active-class="transition ease-in-out duration-200"
                                        leave-to-class="opacity-0"
                                    >
                                        <p
                                            v-show="recentlySuccessful"
                                            class="text-xs text-[#007152] dark:text-[#51fac1] font-bold flex items-center gap-1"
                                        >
                                            <span class="material-symbols-outlined text-base">check_circle</span>
                                            Kata sandi diperbarui.
                                        </p>
                                    </Transition>
                                </div>
                            </Form>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Delete User / Zona Bahaya (Span 1) -->
                <div class="xl:col-span-1 flex flex-col gap-6">
                     <!-- Delete User Card -->
                     <div class="relative overflow-hidden rounded-2xl bg-white dark:bg-[#23231f] p-5 md:p-6 shadow-[0_4px_20px_rgb(0,0,0,0.03)] dark:shadow-none border border-red-200/70 dark:border-red-900/30 transition-all">
                        <div class="mb-4 border-b border-red-100 dark:border-red-900/20 pb-3 flex items-center gap-2">
                            <span class="w-8 h-8 rounded-xl bg-red-100 dark:bg-red-950/40 text-red-600 flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-lg">warning</span>
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-red-600 dark:text-red-400">Zona Berbahaya</h3>
                                <p class="text-xs text-[#4d4634]/60 dark:text-white/50">Tindakan ini tidak dapat dibatalkan.</p>
                            </div>
                        </div>
                        <DeleteUser />
                     </div>
                </div>

            </div>
        </div>
    </AppLayout>
</template>
