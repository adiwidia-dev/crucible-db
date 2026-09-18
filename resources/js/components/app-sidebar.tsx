import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    Database,
    FileCode2,
    FolderTree,
    LayoutGrid,
    Settings2,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import type { NavSection } from '@/components/nav-main';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { dashboard } from '@/routes';
import { edit as editAccessWorkflows } from '@/routes/access-workflows';
import { edit as editApplicationDatabase } from '@/routes/application-database-migrations';
import { edit as editApplicationSettings } from '@/routes/application-settings';
import { index as auditLogsIndex } from '@/routes/audit-logs';
import { index as authProvidersIndex } from '@/routes/auth-providers';
import { edit as editAuthenticationMethods } from '@/routes/authentication-methods';
import { index as connectionGroupsIndex } from '@/routes/connection-groups';
import { index as connectionsIndex } from '@/routes/connections';
import { edit as editNotificationSettings } from '@/routes/notification-settings';
import { edit as editPreferences } from '@/routes/preferences';
import { edit as editProfile } from '@/routes/profile';
import { index as queryRequestsIndex } from '@/routes/query-requests';
import { index as rolesIndex } from '@/routes/roles';
import { edit as editSecurity } from '@/routes/security';
import { edit as editSqlStatementPolicy } from '@/routes/sql-statement-policy';
import { show as showSystemStatus } from '@/routes/system-status';
import { index as usersIndex } from '@/routes/users';
import type { Auth, NavItem } from '@/types';

export function AppSidebar() {
    const { auth, policy_review_summary: policyReviewSummary } = usePage<{
        auth: Auth;
        policy_review_summary?: { pending_count?: number };
    }>().props;
    const { isCurrentOrParentUrl, isCurrentUrl } = useCurrentUrl();
    const isAdmin = Boolean(auth.user.roles?.some((role) => role.is_admin));
    const workNavItems: NavItem[] = [
        {
            title: 'Overview',
            href: dashboard(),
            icon: LayoutGrid,
        },
        {
            title: 'Query Requests',
            href: queryRequestsIndex(),
            icon: FileCode2,
        },
    ];
    const dataNavItems: NavItem[] = [
        {
            title: 'Connections',
            href: connectionsIndex(),
            icon: Database,
        },
        ...(isAdmin
            ? [
                  {
                      title: 'Connection Groups',
                      href: connectionGroupsIndex(),
                      icon: FolderTree,
                  },
              ]
            : []),
    ];
    const adminNavSections: NavSection[] = [
        {
            label: 'Access & identity',
            items: [
                { title: 'People', href: usersIndex() },
                { title: 'Access Roles', href: rolesIndex() },
                {
                    title: 'Access Workflows',
                    href: editAccessWorkflows(),
                },
            ],
        },
        {
            label: 'Security & policy',
            items: [
                {
                    title: 'Sign-in Methods',
                    href: editAuthenticationMethods(),
                },
                {
                    title: 'SSO Providers',
                    href: authProvidersIndex(),
                    isActive: isCurrentOrParentUrl(authProvidersIndex()),
                },
                {
                    title: 'SQL Policy',
                    href: editSqlStatementPolicy(),
                    badge: policyReviewSummary?.pending_count ?? 0,
                },
            ],
        },
        {
            label: 'Application',
            items: [
                {
                    title: 'General',
                    href: editApplicationSettings(),
                },
                {
                    title: 'Notification Policy',
                    href: editNotificationSettings(),
                },
                {
                    title: 'Database',
                    href: editApplicationDatabase(),
                },
                {
                    title: 'System Status',
                    href: showSystemStatus(),
                    icon: Activity,
                },
            ],
        },
        {
            label: 'Governance',
            items: [{ title: 'Audit Log', href: auditLogsIndex() }],
        },
    ];
    const accountNavItems: NavItem[] = [
        {
            title: 'Profile',
            href: editProfile(),
        },
        {
            title: 'Preferences',
            href: editPreferences(),
        },
        { title: 'Security', href: editSecurity() },
    ];
    const hasActiveAdminItem = adminNavSections.some((section) =>
        section.items.some((item) => item.isActive || isCurrentUrl(item.href)),
    );
    const hasActiveAccountItem = accountNavItems.some((item) =>
        isCurrentUrl(item.href),
    );
    const [openNavigationGroups, setOpenNavigationGroups] = useState(() => ({
        administration: hasActiveAdminItem,
        account: hasActiveAccountItem,
    }));

    return (
        <Sidebar collapsible="icon" variant="sidebar">
            <SidebarHeader className="h-13 justify-center border-b border-sidebar-border px-3 py-0 group-data-[collapsible=icon]:px-2">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            size="lg"
                            className="px-0 hover:bg-transparent data-[active=true]:bg-transparent"
                            asChild
                        >
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-2 py-3">
                <NavMain label="Work" items={workNavItems} />
                <NavMain label="Data" items={dataNavItems} />
                {isAdmin && (
                    <NavMain
                        label="Manage"
                        title="Administration"
                        icon={Settings2}
                        sections={adminNavSections}
                        collapsible
                        defaultOpen={false}
                        open={openNavigationGroups.administration}
                        onOpenChange={(isOpen) =>
                            setOpenNavigationGroups((currentGroups) => ({
                                ...currentGroups,
                                administration: isOpen,
                            }))
                        }
                    />
                )}
            </SidebarContent>
            <SidebarFooter className="border-t border-sidebar-border px-2 py-2">
                <NavMain
                    title="Account"
                    icon={UserRound}
                    items={accountNavItems}
                    collapsible
                    defaultOpen={false}
                    open={openNavigationGroups.account}
                    onOpenChange={(isOpen) =>
                        setOpenNavigationGroups((currentGroups) => ({
                            ...currentGroups,
                            account: isOpen,
                        }))
                    }
                    nestedItems
                    className="p-0"
                />
            </SidebarFooter>
        </Sidebar>
    );
}
