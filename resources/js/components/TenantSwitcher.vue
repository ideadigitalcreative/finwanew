<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Button } from '@/components/ui/button';
import { Building2, Check } from 'lucide-vue-next';

const page = usePage();
const auth = computed(() => page.props.auth);

const tenants = computed(() => auth.value?.user?.tenants || []);
const currentTenantId = computed(() => auth.value?.user?.current_tenant_id);

const switchTenant = (tenantId: number) => {
    router.post(`/tenants/${tenantId}/switch`, {}, {
        preserveState: false,
        preserveScroll: false,
    });
};

const currentTenant = computed(() => {
    return tenants.value.find(t => t.id === currentTenantId.value) || tenants.value[0];
});
</script>

<template>
    <DropdownMenu v-if="tenants.length > 1">
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                class="flex items-center gap-2"
            >
                <Building2 class="h-4 w-4" />
                <span class="hidden md:inline">{{ currentTenant?.name || 'Tenant' }}</span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" class="w-56">
            <DropdownMenuLabel>Pilih Tenant</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                v-for="tenant in tenants"
                :key="tenant.id"
                @click="switchTenant(tenant.id)"
                class="flex items-center justify-between cursor-pointer"
            >
                <div class="flex flex-col">
                    <span class="font-medium">{{ tenant.name }}</span>
                    <span class="text-xs text-muted-foreground">{{ tenant.role?.name || 'No role' }}</span>
                </div>
                <Check
                    v-if="tenant.is_current"
                    class="h-4 w-4 text-primary"
                />
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem as-child>
                <a href="/tenants" class="cursor-pointer">
                    Kelola Tenant
                </a>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>

