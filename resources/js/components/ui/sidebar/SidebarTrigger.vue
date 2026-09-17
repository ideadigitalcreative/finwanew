<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { useSidebar } from './utils'

const props = defineProps<{
  class?: HTMLAttributes['class']
}>()

const { toggleSidebar } = useSidebar()
</script>

<template>
  <Button
    data-sidebar="trigger"
    data-slot="sidebar-trigger"
    variant="ghost"
    size="icon"
    :class="cn('h-8 w-8 rounded-xl text-[#4d4634] dark:text-[#a29f90] hover:bg-[#f5f3ee] dark:hover:bg-white/10 transition-colors', props.class)"
    @click="toggleSidebar"
  >
    <span class="material-symbols-outlined text-[20px] select-none">menu</span>
    <span class="sr-only">Toggle Sidebar</span>
  </Button>
</template>
