<script setup lang="ts">
import { useSidebar } from '@/components/ui/sidebar/utils';
import { X, Zap } from 'lucide-vue-next';
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';

const { state } = useSidebar();
const isVisible = ref(true);

const close = () => {
    isVisible.value = false;
};
</script>

<template>
    <div v-if="isVisible && state === 'expanded'" class="px-3 mb-3 animate-in fade-in slide-in-from-bottom-4 duration-500 font-['Plus_Jakarta_Sans',sans-serif]">
        <div class="relative p-3.5 rounded-2xl bg-[#ffd23f]/15 dark:bg-[#ffd23f]/10 border border-[#ffd23f]/40 dark:border-[#ffd23f]/20 overflow-hidden group">
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-1">
                    <h4 class="font-bold text-xs text-[#574500] dark:text-[#ffd23f] flex items-center gap-1.5">
                        Upgrade Pro 👑
                    </h4>
                    <button @click="close" class="text-[#7f7661] hover:text-[#1b1c19] dark:hover:text-foreground transition-colors p-1 rounded-lg">
                        <X class="w-3 h-3" />
                    </button>
                </div>
                
                <p class="text-[11px] text-[#4d4634] dark:text-muted-foreground mb-3 leading-snug">
                    Buka fitur tanpa batas & pantau cuan lebih leluasa.
                </p>
                
                <div class="flex">
                    <Link 
                        href="/subscriptions" 
                        class="w-full flex items-center justify-center gap-1.5 px-3 py-2 bg-[#ffd23f] hover:brightness-95 text-[#574500] rounded-xl text-xs font-bold transition-transform active:scale-95"
                    >
                        <Zap class="w-3.5 h-3.5 fill-[#574500]" />
                        <span>Langganan Sekarang</span>
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>
