import { Link } from '@inertiajs/react';
import {
    BadgeCheck,
    FileCog,
    ScrollText,
    Settings2,
    ShieldCheck,
    Users,
} from 'lucide-react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editApplicationSettings } from '@/routes/application-settings';
import { index as auditLogsIndex } from '@/routes/audit-logs';
import { index as authenticationProvidersIndex } from '@/routes/auth-providers';
import { edit as editAuthenticationMethods } from '@/routes/authentication-methods';
import { index as rolesIndex } from '@/routes/roles';
import { index as usersIndex } from '@/routes/users';
import type { NavItem } from '@/types';

const adminNavItems: NavItem[] = [
    {
        title: 'Application',
        href: editApplicationSettings(),
        icon: Settings2,
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
        title: 'Users',
        href: usersIndex(),
        icon: Users,
    },
    {
        title: 'Roles',
        href: rolesIndex(),
        icon: FileCog,
    },
    {
        title: 'Audit logs',
        href: auditLogsIndex(),
        icon: ScrollText,
    },
];

export default function AdminSettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <div className="px-4 py-6 sm:px-6">
            <Heading
                title="Admin settings"
                description="Manage authentication, users, access policy, audit records, and application delivery settings."
            />

            <div className="flex flex-col gap-6 lg:flex-row lg:gap-12">
                <aside className="w-full lg:w-56 lg:shrink-0">
                    <nav
                        className="grid grid-cols-2 gap-1 sm:grid-cols-3 lg:flex lg:flex-col"
                        aria-label="Admin settings"
                    >
                        {adminNavItems.map((item) => (
                            <Button
                                key={toUrl(item.href)}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('justify-start', {
                                    'bg-muted': isCurrentOrParentUrl(item.href),
                                })}
                            >
                                <Link href={item.href}>
                                    {item.icon && (
                                        <item.icon className="size-4" />
                                    )}
                                    {item.title}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="lg:hidden" />

                <div className="min-w-0 flex-1">
                    <section className="space-y-6">{children}</section>
                </div>
            </div>
        </div>
    );
}
