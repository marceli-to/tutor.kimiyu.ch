<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ImagePlus,
    LayoutGrid,
    Receipt,
    ShieldCheck,
    Users,
} from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { costs, dashboard, privacy } from '@/routes';
import { index as childrenIndex } from '@/routes/children';
import { create } from '@/routes/lessons';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Übersicht',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Neue Lernseite',
        href: create(),
        icon: ImagePlus,
    },
    {
        title: 'Kinder',
        href: childrenIndex(),
        icon: Users,
    },
    {
        title: 'Kosten',
        href: costs(),
        icon: Receipt,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Datenschutz',
        href: privacy(),
        icon: ShieldCheck,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
