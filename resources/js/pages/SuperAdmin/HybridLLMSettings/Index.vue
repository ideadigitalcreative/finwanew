<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { useSweetAlert } from '@/composables/useSweetAlert';
import axios from 'axios';
import { Workflow, PlugZap, Loader2 } from 'lucide-vue-next';

interface Settings {
    enabled: boolean;
    base_url: string;
    model: string;
    api_key_mask: string;
    api_key_set: boolean;
    timeout: number;
}

interface Props {
    settings: Settings;
}

const props = defineProps<Props>();
const { showSuccess, showError } = useSweetAlert();

const form = useForm({
    enabled: props.settings.enabled,
    base_url: props.settings.base_url ?? '',
    model: props.settings.model ?? '',
    api_key: '',
    timeout: props.settings.timeout ?? 4,
});

const breadcrumbs = computed(() => [
    { title: 'Super Admin', href: '/superadmin/dashboard' },
    { title: 'Hybrid LLM AI', href: '/superadmin/hybrid-llm-settings' },
]);

const submit = () => {
    form.put('/superadmin/hybrid-llm-settings', {
        preserveScroll: true,
        onSuccess: () => {
            showSuccess('Tersimpan', 'Pengaturan Hybrid LLM berhasil diperbarui.');
            form.api_key = '';
        },
    });
};

const testing = ref(false);
const testResult = ref<{ ok: boolean; message: string; model?: string; reply?: string; latency_ms?: number } | null>(null);

const testConnection = async () => {
    testing.value = true;
    testResult.value = null;
    try {
        const { data } = await axios.post('/superadmin/hybrid-llm-settings/test', {}, { timeout: 70000 });
        testResult.value = data;
        if (data.ok) {
            showSuccess('Terhubung', `${data.message} Model: ${data.model} — ${data.latency_ms} ms`);
        } else {
            showError('Gagal', data.message);
        }
    } catch (e: any) {
        testResult.value = {
            ok: false,
            message: e?.response?.data?.message ?? 'Permintaan gagal. Cek koneksi jaringan.',
        };
        showError('Gagal', testResult.value.message);
    } finally {
        testing.value = false;
    }
};
</script>

<template>
    <Head title="Pengaturan Hybrid LLM AI" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-2xl space-y-8 p-4 md:p-8">
            <div class="flex items-start gap-3">
                <div class="rounded-lg bg-primary/10 p-2">
                    <Workflow class="h-6 w-6 text-primary" />
                </div>
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Pengaturan Hybrid LLM AI</h1>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Endpoint OpenAI-compatible (chat completion) untuk ekstraksi transaksi hybrid. Saat nonaktif,
                        aplikasi memakai rules murni seperti biasa.
                    </p>
                </div>
            </div>

            <form class="space-y-6" @submit.prevent="submit">
                <div class="flex items-start gap-3">
                    <input
                        id="enabled"
                        v-model="form.enabled"
                        type="checkbox"
                        class="border-input text-primary focus-visible:ring-ring mt-1 h-4 w-4 shrink-0 rounded border shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                    />
                    <div class="min-w-0 flex-1 space-y-1">
                        <Label for="enabled" class="cursor-pointer font-normal leading-snug">
                            Aktifkan ekstraksi hybrid (LLM)
                        </Label>
                        <p class="text-muted-foreground text-xs">
                            Hasil LLM divalidasi validator deterministik dulu sebelum dipakai (Hybrid.md §6A).
                        </p>
                    </div>
                </div>

                <div class="space-y-2">
                    <Label for="base_url">Request URL</Label>
                    <Input
                        id="base_url"
                        v-model="form.base_url"
                        type="text"
                        placeholder="https://api.openai.com/v1"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.base_url" />
                    <p class="text-muted-foreground text-xs">
                        Basis endpoint, tanpa /chat/completions (otomatis ditambahkan). Domain wajib HTTPS;
                        http diizinkan untuk localhost atau alamat IP.
                    </p>
                </div>

                <div class="space-y-2">
                    <Label for="model">Model ID</Label>
                    <Input
                        id="model"
                        v-model="form.model"
                        type="text"
                        required
                        placeholder="gpt-4o-mini"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.model" />
                </div>

                <div class="space-y-2">
                    <Label for="api_key">API Key</Label>
                    <Input
                        id="api_key"
                        v-model="form.api_key"
                        type="password"
                        :placeholder="settings.api_key_set ? `Tersimpan (${settings.api_key_mask}) — kosongkan untuk mempertahankan` : 'sk-...'"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.api_key" />
                    <p class="text-muted-foreground text-xs">
                        Ditulis sekali dan disimpan ter-encrypt. Kosongkan bila tidak ingin mengganti key lama.
                    </p>
                </div>

                <div class="space-y-2">
                    <Label for="timeout">Timeout (detik)</Label>
                    <Input id="timeout" v-model.number="form.timeout" type="number" min="1" max="60" class="max-w-32" />
                    <InputError :message="form.errors.timeout" />
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Menyimpan...' : 'Simpan' }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="testing"
                        @click="testConnection"
                    >
                        <Loader2 v-if="testing" class="h-4 w-4 animate-spin" />
                        <PlugZap v-else class="h-4 w-4" />
                        {{ testing ? 'Menguji koneksi...' : 'Tes Koneksi AI' }}
                    </Button>
                </div>

                <div
                    v-if="testResult"
                    class="rounded-lg border p-3 text-sm"
                    :class="testResult.ok ? 'border-green-300 bg-green-50 text-green-800' : 'border-red-300 bg-red-50 text-red-800'"
                >
                    <p class="font-medium">{{ testResult.message }}</p>
                    <p v-if="testResult.ok" class="mt-1 text-xs">
                        Model: {{ testResult.model }} · Waktu: {{ testResult.latency_ms }} ms · Balasan: "{{ testResult.reply }}"
                    </p>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
