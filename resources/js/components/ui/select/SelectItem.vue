<script setup lang="ts">
import { inject, computed } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{
  value: string | number;
  class?: string;
  disabled?: boolean;
}>();

const select = inject<{
  select: (value: string | number | null) => void;
  selectedValue: { value: string | number | null };
}>('select');

if (!select) {
  throw new Error('SelectItem must be used inside Select');
}

const isSelected = computed(() => select.selectedValue.value === props.value);

const handleClick = () => {
  if (!props.disabled) {
    select.select(props.value);
  }
};
</script>

<template>
  <div
    :class="cn(
      'relative flex w-full cursor-pointer select-none items-center rounded-sm py-1.5 px-2 text-sm outline-none hover:bg-accent hover:text-accent-foreground focus:bg-accent focus:text-accent-foreground',
      isSelected && 'bg-accent text-accent-foreground',
      props.disabled && 'pointer-events-none opacity-50',
      props.class
    )"
    @click="handleClick"
  >
    <slot />
  </div>
</template>

