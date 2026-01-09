<script setup lang="ts">
import { useSidebar } from '@/components/ui/sidebar';

const { state } = useSidebar();
</script>

<template>
    <div class="flex items-center gap-2">
        <!-- Logo lengkap saat sidebar expanded -->
        <img 
            v-if="state === 'expanded'" 
            src="/logo1.png" 
            alt="Logo FinWa Aplikasi Keuangan" 
            class="h-8 w-auto transition-all duration-200" 
        />
        <!-- Logo icon saja saat sidebar collapsed -->
        <img 
            v-else 
            src="/logo3.png" 
            alt="FinWa Icon" 
            class="h-8 w-8 transition-all duration-200" 
        />
    </div>
</template>
