<script setup lang="ts">
import { inject } from 'vue';

const props = defineProps<{
  placeholder?: string;
}>();

const select = inject<{
  selectedValue: { value: string | number | null };
}>('select');

if (!select) {
  throw new Error('SelectValue must be used inside Select');
}
</script>

<template>
  <span v-if="select.selectedValue.value === null || select.selectedValue.value === undefined" class="text-muted-foreground">
    {{ props.placeholder || 'Pilih...' }}
  </span>
  <span v-else>
    <slot :value="select.selectedValue.value">
      {{ select.selectedValue.value }}
    </slot>
  </span>
</template>

