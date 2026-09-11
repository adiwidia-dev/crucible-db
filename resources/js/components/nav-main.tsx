import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
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
import { cn } from '@/lib/utils';
import type { NavItem } from '@/types';

export type NavSection = {
    label: string;
    items: NavItem[];
};

type NavMainProps = {
    label?: string;
    title?: string;
    icon?: LucideIcon;
    items?: NavItem[];
    sections?: NavSection[];
    collapsible?: boolean;
    defaultOpen?: boolean;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    nestedItems?: boolean;
    className?: string;
};

function NavItems({
    items,
    nested = false,
    onNavigate,
}: {
    items: NavItem[];
    nested?: boolean;
    onNavigate?: () => void;
}) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <SidebarMenu className={cn('gap-0.5', nested && 'pl-7')}>
            {items.map((item) => {
                const isActive = item.isActive || isCurrentUrl(item.href);
                const ItemIcon = item.icon;

                return (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            asChild
                            isActive={isActive}
                            className={cn(
                                'h-8.5 gap-2.5 rounded-md px-2 text-sm font-medium text-sidebar-foreground/72 transition-colors duration-150 ease-out hover:bg-sidebar-accent/70 hover:text-sidebar-foreground data-[active=true]:bg-sidebar-accent data-[active=true]:text-sidebar-accent-foreground data-[active=true]:shadow-none motion-reduce:transition-none [&>svg]:size-4 data-[active=true]:[&>svg]:text-primary',
                                nested && 'h-8 pr-3 pl-3',
                            )}
                            tooltip={{ children: item.title }}
                        >
                            <Link
                                href={item.href}
                                prefetch
                                aria-current={isActive ? 'page' : undefined}
                                onClick={onNavigate}
                            >
                                {!nested && ItemIcon && <ItemIcon />}
                                <span>{item.title}</span>
                                {nested && isActive && (
                                    <span
                                        aria-hidden="true"
                                        className="ml-auto size-1.5 shrink-0 rounded-full bg-primary"
                                    />
                                )}
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                );
            })}
        </SidebarMenu>
    );
}

function NavSectionDisclosure({
    section,
    open,
    onOpenChange,
    onNavigate,
}: {
    section: NavSection;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onNavigate: () => void;
}) {
    const { isCurrentUrl } = useCurrentUrl();
    const hasActiveItem = section.items.some(
        (item) => item.isActive || isCurrentUrl(item.href),
    );

    return (
        <Collapsible open={open} onOpenChange={onOpenChange}>
            <CollapsibleTrigger asChild>
                <button
                    type="button"
                    className={cn(
                        'flex h-8 w-full items-center rounded-md pr-2 pl-8 text-left text-xs font-semibold text-sidebar-foreground/60 transition-colors duration-150 outline-none hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:ring-2 focus-visible:ring-sidebar-ring',
                        hasActiveItem && 'text-sidebar-foreground/80',
                    )}
                >
                    <span className="truncate">{section.label}</span>
                    <ChevronDown
                        aria-hidden="true"
                        className={cn(
                            'ml-auto size-3.5 shrink-0 transition-transform duration-150 motion-reduce:transition-none',
                            !open && '-rotate-90',
                        )}
                    />
                </button>
            </CollapsibleTrigger>
            <CollapsibleContent className="pb-1">
                <NavItems
                    items={section.items}
                    nested
                    onNavigate={onNavigate}
                />
            </CollapsibleContent>
        </Collapsible>
    );
}

export function NavMain({
    label,
    title,
    icon: Icon,
    items = [],
    sections = [],
    collapsible = false,
    defaultOpen = true,
    open,
    onOpenChange,
    nestedItems = false,
    className,
}: NavMainProps) {
    const { currentUrl, isCurrentUrl } = useCurrentUrl();
    const hasActiveItem = [
        ...items,
        ...sections.flatMap((section) => section.items),
    ].some((item) => item.isActive || isCurrentUrl(item.href));
    const activeSectionLabels = sections
        .filter((section) =>
            section.items.some(
                (item) => item.isActive || isCurrentUrl(item.href),
            ),
        )
        .map((section) => section.label);
    const [sectionOpenStates, setSectionOpenStates] = useState<
        Record<string, boolean>
    >(() =>
        Object.fromEntries(activeSectionLabels.map((label) => [label, true])),
    );
    const [internalOpenOverride, setInternalOpenOverride] = useState<{
        url: string;
        open: boolean;
    } | null>(null);
    const isControlled = open !== undefined;
    const internalOpen =
        internalOpenOverride?.url === currentUrl
            ? internalOpenOverride.open
            : defaultOpen || hasActiveItem;
    const isOpen = isControlled ? open : internalOpen;

    const handleOpenChange = (nextOpen: boolean) => {
        if (!isControlled) {
            setInternalOpenOverride({ url: currentUrl, open: nextOpen });
        }

        onOpenChange?.(nextOpen);
    };

    const preserveActiveSections = () => {
        if (activeSectionLabels.length === 0) {
            return;
        }

        setSectionOpenStates((currentStates) => {
            const nextStates = { ...currentStates };
            let hasChanged = false;

            for (const sectionLabel of activeSectionLabels) {
                if (nextStates[sectionLabel] === undefined) {
                    nextStates[sectionLabel] = true;
                    hasChanged = true;
                }
            }

            return hasChanged ? nextStates : currentStates;
        });
    };

    const handleSectionOpenChange = (
        sectionLabel: string,
        nextOpen: boolean,
    ) => {
        setSectionOpenStates((currentStates) => {
            const nextStates = { ...currentStates };

            for (const activeSectionLabel of activeSectionLabels) {
                if (nextStates[activeSectionLabel] === undefined) {
                    nextStates[activeSectionLabel] = true;
                }
            }

            nextStates[sectionLabel] = nextOpen;

            return nextStates;
        });
    };

    const navigationContent = (
        <>
            {items.length > 0 && (
                <NavItems items={items} nested={nestedItems} />
            )}
            {sections.length > 0 && (
                <div className="grid gap-0.5 pt-1 group-data-[collapsible=icon]:hidden">
                    {sections.map((section) => (
                        <NavSectionDisclosure
                            key={section.label}
                            section={section}
                            open={
                                sectionOpenStates[section.label] ??
                                section.items.some(
                                    (item) =>
                                        item.isActive ||
                                        isCurrentUrl(item.href),
                                )
                            }
                            onOpenChange={(nextOpen) =>
                                handleSectionOpenChange(section.label, nextOpen)
                            }
                            onNavigate={preserveActiveSections}
                        />
                    ))}
                </div>
            )}
        </>
    );

    if (!collapsible) {
        return (
            <SidebarGroup
                className={cn(
                    'px-2 py-0 group-data-[collapsible=icon]:px-2',
                    className,
                )}
            >
                {label && (
                    <SidebarGroupLabel className="h-7 px-2 text-xs font-medium text-sidebar-foreground/50 group-data-[collapsible=icon]:px-2">
                        {label}
                    </SidebarGroupLabel>
                )}
                {navigationContent}
            </SidebarGroup>
        );
    }

    return (
        <SidebarGroup
            className={cn(
                'px-2 py-0 group-data-[collapsible=icon]:px-2',
                className,
            )}
        >
            {label && (
                <SidebarGroupLabel className="h-7 px-2 text-xs font-medium text-sidebar-foreground/50 group-data-[collapsible=icon]:px-2">
                    {label}
                </SidebarGroupLabel>
            )}
            <Collapsible open={isOpen} onOpenChange={handleOpenChange}>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <CollapsibleTrigger asChild>
                            <SidebarMenuButton
                                type="button"
                                tooltip={{ children: title ?? label ?? '' }}
                                className={cn(
                                    'font-semibold transition-colors duration-150',
                                    hasActiveItem &&
                                        'text-sidebar-accent-foreground [&>svg:first-child]:text-primary',
                                )}
                            >
                                {Icon && <Icon />}
                                <span>{title ?? label}</span>
                                <ChevronDown
                                    aria-hidden="true"
                                    className={cn(
                                        'ml-auto size-4 transition-transform duration-150 group-data-[collapsible=icon]:hidden motion-reduce:transition-none',
                                        !isOpen && '-rotate-90',
                                    )}
                                />
                            </SidebarMenuButton>
                        </CollapsibleTrigger>
                    </SidebarMenuItem>
                </SidebarMenu>
                <CollapsibleContent className="group-data-[collapsible=icon]:hidden">
                    {navigationContent}
                </CollapsibleContent>
            </Collapsible>
        </SidebarGroup>
    );
}
