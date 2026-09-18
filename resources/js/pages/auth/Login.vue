<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';

const showPassword = ref(false);

defineProps<{
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}>();

// telegram.me lebih andal jika t.me diblokir DNS
const telegramUrl = 'https://telegram.me/Finwabot?start=register';

// Buka link eksternal tanpa mengganti halaman (hindari ERR_UNKNOWN_URL_SCHEME /
// "Tidak ada koneksi internet" saat dibuka di dalam webview/PWA aplikasi).
const openExternalLink = (url: string) => {
    const link = document.createElement('a');
    link.href = url;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    document.body.appendChild(link);
    link.click();
    link.remove();
};
</script>

<template>
    <AuthBase
        title="Selamat Datang Kembali!"
        description="Masuk ke akun FinWa untuk pantau & catat keuanganmu"
    >
        <Head title="Login - FinWa">
            <meta name="description" content="Login ke akun FinWa Anda. Kelola keuangan via WhatsApp dengan mudah dan otomatis." />
            <meta name="robots" content="noindex, follow" />
        </Head>

        <div
            v-if="status"
            class="mb-4 rounded-xl bg-[#51fac1]/20 text-center text-xs sm:text-sm font-bold text-[#007152] border border-[#51fac1]/40 p-3"
        >
            {{ status }}
        </div>

        <!-- Tombol Registrasi Bot Cepat -->
        <div class="mb-5 flex flex-col gap-2.5">
            <!-- Daftar via WhatsApp -->
            <button
                type="button"
                @click="openExternalLink('https://wa.me/6285159205506?text=Halo%20FinWa%2C%20saya%20mau%20daftar')"
                class="w-full flex items-center justify-center gap-2.5 bg-white dark:bg-[#1b1c19] hover:bg-[#f5f3ee] dark:hover:bg-white/5 border border-[#eae8e2] dark:border-white/10 text-[#1b1c19] dark:text-white font-bold text-xs sm:text-sm transition-all duration-200 py-3 px-4 rounded-xl"
                data-test="register-whatsapp-button"
            >
                <span class="w-7 h-7 rounded-lg bg-[#25D366]/15 text-[#25D366] flex items-center justify-center shrink-0">
                    <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 0 0-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/></svg>
                </span>
                <span>Daftar Cepat via WhatsApp</span>
            </button>

            <!-- Daftar via Telegram -->
            <button
                type="button"
                @click="openExternalLink(telegramUrl)"
                class="w-full flex items-center justify-center gap-2.5 bg-white dark:bg-[#1b1c19] hover:bg-[#f5f3ee] dark:hover:bg-white/5 border border-[#eae8e2] dark:border-white/10 text-[#1b1c19] dark:text-white font-bold text-xs sm:text-sm transition-all duration-200 py-3 px-4 rounded-xl"
                data-test="register-telegram-button"
            >
                <span class="w-7 h-7 rounded-lg bg-[#0088cc]/15 text-[#0088cc] flex items-center justify-center shrink-0">
                    <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>
                </span>
                <span>Daftar via Telegram</span>
            </button>
        </div>

        <!-- Pembatas / Divider -->
        <div class="relative my-6">
            <div class="absolute inset-0 flex items-center">
                <span class="w-full border-t border-[#eae8e2] dark:border-white/10"></span>
            </div>
            <div class="relative flex justify-center text-[11px] uppercase tracking-wider font-bold">
                <span class="bg-white dark:bg-[#23231f] px-3 text-[#4d4634]/60 dark:text-white/50">atau login dengan email</span>
            </div>
        </div>

        <Form
            v-bind="store.form()"
            :reset-on-success="['password']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-4"
        >
            <div class="grid gap-4">
                <div class="grid gap-1.5">
                    <Label for="email" class="text-xs font-bold text-[#1b1c19] dark:text-white">Alamat Email</Label>
                    <div class="relative">
                        <Input
                            id="email"
                            type="email"
                            name="email"
                            required
                            autofocus
                            :tabindex="1"
                            autocomplete="email"
                            placeholder="nama@email.com"
                            class="bg-[#f5f3ee]/40 dark:bg-white/5 border-[#eae8e2] dark:border-white/10 text-[#1b1c19] dark:text-white placeholder:text-[#4d4634]/40 dark:placeholder:text-white/30 rounded-xl px-3.5 py-2.5 text-xs sm:text-sm focus:border-[#ffd23f] focus:ring-2 focus:ring-[#ffd23f]/30"
                        />
                    </div>
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-1.5">
                    <div class="flex items-center justify-between">
                        <Label for="password" class="text-xs font-bold text-[#1b1c19] dark:text-white">Kata Sandi</Label>
                        <TextLink
                            v-if="canResetPassword"
                            :href="request()"
                            class="text-xs font-bold text-[#006c4f] hover:text-[#007152] dark:text-[#51fac1] dark:hover:text-[#51fac1]/80 transition-colors"
                            :tabindex="5"
                        >
                            Lupa kata sandi?
                        </TextLink>
                    </div>
                    <div class="relative">
                        <Input
                            id="password"
                            :type="showPassword ? 'text' : 'password'"
                            name="password"
                            required
                            :tabindex="2"
                            autocomplete="current-password"
                            placeholder="Masukkan kata sandi Anda"
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

                <div class="flex items-center justify-between pt-1">
                    <Label for="remember" class="flex items-center gap-2 text-xs font-semibold text-[#4d4634] dark:text-white/70 cursor-pointer select-none">
                        <Checkbox id="remember" name="remember" :tabindex="3" :default-checked="true" class="rounded-md border-[#eae8e2] data-[state=checked]:bg-[#006c4f] data-[state=checked]:border-[#006c4f]" />
                        <span>Ingat saya di perangkat ini</span>
                    </Label>
                </div>
                <input type="hidden" name="remember" value="on" />

                <Button
                    type="submit"
                    class="mt-2 w-full bg-[#ffd23f] hover:bg-[#ffe089] text-[#574500] font-bold text-sm transition-all rounded-xl py-2.5 h-auto shadow-none border-0"
                    :tabindex="4"
                    :disabled="processing"
                    data-test="login-button"
                >
                    <Spinner v-if="processing" />
                    <span v-else class="flex items-center justify-center gap-1.5">
                        <span class="material-symbols-outlined text-lg">login</span>
                        Masuk ke FinWa
                    </span>
                </Button>
            </div>
        </Form>
    </AuthBase>
</template>

