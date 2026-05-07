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
        title: 'Profile settings',
        href: edit().url,
    },
];

const page = usePage();
const user = computed(() => page.props.auth.user);

const form = ProfileController.update.form();
const fileInput = ref<HTMLInputElement | null>(null);
const preview = ref<string | null>(null);

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
        <Head title="Profile settings" />

        <div class="flex flex-col gap-6">
            <!-- Header Section (Consistent with Dashboard) -->
            <div class="flex items-center justify-between px-7">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Profile Settings</h2>
                    <p class="text-sm text-gray-500 mt-1">Update your profile information and manage your account</p>
                </div>
            </div>

            <!-- Main Content Grid -->
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                
                <!-- Left Column: Profile Information (Span 2) -->
                <div class="xl:col-span-2 flex flex-col gap-6">
                    <!-- Profile Info Card -->
                    <div class="relative overflow-hidden rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(0,0,0,0.04)] transition-all hover:shadow-md dark:bg-gray-800">
                        <div class="mb-6 border-b border-gray-100 dark:border-gray-700 pb-4 px-1">
                             <h3 class="text-lg font-bold text-gray-900 dark:text-white">Profile Information</h3>
                             <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Update your name, email address, and WhatsApp number.</p>
                        </div>

                        <Form
                            v-bind="form"
                            class="space-y-6 px-1"
                            v-slot="{ errors, processing, recentlySuccessful }"
                        >
                            <!-- Avatar Upload -->
                            <div class="flex items-center gap-6">
                                <div class="relative h-24 w-24 rounded-full overflow-hidden border-2 border-gray-100 dark:border-gray-700 shadow-sm">
                                    <img 
                                        :src="preview || user.avatar || `https://ui-avatars.com/api/?name=${encodeURIComponent(user.name)}&background=random`" 
                                        alt="Profile" 
                                        class="h-full w-full object-cover"
                                    />
                                </div>
                                <div class="flex flex-col gap-3">
                                    <div class="flex gap-3">
                                        <Button type="button" variant="outline" @click="triggerFileInput" class="shadow-sm">
                                            Change Photo
                                        </Button>
                                    </div>
                                    <p class="text-xs text-muted-foreground">
                                        JPG, GIF or PNG. Max 1MB.
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
                                <div class="grid gap-2">
                                    <Label for="name">Name</Label>
                                    <Input
                                        id="name"
                                        class="mt-1 block w-full"
                                        name="name"
                                        :default-value="user.name"
                                        required
                                        autocomplete="name"
                                        placeholder="Full name"
                                    />
                                    <InputError class="mt-2" :message="errors.name" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="email">Email address</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        class="mt-1 block w-full"
                                        name="email"
                                        :default-value="user.email"
                                        required
                                        autocomplete="username"
                                        placeholder="Email address"
                                    />
                                    <InputError class="mt-2" :message="errors.email" />
                                </div>
                            </div>

                            <div class="grid gap-2">
                                <Label for="whatsapp_number">WhatsApp Number</Label>
                                <Input
                                    id="whatsapp_number"
                                    type="text"
                                    class="mt-1 block w-full"
                                    name="whatsapp_number"
                                    :default-value="user.whatsapp_number"
                                    autocomplete="tel"
                                    placeholder="6281234567890"
                                />
                                <p class="text-sm text-muted-foreground">
                                    Nomor WhatsApp untuk notifikasi. Gunakan format internasional (contoh: 6281234567890).
                                </p>
                                <InputError class="mt-2" :message="errors.whatsapp_number" />
                            </div>

                            <div v-if="mustVerifyEmail && !user.email_verified_at" class="rounded-lg bg-yellow-50 p-4 dark:bg-yellow-900/20">
                                <div class="flex">
                                    <div class="flex-shrink-0">
                                        <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                    <div class="ml-3">
                                        <h3 class="text-sm font-medium text-yellow-800 dark:text-yellow-200">Email Unverified</h3>
                                        <div class="mt-2 text-sm text-yellow-700 dark:text-yellow-300">
                                            <p>Your email address is unverified.</p>
                                            <Link
                                                :href="send()"
                                                as="button"
                                                class="mt-2 font-medium underline hover:text-yellow-600 dark:hover:text-yellow-100"
                                            >
                                                Click here to resend the verification email.
                                            </Link>
                                        </div>
                                    </div>
                                </div>
                                <div
                                    v-if="status === 'verification-link-sent'"
                                    class="mt-4 text-sm font-medium text-green-600"
                                >
                                    A new verification link has been sent to your email address.
                                </div>
                            </div>

                            <div class="flex items-center gap-4 pt-4 border-t border-gray-100 dark:border-gray-700">
                                <Button
                                    :disabled="processing"
                                    data-test="update-profile-button"
                                    class="bg-gray-900 text-white hover:bg-gray-800 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-100"
                                >
                                    Save Changes
                                </Button>

                                <Transition
                                    enter-active-class="transition ease-in-out"
                                    enter-from-class="opacity-0"
                                    leave-active-class="transition ease-in-out"
                                    leave-to-class="opacity-0"
                                >
                                    <p
                                        v-show="recentlySuccessful"
                                        class="text-sm text-green-600 font-medium flex items-center gap-1"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                        Saved successfully.
                                    </p>
                                </Transition>
                            </div>
                        </Form>
                    </div>

                    <!-- Update Password Card -->
                    <div class="relative overflow-hidden rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(0,0,0,0.04)] transition-all hover:shadow-md dark:bg-gray-800">
                        <div class="mb-6 border-b border-gray-100 dark:border-gray-700 pb-4 px-1">
                             <h3 class="text-lg font-bold text-gray-900 dark:text-white">Update Password</h3>
                             <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Ensure your account is using a long, random password to stay secure.</p>
                        </div>

                        <Form
                            v-bind="PasswordController.update.form()"
                            :options="{
                                preserveScroll: true,
                            }"
                            reset-on-success
                            :reset-on-error="[
                                'password',
                                'password_confirmation',
                                'current_password',
                            ]"
                            class="space-y-6 px-1"
                            v-slot="{ errors, processing, recentlySuccessful }"
                        >
                            <div class="grid gap-2">
                                <Label for="current_password">Current Password</Label>
                                <Input
                                    id="current_password"
                                    name="current_password"
                                    type="password"
                                    class="mt-1 block w-full"
                                    autocomplete="current-password"
                                    placeholder="Current password"
                                />
                                <InputError :message="errors.current_password" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="password">New Password</Label>
                                <Input
                                    id="password"
                                    name="password"
                                    type="password"
                                    class="mt-1 block w-full"
                                    autocomplete="new-password"
                                    placeholder="New password"
                                />
                                <InputError :message="errors.password" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="password_confirmation">Confirm Password</Label>
                                <Input
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    type="password"
                                    class="mt-1 block w-full"
                                    autocomplete="new-password"
                                    placeholder="Confirm password"
                                />
                                <InputError :message="errors.password_confirmation" />
                            </div>

                            <div class="flex items-center gap-4 pt-4 border-t border-gray-100 dark:border-gray-700">
                                <Button
                                    :disabled="processing"
                                    data-test="update-password-button"
                                    class="bg-gray-900 text-white hover:bg-gray-800 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-100"
                                >
                                    Update Password
                                </Button>

                                <Transition
                                    enter-active-class="transition ease-in-out"
                                    enter-from-class="opacity-0"
                                    leave-active-class="transition ease-in-out"
                                    leave-to-class="opacity-0"
                                >
                                    <p
                                        v-show="recentlySuccessful"
                                        class="text-sm text-green-600 font-medium flex items-center gap-1"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                        Password updated.
                                    </p>
                                </Transition>
                            </div>
                        </Form>
                    </div>
                </div>

                <!-- Right Column: Delete User (Span 1) -->
                <div class="xl:col-span-1 flex flex-col gap-6">
                     <!-- Delete User Card -->
                     <div class="relative overflow-hidden rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(0,0,0,0.04)] transition-all hover:shadow-md dark:bg-gray-800 border border-red-100 dark:border-red-900/30">
                        <div class="mb-6 border-b border-gray-100 dark:border-gray-700 pb-4">
                             <h3 class="text-lg font-bold text-red-600">Danger Zone</h3>
                             <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Irreversible actions for your account.</p>
                        </div>
                        <DeleteUser />
                     </div>
                </div>

            </div>
        </div>
    </AppLayout>
</template>
