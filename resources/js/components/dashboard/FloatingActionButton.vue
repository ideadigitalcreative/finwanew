<script setup lang="ts">
const emit = defineEmits<{
    (e: 'click'): void;
}>();
</script>

<template>
    <button
        @click="emit('click')"
        class="fixed bottom-20 right-5 z-50 group sm:bottom-6 sm:right-6 focus:outline-none"
        aria-label="Tambah Transaksi"
    >
        <!-- Outer Glow & Coin Container -->
        <div class="relative w-14 h-14 sm:w-16 sm:h-16 flex items-center justify-center transition-transform duration-300 group-hover:scale-110 group-active:scale-95">
            <!-- Ambient Golden Glow -->
            <div class="absolute inset-0 rounded-full bg-[#ffd23f]/40 blur-md animate-pulse"></div>

            <!-- 3D Animated Gold Coin -->
            <div class="coin-wrapper w-full h-full relative flex items-center justify-center">
                <!-- Coin Body -->
                <div class="coin w-full h-full rounded-full relative flex items-center justify-center">
                    <!-- Outer Edge / Border with 3D bevel look -->
                    <div class="absolute inset-0 rounded-full bg-gradient-to-tr from-[#c49200] via-[#ffd23f] to-[#fff4b8] p-[3px] shadow-[0_4px_12px_rgba(212,160,23,0.45)]">
                        <!-- Inner Face -->
                        <div class="w-full h-full rounded-full bg-gradient-to-br from-[#ffd23f] via-[#f7b731] to-[#e19a00] flex items-center justify-center relative overflow-hidden border border-[#ffeaa7]/70">
                            <!-- Shimmer Light Sweep -->
                            <div class="shimmer-sweep absolute inset-0 bg-gradient-to-r from-transparent via-white/40 to-transparent -skew-x-12"></div>
                            
                            <!-- Coin Inner Ring -->
                            <div class="w-[82%] h-[82%] rounded-full border border-dashed border-[#8d6900]/30 flex items-center justify-center">
                                <!-- Plus / Add Symbol with Embossed 3D Feel -->
                                <span class="material-symbols-outlined text-[28px] sm:text-[32px] text-[#5e4400] font-black drop-shadow-[0_1px_1px_rgba(255,255,255,0.7)] select-none">
                                    add
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </button>
</template>

<style scoped>
/* Shimmer Sweep Animation */
.shimmer-sweep {
    animation: shimmer 3s ease-in-out infinite;
    transform: translateX(-150%);
}

@keyframes shimmer {
    0%, 20% {
        transform: translateX(-150%) skewX(-20deg);
    }
    60%, 100% {
        transform: translateX(150%) skewX(-20deg);
    }
}
</style>
