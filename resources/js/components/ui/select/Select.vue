<script setup lang="ts">
import { provide, ref, computed } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{
  modelValue?: string | number | null;
  disabled?: boolean;
  class?: string;
}>();

const emit = defineEmits<{
  (e: 'update:modelValue', value: string | number | null): void;
}>();

const isOpen = ref(false);
const triggerRef = ref<HTMLElement | null>(null);
const selectedValue = computed({
  get: () => props.modelValue ?? null,
  set: (value) => emit('update:modelValue', value),
});

provide('select', {
  isOpen,
  selectedValue,
  triggerRef,
  select: (value: string | number | null) => {
    selectedValue.value = value;
    isOpen.value = false;
  },
});
</script>

<template>
  <div :class="cn('relative', props.class)">
    <slot />
  </div>
</template>

