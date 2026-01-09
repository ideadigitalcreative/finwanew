<script setup lang="ts">
import { inject, ref, onMounted } from 'vue';
import { cn } from '@/lib/utils';
import { ChevronDown } from 'lucide-vue-next';

const props = defineProps<{
  class?: string;
  disabled?: boolean;
}>();

const select = inject<{
  isOpen: { value: boolean };
  selectedValue: { value: string | number | null };
  triggerRef: { value: HTMLElement | null };
}>('select');

if (!select) {
  throw new Error('SelectTrigger must be used inside Select');
}

const triggerElement = ref<HTMLElement | null>(null);

onMounted(() => {
  if (triggerElement.value) {
    select.triggerRef.value = triggerElement.value;
  }
});

const toggle = () => {
  if (!props.disabled) {
    select.isOpen.value = !select.isOpen.value;
  }
};
</script>

<template>
  <button
    ref="triggerElement"
    type="button"
    class="select-trigger"
    :class="cn(
      'flex h-9 w-full items-center justify-between rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs ring-offset-background placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
      props.class
    )"
    :disabled="props.disabled"
    @click="toggle"
  >
    <span class="flex-1 text-left">
      <slot />
    </span>
    <ChevronDown
      :class="cn(
        'h-4 w-4 opacity-50 transition-transform',
        select.isOpen.value && 'rotate-180'
      )"
    />
  </button>
</template>

