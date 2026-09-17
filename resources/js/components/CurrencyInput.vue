<script setup lang="ts">
import { ref, watch } from 'vue';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        modelValue?: string | number | null;
        placeholder?: string;
        required?: boolean;
        class?: string;
    }>(),
    {
        modelValue: null,
        placeholder: '',
        required: false,
        class: '',
    }
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: number | null): void;
}>();

const stripNonDigits = (value: string) => value.replace(/\D/g, '');

const formatWithSeparators = (value: string) => {
    const digits = stripNonDigits(value);
    return digits ? Number(digits).toLocaleString('id-ID') : '';
};

const getInitialDisplay = () => {
    if (props.modelValue == null || props.modelValue === '') return '';
    return formatWithSeparators(String(props.modelValue));
};

const display = ref(getInitialDisplay());

// Sinkronisasi saat parent mengubah nilai (misal reset form)
watch(
    () => props.modelValue,
    (value) => {
        if (value == null || value === '') {
            if (display.value !== '') display.value = '';
            return;
        }
        const formatted = formatWithSeparators(String(value));
        if (formatted !== display.value) display.value = formatted;
    }
);

const onInput = (event: Event) => {
    const raw = (event.target as HTMLInputElement).value;
    const digits = stripNonDigits(raw);
    display.value = digits ? Number(digits).toLocaleString('id-ID') : '';
    emit('update:modelValue', digits === '' ? null : Number(digits));
};
</script>

<template>
    <div class="relative w-full" :class="$props.class">
        <span
            class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm font-semibold text-[#4d4634]/60 dark:text-muted-foreground select-none"
        >
            Rp
        </span>
        <input
            v-bind="$attrs"
            :value="display"
            @input="onInput"
            type="text"
            inputmode="numeric"
            autocomplete="off"
            :placeholder="placeholder"
            :required="required"
            class="placeholder:text-muted-foreground border-input flex h-9 w-full rounded-md border border-[#eae8e2] bg-[#f5f3ee] px-3 py-1 pl-9 text-base shadow-xs transition-[color,box-shadow] outline-none disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] focus:ring-[#ffd23f] focus:border-[#ffd23f]"
        />
    </div>
</template>