import { Link, usePage } from '@inertiajs/react';
import {
    BadgeCheck,
    Database,
    FileCode2,
    FolderTree,
    KeyRound,
    LayoutGrid,
    ScrollText,
    Settings2,
    Shield,
    ShieldCheck,
    ShieldAlert,
    SlidersHorizontal,
    UserRound,
    UsersRound,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import {
    Sidebar,
    SidebarContent,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { edit as editApplicationSettings } from '@/routes/application-settings';
import { index as auditLogsIndex } from '@/routes/audit-logs';
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
import { index as usersIndex } from '@/routes/users';
import type { Auth, NavItem } from '@/types';

export function AppSidebar() {
    const { auth } = usePage<{ auth: Auth }>().props;
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
    const adminNavSections = [
        {
            label: 'Access',
            items: [
                { title: 'People', href: usersIndex(), icon: UsersRound },
                { title: 'Access Roles', href: rolesIndex(), icon: KeyRound },
            ],
        },
        {
            label: 'Authentication',
            items: [
                {
                    title: 'Authentication',
                    href: editAuthenticationMethods(),
                    icon: ShieldCheck,
                },
            ],
        },
        {
            label: 'Workspace',
            items: [
                {
                    title: 'Application',
                    href: editApplicationSettings(),
                    icon: Settings2,
                },
                {
                    title: 'Notification Policy',
                    href: editNotificationSettings(),
                    icon: BadgeCheck,
                },
            ],
        },
        {
            label: 'Governance',
            items: [
                {
                    title: 'SQL Policy',
                    href: editSqlStatementPolicy(),
                    icon: ShieldAlert,
                },
                {
                    title: 'Audit Log',
                    href: auditLogsIndex(),
                    icon: ScrollText,
                },
            ],
        },
    ];
    const accountNavItems: NavItem[] = [
        {
            title: 'Profile',
            href: editProfile(),
            icon: UserRound,
        },
        {
            title: 'Preferences',
            href: editPreferences(),
            icon: SlidersHorizontal,
        },
        { title: 'Security', href: editSecurity(), icon: Shield },
    ];

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
                <NavMain
                    label="Account"
                    items={accountNavItems}
                    collapsible
                    storageKey="crucible.sidebar.account-open"
                />
                {isAdmin && (
                    <NavMain
                        label="Admin"
                        sections={adminNavSections}
                        collapsible
                        storageKey="crucible.sidebar.admin-open"
                    />
                )}
            </SidebarContent>
        </Sidebar>
    );
}
