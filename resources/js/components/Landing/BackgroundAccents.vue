<template>
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden bg-gray-50/50">
        <div class="absolute -top-[20%] -left-[10%] h-[50%] w-[50%] rounded-full blur-[120px] bg-emerald-500/10 animate-pulse-slow"></div>
        <div class="absolute top-[40%] -right-[10%] h-[40%] w-[40%] rounded-full blur-[100px] bg-cyan-500/10 animate-pulse-slow animation-delay-2000"></div>
        <div class="absolute -bottom-[10%] left-[20%] h-[40%] w-[40%] rounded-full blur-[100px] bg-blue-500/5 animate-pulse-slow animation-delay-4000"></div>
        <div class="absolute inset-0" style="background-image: radial-gradient(circle at 50% 50%, rgba(255,255,255,0.8) 0%, transparent 100%);"></div>
    </div>
</template>

<script setup lang="ts">
</script>

