<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import NavUpgrade from '@/components/NavUpgrade.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { LayoutGrid, Receipt, CreditCard, MessageSquare, Wallet, Shield, Users, Building2, HelpCircle, PiggyBank, Scale, Sparkles, Search } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import superadminRoutes from '@/routes/superadmin/index';

const superadmin = {
    dashboard: () => superadminRoutes.dashboard.url(),
    users: {
        index: () => superadminRoutes.users.index.url(),
        store: () => superadminRoutes.users.store.url(),
        update: (id: number) => superadminRoutes.users.update.url({ user: id }),
        destroy: (id: number) => superadminRoutes.users.destroy.url({ user: id }),
    },
    subscriptions: {
        index: () => superadminRoutes.subscriptions.index.url(),
        update: (id: number) => superadminRoutes.subscriptions.update.url({ subscription: id }),
        extend: (id: number) => superadminRoutes.subscriptions.extend.url({ subscription: id }),
    },
    banks: {
        index: () => superadminRoutes.banks.index.url(),
        store: () => superadminRoutes.banks.store.url(),
        update: (id: number) => superadminRoutes.banks.update.url({ bank: id }),
        destroy: (id: number) => superadminRoutes.banks.destroy.url({ bank: id }),
    },
    whatsapp: {
        index: () => superadminRoutes.whatsapp.index.url(),
    },
    broadcast: {
        index: () => '/superadmin/broadcast',
    },
};

const page = usePage();
const auth = computed(() => page.props.auth as any);
const isSuperAdmin = computed(() => (auth.value?.user as any)?.is_super_admin ?? false);
const pendingSubscriptionsCount = computed(() => (page.props as any).pending_subscriptions_count ?? 0);
const isPremiumUser = computed(() => (auth.value?.user as any)?.is_premium ?? false);

// Menu items untuk user biasa
const regularNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'Transaksi',
        href: '/transactions',
        icon: Receipt,
    },
    {
        title: 'Saldo Akun',
        href: '/balances',
        icon: Wallet,
    },
    {
        title: 'Budget',
        href: '/budgets',
        icon: PiggyBank,
    },
    {
        title: 'Hutang & Piutang',
        href: '/hutang-piutang',
        icon: Scale,
    },
    {
        title: 'Subscription',
        href: '/subscriptions',
        icon: CreditCard,
    },
    {
        title: 'WhatsApp',
        href: '/whatsapp',
        icon: MessageSquare,
    },
    {
        title: 'Support',
        href: 'https://wa.me/6285242766676?text=saya%20butuh%20bantuan',
        icon: HelpCircle,
    },
];

// Menu items untuk super admin (tanpa Dashboard, Transaksi, Saldo Akun, Subscription, WhatsApp)
const superAdminMainNavItems: NavItem[] = [];

// Computed menu items berdasarkan role
const mainNavItems = computed(() => {
    return isSuperAdmin.value ? superAdminMainNavItems : regularNavItems;
});

// Super admin nav items dengan badge untuk pending subscriptions
const superAdminNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: superadmin.dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'User Management',
        href: superadmin.users.index(),
        icon: Users,
    },
    {
        title: 'Subscription',
        href: superadmin.subscriptions.index(),
        icon: CreditCard,
        badge: pendingSubscriptionsCount.value,
    },
    {
        title: 'WhatsApp',
        href: superadmin.whatsapp.index(),
        icon: MessageSquare,
    },
    {
        title: 'Broadcast',
        href: superadmin.broadcast.index(),
        icon: MessageSquare,
    },
    {
        title: 'Bank Management',
        href: superadmin.banks.index(),
        icon: Building2,
    },
    {
        title: 'Gemini AI',
        href: '/superadmin/gemini-settings',
        icon: Sparkles,
    },
    {
        title: 'Risen AI (SEO)',
        href: '/admin/risen-ai',
        icon: Search,
    },
    {
        title: 'Support',
        href: 'https://wa.me/6285242766676?text=saya%20butuh%20bantuan',
        icon: HelpCircle,
    },
]);

const footerNavItems: NavItem[] = [];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="isSuperAdmin ? superadmin.dashboard() : dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain v-if="!isSuperAdmin" :items="mainNavItems" label="Platform" />
            <NavMain v-if="isSuperAdmin" :items="superAdminNavItems" label="Super Admin" />
        </SidebarContent>

        <SidebarFooter>
            <NavUpgrade v-if="!isSuperAdmin && !isPremiumUser" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
