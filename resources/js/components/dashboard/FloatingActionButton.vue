<script setup lang="ts">
const emit = defineEmits<{
    (e: 'click'): void;
}>();
</script>

<template>
    <button
        @click="emit('click')"
        class="fixed bottom-20 right-5 z-50 group sm:bottom-6 sm:right-6"
        aria-label="Tambah Transaksi"
    >
        <span class="absolute inset-0 rounded-full bg-orange-400/40 animate-ping"></span>
        <img
            src="/Circle.svg"
            alt="Tambah Transaksi"
            class="relative w-14 h-14 sm:w-16 sm:h-16 drop-shadow-lg hover:scale-110 active:scale-95 transition-all duration-300"
        />
    </button>
</template>
