<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { store } from '@/routes/register';
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';

const showPassword = ref(false);
const showConfirmPassword = ref(false);
</script>

<template>
    <AuthBase
        title="Buat Akun Baru"
        description="Mulai langkah mudah mengelola keuangan pribadimu bersama FinWa"
    >
        <Head title="Daftar Akun - FinWa">
            <meta name="description" content="Daftar akun FinWa gratis. Mulai catat keuangan via WhatsApp secara otomatis hari ini." />
            <meta name="robots" content="noindex, follow" />
        </Head>

        <Form
            v-bind="store.form()"
            :reset-on-success="['password', 'password_confirmation']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-4"
        >
            <div class="grid gap-4">
                <div class="grid gap-1.5">
                    <Label for="name" class="text-xs font-bold text-[#1b1c19] dark:text-white">Nama Lengkap</Label>
                    <Input
                        id="name"
                        type="text"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="name"
                        name="name"
                        placeholder="Contoh: Budi Santoso"
                        class="bg-[#f5f3ee]/40 dark:bg-white/5 border-[#eae8e2] dark:border-white/10 text-[#1b1c19] dark:text-white placeholder:text-[#4d4634]/40 dark:placeholder:text-white/30 rounded-xl px-3.5 py-2.5 text-xs sm:text-sm focus:border-[#ffd23f] focus:ring-2 focus:ring-[#ffd23f]/30"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="email" class="text-xs font-bold text-[#1b1c19] dark:text-white">Alamat Email</Label>
                    <Input
                        id="email"
                        type="email"
                        required
                        :tabindex="2"
                        autocomplete="email"
                        name="email"
                        placeholder="nama@email.com"
                        class="bg-[#f5f3ee]/40 dark:bg-white/5 border-[#eae8e2] dark:border-white/10 text-[#1b1c19] dark:text-white placeholder:text-[#4d4634]/40 dark:placeholder:text-white/30 rounded-xl px-3.5 py-2.5 text-xs sm:text-sm focus:border-[#ffd23f] focus:ring-2 focus:ring-[#ffd23f]/30"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="password" class="text-xs font-bold text-[#1b1c19] dark:text-white">Kata Sandi</Label>
                    <div class="relative">
                        <Input
                            id="password"
                            :type="showPassword ? 'text' : 'password'"
                            required
                            :tabindex="3"
                            autocomplete="new-password"
                            name="password"
                            placeholder="Minimal 8 karakter"
                            class="bg-[#f5f3ee]/40 dark:bg-white/5 border-[#eae8e2] dark:border-white/10 text-[#1b1c19] dark:text-white placeholder:text-[#4d4634]/40 dark:placeholder:text-white/30 rounded-xl px-3.5 py-2.5 text-xs sm:text-sm focus:border-[#ffd23f] focus:ring-2 focus:ring-[#ffd23f]/30 pr-10"
                        />
                        <button
                            type="button"
                            class="absolute right-2.5 top-1/2 -translate-y-1/2 p-1 rounded-lg text-[#4d4634]/50 hover:text-[#1b1c19] dark:text-white/50 dark:hover:text-white transition-colors"
                            :aria-label="showPassword ? 'Sembunyikan password' : 'Tampilkan password'"
                            @click="showPassword = !showPassword"
                        >
                            <span class="material-symbols-outlined text-lg leading-none select-none">
                                {{ showPassword ? 'visibility_off' : 'visibility' }}
                            </span>
                        </button>
                    </div>
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="password_confirmation" class="text-xs font-bold text-[#1b1c19] dark:text-white">Konfirmasi Kata Sandi</Label>
                    <div class="relative">
                        <Input
                            id="password_confirmation"
                            :type="showConfirmPassword ? 'text' : 'password'"
                            required
                            :tabindex="4"
                            autocomplete="new-password"
                            name="password_confirmation"
                            placeholder="Ulangi kata sandi"
                            class="bg-[#f5f3ee]/40 dark:bg-white/5 border-[#eae8e2] dark:border-white/10 text-[#1b1c19] dark:text-white placeholder:text-[#4d4634]/40 dark:placeholder:text-white/30 rounded-xl px-3.5 py-2.5 text-xs sm:text-sm focus:border-[#ffd23f] focus:ring-2 focus:ring-[#ffd23f]/30 pr-10"
                        />
                        <button
                            type="button"
                            class="absolute right-2.5 top-1/2 -translate-y-1/2 p-1 rounded-lg text-[#4d4634]/50 hover:text-[#1b1c19] dark:text-white/50 dark:hover:text-white transition-colors"
                            :aria-label="showConfirmPassword ? 'Sembunyikan konfirmasi password' : 'Tampilkan konfirmasi password'"
                            @click="showConfirmPassword = !showConfirmPassword"
                        >
                            <span class="material-symbols-outlined text-lg leading-none select-none">
                                {{ showConfirmPassword ? 'visibility_off' : 'visibility' }}
                            </span>
                        </button>
                    </div>
                    <InputError :message="errors.password_confirmation" />
                </div>

                <Button
                    type="submit"
                    class="mt-2 w-full bg-[#ffd23f] hover:bg-[#ffe089] text-[#574500] font-bold text-sm transition-all rounded-xl py-2.5 h-auto shadow-none border-0"
                    :tabindex="5"
                    :disabled="processing"
                    data-test="register-user-button"
                >
                    <Spinner v-if="processing" />
                    <span v-else class="flex items-center justify-center gap-1.5">
                        <span class="material-symbols-outlined text-lg">person_add</span>
                        Daftar Akun FinWa
                    </span>
                </Button>
            </div>

            <div class="text-center text-xs text-[#4d4634]/70 dark:text-white/60 mt-2">
                Sudah memiliki akun?
                <TextLink
                    :href="login()"
                    class="font-bold text-[#006c4f] hover:text-[#007152] dark:text-[#51fac1] dark:hover:text-[#51fac1]/80 transition-colors ml-1"
                    :tabindex="6"
                >
                    Masuk di sini
                </TextLink>
            </div>
        </Form>
    </AuthBase>
</template>
