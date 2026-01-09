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
    <div v-if="isVisible && state === 'expanded'" class="px-3 mb-4 animate-in fade-in slide-in-from-bottom-4 duration-500">
        <div class="relative p-4 rounded-2xl bg-gradient-to-br from-primary/10 to-accent/20 backdrop-blur-xl border border-primary/10 shadow-lg shadow-primary/5 overflow-hidden group">
            <!-- Decorative background elements -->
            <div class="absolute -right-4 -top-4 w-20 h-20 bg-primary/10 rounded-full blur-2xl group-hover:bg-primary/20 transition-colors duration-500"></div>
            
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-1">
                    <h4 class="font-semibold text-sm text-foreground flex items-center gap-1.5">
                        Upgrade Pro! 👑
                    </h4>
                    <button @click="close" class="text-muted-foreground hover:text-foreground transition-colors p-1 rounded-lg hover:bg-black/5 dark:hover:bg-white/5">
                        <X class="w-3.5 h-3.5" />
                    </button>
                </div>
                
                <p class="text-xs text-muted-foreground mb-4 leading-relaxed">
                    Produktivitas lebih tinggi dengan fitur lengkap
                </p>
                
                <div class="flex gap-3">
                    <Link 
                        href="/subscriptions" 
                        class="flex-1 flex items-center justify-center gap-2 px-4 py-2 bg-primary/90 hover:bg-primary backdrop-blur-sm text-primary-foreground rounded-xl text-xs font-medium shadow-lg shadow-primary/20 transition-all active:scale-95"
                    >
                        <Zap class="w-3.5 h-3.5" />
                        Upgrade
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>
