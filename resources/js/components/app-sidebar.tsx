import { Link, usePage } from '@inertiajs/react';
import {
    BadgeCheck,
    Bell,
    Database,
    FileCode2,
    KeyRound,
    LayoutGrid,
    Palette,
    ScrollText,
    Settings2,
    Shield,
    ShieldCheck,
    UserRound,
    UsersRound,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
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
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editApplicationSettings } from '@/routes/application-settings';
import { index as auditLogsIndex } from '@/routes/audit-logs';
import { index as authenticationProvidersIndex } from '@/routes/auth-providers';
import { edit as editAuthenticationMethods } from '@/routes/authentication-methods';
import { index as connectionsIndex } from '@/routes/connections';
import { edit as editNotificationSettings } from '@/routes/notification-settings';
import { edit as editProfile } from '@/routes/profile';
import { index as queryRequestsIndex } from '@/routes/query-requests';
import { index as rolesIndex } from '@/routes/roles';
import { edit as editSecurity } from '@/routes/security';
import { edit as editNotificationPreferences } from '@/routes/user-notifications';
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
    ];
    const adminNavItems: NavItem[] = [
        {
            title: 'People',
            href: usersIndex(),
            icon: UsersRound,
        },
        {
            title: 'Access Roles',
            href: rolesIndex(),
            icon: KeyRound,
        },
        {
            title: 'Sign-in methods',
            href: editAuthenticationMethods(),
            icon: ShieldCheck,
        },
        {
            title: 'Authentication providers',
            href: authenticationProvidersIndex(),
            icon: BadgeCheck,
        },
        {
            title: 'Application',
            href: editApplicationSettings(),
            icon: Settings2,
        },
        {
            title: 'Notifications',
            href: editNotificationSettings(),
            icon: Bell,
        },
        {
            title: 'Audit Log',
            href: auditLogsIndex(),
            icon: ScrollText,
        },
    ];
    const accountNavItems: NavItem[] = [
        {
            title: 'Profile',
            href: editProfile(),
            icon: UserRound,
        },
        {
            title: 'Notifications',
            href: editNotificationPreferences(),
            icon: Bell,
        },
        {
            title: 'Security',
            href: editSecurity(),
            icon: Shield,
        },
        {
            title: 'Appearance',
            href: editAppearance(),
            icon: Palette,
        },
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
                        items={adminNavItems}
                        collapsible
                        storageKey="crucible.sidebar.admin-open"
                    />
                )}
            </SidebarContent>

            <SidebarFooter className="border-t border-sidebar-border px-3 py-2.5 group-data-[collapsible=icon]:px-2">
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
