import { Link } from '@inertiajs/react';
import { HeartPulse, LayoutGrid } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuItem,
    sidebarMenuButtonVariants,
} from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import { dashboard, health } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Health',
        href: health(),
        icon: HeartPulse,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <Link
                            href={dashboard()}
                            prefetch
                            className={cn(
                                sidebarMenuButtonVariants({ size: 'lg' }),
                            )}
                            data-sidebar="menu-button"
                            data-slot="sidebar-menu-button"
                            data-size="lg"
                        >
                            <AppLogo />
                        </Link>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
