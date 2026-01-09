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
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel v-if="label" class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 px-3 py-2">
            {{ label }}
        </SidebarGroupLabel>
        <SidebarMenu class="space-y-1">
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
                                class="w-full rounded-xl transition-colors duration-200 hover:bg-gray-100 dark:hover:bg-gray-800"
                            >
                                <component :is="item.icon" v-if="item.icon" class="size-5 transition-transform duration-200 group-hover:scale-110" />
                                <span class="flex-1 text-sm font-medium transition-colors">{{ item.title }}</span>
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
                        class="group relative rounded-xl transition-all duration-200 hover:bg-gray-100 dark:hover:bg-gray-800 data-[active=true]:bg-gradient-to-r data-[active=true]:from-green-50 data-[active=true]:to-emerald-50 dark:data-[active=true]:from-green-900/20 dark:data-[active=true]:to-emerald-900/20 data-[active=true]:text-green-700 dark:data-[active=true]:text-green-400 data-[active=true]:shadow-sm [&>svg]:size-5 [&>span]:text-sm [&>span]:font-medium"
                    >
                        <a 
                            v-if="item.href && typeof item.href === 'string' && isExternalLink(item.href)" 
                            :href="item.href" 
                            :id="`desktop-menu-${item.title.toLowerCase().replace(/\s+/g, '-')}`"
                            target="_blank" 
                            rel="noopener noreferrer"
                            class="flex items-center gap-3 px-3 py-2.5"
                        >
                            <component :is="item.icon" class="transition-transform duration-200 group-hover:scale-110" />
                            <span class="transition-all duration-200">{{ item.title }}</span>
                        </a>
                        <Link 
                            v-else-if="item.href" 
                            :href="item.href"
                            :id="`desktop-menu-${item.title.toLowerCase().replace(/\s+/g, '-')}`"
                            class="flex items-center gap-3 px-3 py-2.5"
                        >
                            <component :is="item.icon" class="transition-transform duration-200 group-hover:scale-110" />
                            <span class="transition-all duration-200">{{ item.title }}</span>
                        </Link>
                    </SidebarMenuButton>
                    <SidebarMenuBadge 
                        v-if="item.badge && item.badge > 0" 
                        class="bg-red-500 text-white font-semibold shadow-sm"
                    >
                        {{ item.badge > 99 ? '99+' : item.badge }}
                    </SidebarMenuBadge>
                </SidebarMenuItem>
            </template>
        </SidebarMenu>
    </SidebarGroup>
</template>
