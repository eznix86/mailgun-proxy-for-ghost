import type { ComponentPropsWithoutRef } from 'react';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarMenu,
    SidebarMenuItem,
    sidebarMenuButtonVariants,
} from '@/components/ui/sidebar';
import { cn, toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

export function NavFooter({
    items,
    className,
    ...props
}: ComponentPropsWithoutRef<typeof SidebarGroup> & {
    items: NavItem[];
}) {
    return (
        <SidebarGroup
            {...props}
            className={`group-data-[collapsible=icon]:p-0 ${className || ''}`}
        >
            <SidebarGroupContent>
                <SidebarMenu>
                    {items.map((item) => (
                        <SidebarMenuItem key={item.title}>
                            <a
                                href={toUrl(item.href)}
                                target="_blank"
                                rel="noopener noreferrer"
                                className={cn(
                                    sidebarMenuButtonVariants(),
                                    'text-neutral-600 hover:text-neutral-800 dark:text-neutral-300 dark:hover:text-neutral-100',
                                )}
                                data-sidebar="menu-button"
                                data-slot="sidebar-menu-button"
                                data-size="default"
                            >
                                {item.icon && <item.icon className="h-5 w-5" />}
                                <span>{item.title}</span>
                            </a>
                        </SidebarMenuItem>
                    ))}
                </SidebarMenu>
            </SidebarGroupContent>
        </SidebarGroup>
    );
}
