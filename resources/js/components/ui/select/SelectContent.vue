<script setup lang="ts">
import { inject, onMounted, onUnmounted, ref, nextTick, watch } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{
  class?: string;
}>();

const select = inject<{
  isOpen: { value: boolean };
  triggerRef: { value: HTMLElement | null };
}>('select');

if (!select) {
  throw new Error('SelectContent must be used inside Select');
}

const contentRef = ref<HTMLElement | null>(null);
const position = ref({ top: 0, left: 0, width: 0 });

const updatePosition = () => {
  nextTick(() => {
    const trigger = select.triggerRef.value;
    if (trigger && contentRef.value) {
      const rect = trigger.getBoundingClientRect();
      position.value = {
        top: rect.bottom + window.scrollY + 4,
        left: rect.left + window.scrollX,
        width: rect.width,
      };
      if (contentRef.value) {
        contentRef.value.style.top = `${position.value.top}px`;
        contentRef.value.style.left = `${position.value.left}px`;
        contentRef.value.style.width = `${position.value.width}px`;
      }
    }
  });
};

const handleClickOutside = (event: MouseEvent) => {
  const target = event.target as HTMLElement;
  if (contentRef.value && !contentRef.value.contains(target)) {
    const trigger = target.closest('.select-trigger');
    if (!trigger) {
      select.isOpen.value = false;
    }
  }
};

watch(() => select.isOpen.value, (isOpen) => {
  if (isOpen) {
    updatePosition();
  }
});

onMounted(() => {
  document.addEventListener('click', handleClickOutside);
  window.addEventListener('resize', updatePosition);
  window.addEventListener('scroll', updatePosition, true);
});

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside);
  window.removeEventListener('resize', updatePosition);
  window.removeEventListener('scroll', updatePosition, true);
});
</script>

<template>
  <Teleport to="body">
    <div
      v-if="select.isOpen.value"
      ref="contentRef"
      :class="cn(
        'select-content fixed z-50 min-w-[8rem] overflow-hidden rounded-md border bg-card text-card-foreground shadow-md',
        props.class
      )"
    >
      <div class="p-1 max-h-[300px] overflow-auto">
        <slot />
      </div>
    </div>
  </Teleport>
</template>

