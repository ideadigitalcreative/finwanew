<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import MobileFooter from '@/components/MobileFooter.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <!-- md:!mt-0 & md:!rounded-t-none: header hijau menempel ke tepi atas layar (tanpa strip putih di atasnya) -->
        <AppContent variant="sidebar" class="overflow-x-hidden pb-20 md:pb-0 md:!mt-0 md:!rounded-t-none">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
        </AppContent>
        <MobileFooter />
    </AppShell>
</template>
