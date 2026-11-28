<script setup lang="ts">
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import { Form } from '@inertiajs/vue3';
import { useTemplateRef } from 'vue';

// Components
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const passwordInput = useTemplateRef('passwordInput');
</script>

<template>
    <div class="space-y-4 font-['Plus_Jakarta_Sans',sans-serif]">
        <div
            class="space-y-2 rounded-xl border border-red-200/80 bg-red-50/60 p-4 dark:border-red-900/30 dark:bg-red-950/20"
        >
            <div class="relative space-y-1 text-red-700 dark:text-red-300">
                <p class="text-xs font-bold flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">error</span>
                    Peringatan Akun
                </p>
                <p class="text-xs text-[#4d4634]/80 dark:text-white/70 leading-relaxed">
                    Setelah akun Anda dihapus, seluruh data keuangan, histori WhatsApp, dan celengan akan hilang permanen.
                </p>
            </div>
            
            <div class="pt-2">
                <Dialog>
                    <DialogTrigger as-child>
                        <Button 
                            variant="destructive" 
                            data-test="delete-user-button"
                            class="w-full bg-red-600 hover:bg-red-700 text-white font-bold text-xs h-9 rounded-xl shadow-none inline-flex items-center justify-center gap-1.5"
                        >
                            <span class="material-symbols-outlined text-base">delete_forever</span>
                            Hapus Akun Saya
                        </Button>
                    </DialogTrigger>
                    <DialogContent class="rounded-2xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] p-6 max-w-md">
                        <Form
                            v-bind="ProfileController.destroy.form()"
                            reset-on-success
                            @error="() => passwordInput?.$el?.focus()"
                            :options="{
                                preserveScroll: true,
                            }"
                            class="space-y-5"
                            v-slot="{ errors, processing, reset, clearErrors }"
                        >
                            <DialogHeader class="space-y-2 text-left">
                                <DialogTitle class="text-lg font-bold text-[#1b1c19] dark:text-white flex items-center gap-2">
                                    <span class="material-symbols-outlined text-red-600 text-xl">warning</span>
                                    Konfirmasi Hapus Akun?
                                </DialogTitle>
                                <DialogDescription class="text-xs text-[#4d4634]/70 dark:text-white/60 leading-relaxed">
                                    Tindakan ini tidak dapat dibatalkan. Masukkan kata sandi Anda untuk mengonfirmasi bahwa Anda benar-benar ingin menghapus akun ini secara permanen.
                                </DialogDescription>
                            </DialogHeader>

                            <div class="grid gap-1.5">
                                <Label for="password" class="text-xs font-bold text-[#1b1c19] dark:text-white">
                                    Kata Sandi Anda
                                </Label>
                                <Input
                                    id="password"
                                    type="password"
                                    name="password"
                                    ref="passwordInput"
                                    placeholder="Ketik kata sandi untuk konfirmasi"
                                    class="bg-[#f5f3ee]/40 dark:bg-white/5 border-[#eae8e2] dark:border-white/10 text-[#1b1c19] dark:text-white rounded-xl px-3.5 py-2.5 text-xs sm:text-sm focus:border-red-500 focus:ring-2 focus:ring-red-500/20"
                                />
                                <InputError :message="errors.password" />
                            </div>

                            <DialogFooter class="flex flex-col sm:flex-row gap-2 pt-2">
                                <DialogClose as-child>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        @click="
                                            () => {
                                                clearErrors();
                                                reset();
                                            }
                                        "
                                        class="w-full sm:w-auto bg-[#f5f3ee] hover:bg-[#eae8e2] text-[#1b1c19] dark:bg-white/10 dark:hover:bg-white/15 dark:text-white rounded-xl text-xs font-bold h-9"
                                    >
                                        Batal
                                    </Button>
                                </DialogClose>

                                <Button
                                    type="submit"
                                    variant="destructive"
                                    :disabled="processing"
                                    data-test="confirm-delete-user-button"
                                    class="w-full sm:w-auto bg-red-600 hover:bg-red-700 text-white rounded-xl text-xs font-bold h-9 shadow-none inline-flex items-center justify-center gap-1.5"
                                >
                                    <span class="material-symbols-outlined text-base">delete</span>
                                    Ya, Hapus Permanen
                                </Button>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    </div>
</template>
