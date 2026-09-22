import { Head, Link } from '@inertiajs/react';
import { Edit3, FolderTree } from 'lucide-react';
import { PageHeader } from '@/components/crucible/page-header';
import { SemanticIcon } from '@/components/crucible/semantic-icon';
import {
    AccessModeBadge,
    StatusBadge,
} from '@/components/crucible/status-badge';
import { Button } from '@/components/ui/button';
import { driverLabel } from '@/lib/crucible';
import type { AccessMode, DatabaseDriver } from '@/lib/crucible';
import { edit, index } from '@/routes/connection-groups';
import { show as showConnection } from '@/routes/connections';
import { show as showRole } from '@/routes/roles';

type Props = {
    connection_group: {
        id: number;
        name: string;
        description: string | null;
        connections: Array<{
            id: number;
            name: string;
            driver: DatabaseDriver;
            endpoint: string;
            database: string;
            is_active: boolean;
        }>;
        role_policies: Array<{
            id: number;
            role: {
                id: number;
                name: string;
                slug: string;
                is_admin: boolean;
            };
            access_mode: AccessMode;
            query_access_mode: AccessMode;
            native_proxy_access_mode: AccessMode;
            can_review: boolean;
            read_requires_approval: boolean;
            write_requires_approval: boolean;
            max_write_session_minutes: number | null;
        }>;
    };
};

export default function ConnectionGroupShow({
    connection_group: connectionGroup,
}: Props) {
    return (
        <>
            <Head title={connectionGroup.name} />

            <div className="crucible-page">
                <PageHeader
                    title={connectionGroup.name}
                    description={
                        connectionGroup.description ??
                        'No description provided.'
                    }
                    actions={
                        <Button asChild>
                            <Link href={edit(connectionGroup.id)}>
                                <Edit3 />
                                Edit group
                            </Link>
                        </Button>
                    }
                />

                <section className="overflow-hidden border-y bg-card sm:rounded-lg sm:border">
                    <div className="flex items-center gap-2 border-b px-4 py-3 sm:px-5">
                        <SemanticIcon icon={FolderTree} tone="info" size="sm" />
                        <h2 className="text-base font-semibold">
                            Group profile
                        </h2>
                    </div>
                    <dl className="grid gap-x-8 gap-y-4 px-4 py-5 text-sm sm:grid-cols-2 sm:px-5">
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Member connections
                            </dt>
                            <dd className="mt-1 tabular-nums">
                                {connectionGroup.connections.length}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Role policies
                            </dt>
                            <dd className="mt-1 tabular-nums">
                                {connectionGroup.role_policies.length}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section className="overflow-hidden rounded-lg border bg-card">
                    <div className="border-b px-4 py-3 sm:px-5">
                        <h2 className="text-base font-semibold">
                            Member connections
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Explicit database targets included in this access
                            scope.
                        </p>
                    </div>
                    {connectionGroup.connections.length === 0 ? (
                        <p className="px-4 py-5 text-sm text-muted-foreground sm:px-5">
                            No database connections belong to this group.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[52rem] text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/45 text-left text-xs text-muted-foreground">
                                        <th className="px-4 py-2.5 font-medium sm:px-5">
                                            Connection
                                        </th>
                                        <th className="px-4 py-2.5 font-medium">
                                            Endpoint
                                        </th>
                                        <th className="px-4 py-2.5 font-medium">
                                            Database
                                        </th>
                                        <th className="px-4 py-2.5 font-medium sm:px-5">
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {connectionGroup.connections.map(
                                        (connection) => (
                                            <tr
                                                key={connection.id}
                                                className="border-b last:border-0"
                                            >
                                                <td className="px-4 py-3 sm:px-5">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <Link
                                                            href={showConnection(
                                                                connection.id,
                                                            )}
                                                            className="font-medium hover:underline"
                                                        >
                                                            {connection.name}
                                                        </Link>
                                                        <StatusBadge
                                                            value={
                                                                connection.driver
                                                            }
                                                            label={driverLabel(
                                                                connection.driver,
                                                            )}
                                                        />
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                                                    {connection.endpoint}
                                                </td>
                                                <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                                                    {connection.database}
                                                </td>
                                                <td className="px-4 py-3 sm:px-5">
                                                    <StatusBadge
                                                        value={
                                                            connection.is_active
                                                                ? 'active'
                                                                : 'disabled'
                                                        }
                                                    />
                                                </td>
                                            </tr>
                                        ),
                                    )}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section className="overflow-hidden rounded-lg border bg-card">
                    <div className="border-b px-4 py-3 sm:px-5">
                        <h2 className="text-base font-semibold">
                            Roles using this group
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Policies whose scope changes when group membership
                            changes.
                        </p>
                    </div>
                    {connectionGroup.role_policies.length === 0 ? (
                        <p className="px-4 py-5 text-sm text-muted-foreground sm:px-5">
                            No role policies currently reference this group.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[72rem] text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/45 text-left text-xs text-muted-foreground">
                                        <th className="px-4 py-2.5 font-medium sm:px-5">
                                            Role
                                        </th>
                                        <th className="px-3 py-2.5 font-medium">
                                            Maximum
                                        </th>
                                        <th className="px-3 py-2.5 font-medium">
                                            Query Access
                                        </th>
                                        <th className="px-3 py-2.5 font-medium">
                                            Native Client
                                        </th>
                                        <th className="px-3 py-2.5 font-medium">
                                            Reviewer
                                        </th>
                                        <th className="px-3 py-2.5 font-medium">
                                            Read approval
                                        </th>
                                        <th className="px-3 py-2.5 font-medium">
                                            Write approval
                                        </th>
                                        <th className="px-4 py-2.5 font-medium sm:px-5">
                                            Write limit
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {connectionGroup.role_policies.map(
                                        (policy) => (
                                            <tr
                                                key={policy.id}
                                                className="border-b last:border-0"
                                            >
                                                <td className="px-4 py-3 sm:px-5">
                                                    <Link
                                                        href={showRole(
                                                            policy.role.id,
                                                        )}
                                                        className="font-medium hover:underline"
                                                    >
                                                        {policy.role.name}
                                                    </Link>
                                                    <p className="mt-1 font-mono text-xs text-muted-foreground">
                                                        {policy.role.slug}
                                                    </p>
                                                </td>
                                                <td className="px-3 py-3">
                                                    <AccessModeBadge
                                                        mode={
                                                            policy.access_mode
                                                        }
                                                    />
                                                </td>
                                                <td className="px-3 py-3">
                                                    <AccessModeBadge
                                                        mode={
                                                            policy.query_access_mode
                                                        }
                                                    />
                                                </td>
                                                <td className="px-3 py-3">
                                                    <AccessModeBadge
                                                        mode={
                                                            policy.native_proxy_access_mode
                                                        }
                                                    />
                                                </td>
                                                <td className="px-3 py-3">
                                                    {policy.can_review
                                                        ? 'May review'
                                                        : 'No'}
                                                </td>
                                                <td className="px-3 py-3">
                                                    {policy.read_requires_approval
                                                        ? 'Required'
                                                        : 'Not required'}
                                                </td>
                                                <td className="px-3 py-3">
                                                    {policy.write_requires_approval
                                                        ? 'Required'
                                                        : 'Not required'}
                                                </td>
                                                <td className="px-4 py-3 tabular-nums sm:px-5">
                                                    {policy.max_write_session_minutes
                                                        ? `${policy.max_write_session_minutes} min`
                                                        : 'No limit'}
                                                </td>
                                            </tr>
                                        ),
                                    )}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

ConnectionGroupShow.layout = {
    breadcrumbs: [{ title: 'Connection Groups', href: index() }],
};
