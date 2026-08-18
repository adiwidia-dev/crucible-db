import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

export function NavMain({
    label,
    items = [],
    collapsible = false,
    defaultOpen = true,
    storageKey,
}: {
    label: string;
    items: NavItem[];
    collapsible?: boolean;
    defaultOpen?: boolean;
    storageKey?: string;
}) {
    const { isCurrentUrl } = useCurrentUrl();
    const hasActiveItem = items.some((item) => isCurrentUrl(item.href));
    const [isOpen, setIsOpen] = useState(() => {
        if (typeof window !== 'undefined' && storageKey) {
            const storedState = window.localStorage.getItem(storageKey);

            if (storedState !== null) {
                return storedState === 'true';
            }
        }

        return defaultOpen || hasActiveItem;
    });

    const handleOpenChange = (open: boolean) => {
        setIsOpen(open);

        if (storageKey) {
            window.localStorage.setItem(storageKey, String(open));
        }
    };

    const menu = (
        <SidebarMenu className="gap-0.5">
            {items.map((item) => (
                <SidebarMenuItem key={item.title}>
                    <SidebarMenuButton
                        asChild
                        isActive={isCurrentUrl(item.href)}
                        className="h-8.5 gap-2.5 rounded-md px-2 text-sm font-medium text-sidebar-foreground/72 transition-colors duration-150 ease-out hover:bg-sidebar-accent/70 hover:text-sidebar-foreground data-[active=true]:bg-sidebar-accent data-[active=true]:text-sidebar-accent-foreground data-[active=true]:shadow-none motion-reduce:transition-none [&>svg]:size-4 data-[active=true]:[&>svg]:text-primary"
                        tooltip={{ children: item.title }}
                    >
                        <Link href={item.href} prefetch>
                            {item.icon && <item.icon />}
                            <span>{item.title}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            ))}
        </SidebarMenu>
    );

    if (!collapsible) {
        return (
            <SidebarGroup className="px-2 py-0 group-data-[collapsible=icon]:px-2">
                <SidebarGroupLabel className="h-7 px-2 text-[11px] font-medium text-sidebar-foreground/50 group-data-[collapsible=icon]:px-2">
                    {label}
                </SidebarGroupLabel>
                {menu}
            </SidebarGroup>
        );
    }

    return (
        <Collapsible open={isOpen} onOpenChange={handleOpenChange}>
            <SidebarGroup className="px-2 py-0 group-data-[collapsible=icon]:px-2">
                <CollapsibleTrigger asChild>
                    <button
                        type="button"
                        className="flex h-7 w-full items-center justify-between rounded-md px-2 text-[11px] font-medium text-sidebar-foreground/50 transition-colors group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-2 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none"
                    >
                        <span className="group-data-[collapsible=icon]:hidden">
                            {label}
                        </span>
                        <ChevronDown
                            className={`size-3.5 transition-transform duration-150 group-data-[collapsible=icon]:hidden motion-reduce:transition-none ${
                                isOpen ? '' : '-rotate-90'
                            }`}
                        />
                    </button>
                </CollapsibleTrigger>
                <CollapsibleContent>{menu}</CollapsibleContent>
            </SidebarGroup>
        </Collapsible>
    );
}
