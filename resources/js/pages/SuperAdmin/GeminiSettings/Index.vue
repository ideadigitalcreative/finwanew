<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { useSweetAlert } from '@/composables/useSweetAlert';
import { Sparkles } from 'lucide-vue-next';
import { cn } from '@/lib/utils';

interface KeyPreview {
    mask: string;
}

interface Settings {
    model: string;
    base_url: string;
    timeout: number;
    key_count: number;
    keys_preview: KeyPreview[];
}

interface Props {
    settings: Settings;
}

const props = defineProps<Props>();
const { showSuccess } = useSweetAlert();

const replaceKeys = ref(false);

const form = useForm({
    model: props.settings.model,
    base_url: props.settings.base_url ?? '',
    replace_api_keys: false,
    api_keys_text: '',
});

const breadcrumbs = computed(() => [
    { title: 'Super Admin', href: '/superadmin/dashboard' },
    { title: 'Pengaturan Gemini AI', href: '/superadmin/gemini-settings' },
]);

const submit = () => {
    form.replace_api_keys = replaceKeys.value;
    form.put('/superadmin/gemini-settings', {
        preserveScroll: true,
        onSuccess: () => {
            showSuccess('Tersimpan', 'Pengaturan Gemini berhasil diperbarui.');
            replaceKeys.value = false;
            form.api_keys_text = '';
        },
    });
};
</script>

<template>
    <Head title="Pengaturan Gemini AI" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-2xl space-y-8 p-4 md:p-8">
            <div class="flex items-start gap-3">
                <div class="rounded-lg bg-primary/10 p-2">
                    <Sparkles class="h-6 w-6 text-primary" />
                </div>
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Pengaturan Gemini AI</h1>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Model, base URL proxy (opsional), dan beberapa API key dengan rotasi otomatis antar key.
                    </p>
                </div>
            </div>

            <form class="space-y-6" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="model">Model Gemini</Label>
                    <Input id="model" v-model="form.model" type="text" required autocomplete="off" />
                    <InputError :message="form.errors.model" />
                    <p class="text-muted-foreground text-xs">Contoh: gemini-2.5-flash, gemini-2.0-flash</p>
                </div>

                <div class="space-y-2">
                    <Label for="base_url">Base URL (opsional)</Label>
                    <Input
                        id="base_url"
                        v-model="form.base_url"
                        type="text"
                        placeholder="Kosongkan untuk default Google API"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.base_url" />
                    <p class="text-muted-foreground text-xs">
                        Untuk proxy / bypass region. Contoh:
                        https://generativelanguage.googleapis.com/v1beta/models
                    </p>
                </div>

                <div class="rounded-md border border-dashed p-4">
                    <p class="text-sm font-medium">API key saat ini</p>
                    <p class="text-muted-foreground mt-1 text-sm">
                        {{ settings.key_count }} key terkonfigurasi
                        <span v-if="settings.timeout"> · timeout HTTP: {{ settings.timeout }}s (dari .env)</span>
                    </p>
                    <ul v-if="settings.keys_preview.length" class="mt-2 space-y-1 font-mono text-sm">
                        <li v-for="(k, i) in settings.keys_preview" :key="i">{{ k.mask }}</li>
                    </ul>
                    <p v-else class="text-muted-foreground mt-2 text-sm">Belum ada key (isi dari .env atau ganti di bawah).</p>
                </div>

                <div class="flex items-start gap-3">
                    <input
                        id="replace"
                        v-model="replaceKeys"
                        type="checkbox"
                        class="border-input text-primary focus-visible:ring-ring mt-1 h-4 w-4 shrink-0 rounded border shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                    />
                    <div class="min-w-0 flex-1 space-y-1">
                        <Label for="replace" class="cursor-pointer font-normal leading-snug">
                            Ganti semua API key
                        </Label>
                        <p class="text-muted-foreground text-xs">
                            Setelah dicentang, kolom untuk menempel key muncul di bawah (satu baris = satu key).
                        </p>
                    </div>
                </div>

                <div v-show="replaceKeys" class="space-y-2">
                    <Label for="api_keys_text">Daftar API key (satu baris per key)</Label>
                    <textarea
                        id="api_keys_text"
                        v-model="form.api_keys_text"
                        rows="5"
                        :class="
                            cn(
                                'border-input placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 flex w-full min-w-0 rounded-md border bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none md:text-sm',
                                'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]',
                            )
                        "
                        placeholder="AIza..."
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.api_keys_text" />
                </div>

                <div class="flex gap-2">
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Menyimpan…' : 'Simpan' }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
