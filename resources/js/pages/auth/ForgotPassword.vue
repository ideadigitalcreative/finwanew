<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { email } from '@/routes/password';
import { Form, Head } from '@inertiajs/vue3';

defineProps<{
    status?: string;
}>();
</script>

<template>
    <AuthLayout
        title="Lupa Kata Sandi?"
        description="Masukkan email yang terdaftar untuk menerima tautan reset kata sandi"
    >
        <Head title="Lupa Kata Sandi - FinWa" />

        <div
            v-if="status"
            class="mb-4 rounded-xl bg-[#51fac1]/20 text-center text-xs sm:text-sm font-bold text-[#007152] border border-[#51fac1]/40 p-3"
        >
            {{ status }}
        </div>

        <div class="space-y-4">
            <Form v-bind="email.form()" v-slot="{ errors, processing }">
                <div class="grid gap-3">
                    <div class="grid gap-1.5">
                        <Label for="email" class="text-xs font-bold text-[#1b1c19] dark:text-white">Alamat Email</Label>
                        <Input
                            id="email"
                            type="email"
                            name="email"
                            autocomplete="off"
                            autofocus
                            placeholder="nama@email.com"
                            class="bg-[#f5f3ee]/40 dark:bg-white/5 border-[#eae8e2] dark:border-white/10 text-[#1b1c19] dark:text-white placeholder:text-[#4d4634]/40 dark:placeholder:text-white/30 rounded-xl px-3.5 py-2.5 text-xs sm:text-sm focus:border-[#ffd23f] focus:ring-2 focus:ring-[#ffd23f]/30"
                        />
                        <InputError :message="errors.email" />
                    </div>

                    <Button
                        class="w-full mt-2 bg-[#ffd23f] hover:bg-[#ffe089] text-[#574500] font-bold text-sm transition-all rounded-xl py-2.5 h-auto shadow-none border-0"
                        :disabled="processing"
                        data-test="email-password-reset-link-button"
                    >
                        <Spinner v-if="processing" />
                        <span v-else class="flex items-center justify-center gap-1.5">
                            <span class="material-symbols-outlined text-lg">mail</span>
                            Kirim Tautan Reset Sandi
                        </span>
                    </Button>
                </div>
            </Form>

            <div class="text-center text-xs text-[#4d4634]/70 dark:text-white/60">
                <span>Ingat kata sandi Anda?</span>
                <TextLink
                    :href="login()"
                    class="font-bold text-[#006c4f] hover:text-[#007152] dark:text-[#51fac1] dark:hover:text-[#51fac1]/80 transition-colors ml-1"
                >
                    Kembali ke Login
                </TextLink>
            </div>
        </div>
    </AuthLayout>
</template>
