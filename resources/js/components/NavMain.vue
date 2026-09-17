<script setup lang="ts">
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { ChevronDown } from 'lucide-vue-next';
import { urlIsActive } from '@/lib/utils';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';

withDefaults(defineProps<{
    items: NavItem[];
    label?: string;
}>(), {
    label: 'Platform',
});

const page = usePage();

const isExternalLink = (href: NonNullable<NavItem['href']>): href is string => {
    return typeof href === 'string' && (href.startsWith('http://') || href.startsWith('https://') || href.startsWith('wa.me/') || href.startsWith('mailto:') || href.startsWith('tel:'));
};
</script>

<template>
    <SidebarGroup class="px-2 py-0 font-['Plus_Jakarta_Sans',sans-serif]">
        <SidebarGroupLabel v-if="label" class="text-[10px] font-extrabold uppercase tracking-widest text-[#7f7661] dark:text-[#6b6a5e] px-3 py-2 mb-1">
            {{ label }}
        </SidebarGroupLabel>
        <SidebarMenu class="space-y-0.5">
            <template v-for="item in items" :key="item.title">
                <!-- Collapsible Submenu -->
                <Collapsible
                    v-if="item.items && item.items.length > 0"
                    as-child
                    :default-open="item.isActive"
                    class="group/collapsible"
                >
                    <SidebarMenuItem>
                        <CollapsibleTrigger as-child>
                            <SidebarMenuButton
                                :tooltip="item.title"
                                size="lg"
                                class="w-full rounded-xl transition-colors duration-150 hover:bg-[#eae8e2] dark:hover:bg-white/5 text-[#4d4634] dark:text-[#9ca3af]"
                            >
                                <span v-if="item.materialIcon" class="material-symbols-outlined text-[20px]" style="font-variation-settings: 'FILL' 0, 'wght' 500">{{ item.materialIcon }}</span>
                                <component :is="item.icon" v-else-if="item.icon" class="size-5" />
                                <span class="flex-1 text-sm font-medium">{{ item.title }}</span>
                                <ChevronDown class="ml-auto size-4 transition-transform duration-200 group-data-[state=open]/collapsible:rotate-180" />
                            </SidebarMenuButton>
                        </CollapsibleTrigger>
                        <CollapsibleContent class="animate-in fade-in slide-in-from-top-1 duration-200">
                            <SidebarMenuSub>
                                <SidebarMenuSubItem v-for="subItem in item.items" :key="subItem.title">
                                    <SidebarMenuSubButton as-child :is-active="subItem.href && typeof subItem.href === 'string' ? !!urlIsActive(subItem.href, page.url) : false">
                                        <a v-if="subItem.href && typeof subItem.href === 'string' && isExternalLink(subItem.href)" :href="subItem.href" target="_blank" rel="noopener noreferrer">
                                            <span>{{ subItem.title }}</span>
                                        </a>
                                        <Link v-else-if="subItem.href" :href="subItem.href">
                                            <span>{{ subItem.title }}</span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            </SidebarMenuSub>
                        </CollapsibleContent>
                    </SidebarMenuItem>
                </Collapsible>

                <!-- Single Menu Item -->
                <SidebarMenuItem v-else>
                    <SidebarMenuButton
                        as-child
                        size="lg"
                        :is-active="item.href && typeof item.href === 'string' && !isExternalLink(item.href) ? !!urlIsActive(item.href, page.url) : false"
                        :tooltip="item.title"
                        class="group relative rounded-xl transition-colors duration-150 text-[#4d4634] dark:text-[#a29f90] hover:bg-[#f0eee8] hover:text-[#1b1c19] dark:hover:bg-white/5 dark:hover:text-white data-[active=true]:bg-[#ffd23f] data-[active=true]:text-[#574500] dark:data-[active=true]:bg-[#ffd23f] dark:data-[active=true]:text-[#241a00] data-[active=true]:shadow-[2px_2px_0px_#1b1c19] [&>span]:text-sm [&>span]:font-bold"
                    >
                        <a 
                            v-if="item.href && typeof item.href === 'string' && isExternalLink(item.href)" 
                            :href="item.href" 
                            :id="`desktop-menu-${item.title.toLowerCase().replace(/\s+/g, '-')}`"
                            target="_blank" 
                            rel="noopener noreferrer"
                            class="flex items-center gap-3 px-3 py-2.5"
                        >
                            <span v-if="item.materialIcon" class="material-symbols-outlined text-[20px]" style="font-variation-settings: 'FILL' 0, 'wght' 500">{{ item.materialIcon }}</span>
                            <component :is="item.icon" v-else class="size-5" />
                            <span>{{ item.title }}</span>
                        </a>
                        <Link 
                            v-else-if="item.href" 
                            :href="item.href"
                            :id="`desktop-menu-${item.title.toLowerCase().replace(/\s+/g, '-')}`"
                            class="flex items-center gap-3 px-3 py-2.5"
                        >
                            <span v-if="item.materialIcon" class="material-symbols-outlined text-[20px]" style="font-variation-settings: 'FILL' 0, 'wght' 500">{{ item.materialIcon }}</span>
                            <component :is="item.icon" v-else class="size-5" />
                            <span>{{ item.title }}</span>
                        </Link>
                    </SidebarMenuButton>
                    <SidebarMenuBadge 
                        v-if="item.badge && item.badge > 0" 
                        class="bg-[#ffc9d0] text-[#93000a] font-extrabold text-[10px] rounded-full px-1.5"
                    >
                        {{ item.badge > 99 ? '99+' : item.badge }}
                    </SidebarMenuBadge>
                </SidebarMenuItem>
            </template>
        </SidebarMenu>
    </SidebarGroup>
</template>
