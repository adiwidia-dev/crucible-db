import { Head, Link } from '@inertiajs/react';
import { Edit3, KeyRound } from 'lucide-react';
import { PageHeader } from '@/components/crucible/page-header';
import { SemanticIcon } from '@/components/crucible/semantic-icon';
import {
    AccessModeBadge,
    StatusBadge,
} from '@/components/crucible/status-badge';
import { Button } from '@/components/ui/button';
import { driverLabel } from '@/lib/crucible';
import type { AccessMode, DatabaseDriver } from '@/lib/crucible';
import { show as showConnectionGroup } from '@/routes/connection-groups';
import { show as showConnection } from '@/routes/connections';
import { edit, index } from '@/routes/roles';

type Policy = {
    id: number;
    access_mode: AccessMode;
    query_access_mode: AccessMode;
    native_proxy_access_mode: AccessMode;
    can_review: boolean;
    read_requires_approval: boolean;
    write_requires_approval: boolean;
    max_write_session_minutes: number | null;
};

type Props = {
    role: {
        id: number;
        name: string;
        slug: string;
        description: string | null;
        is_admin: boolean;
        users: Array<{ id: number; name: string; email: string }>;
        policies: Array<
            Policy & {
                connection: {
                    id: number;
                    name: string;
                    driver: DatabaseDriver;
                    endpoint: string;
                    database: string;
                    is_active: boolean;
                };
            }
        >;
        group_policies: Array<
            Policy & {
                connection_group: {
                    id: number;
                    name: string;
                    description: string | null;
                };
            }
        >;
    };
    access_features: {
        query_access_enabled: boolean;
        native_client_access_enabled: boolean;
    };
};

function approvalLabel(policy: Policy): string {
    if (policy.read_requires_approval && policy.write_requires_approval) {
        return 'Read and write';
    }

    if (policy.write_requires_approval) {
        return 'Write only';
    }

    if (policy.read_requires_approval) {
        return 'Read only';
    }

    return 'Not required';
}

function PolicyCells({ policy }: { policy: Policy }) {
    return (
        <>
            <td className="px-3 py-3">
                <AccessModeBadge mode={policy.access_mode} />
            </td>
            <td className="px-3 py-3">
                <AccessModeBadge mode={policy.query_access_mode} />
            </td>
            <td className="px-3 py-3">
                <AccessModeBadge mode={policy.native_proxy_access_mode} />
            </td>
            <td className="px-3 py-3">
                {policy.can_review ? 'May review' : 'No'}
            </td>
            <td className="px-3 py-3">{approvalLabel(policy)}</td>
            <td className="px-3 py-3 tabular-nums">
                {policy.max_write_session_minutes
                    ? `${policy.max_write_session_minutes} min`
                    : 'No limit'}
            </td>
        </>
    );
}

function PolicyHeadCells() {
    return (
        <>
            <th className="px-3 py-2.5 font-medium">Maximum</th>
            <th className="px-3 py-2.5 font-medium">Query Access</th>
            <th className="px-3 py-2.5 font-medium">Native Client</th>
            <th className="px-3 py-2.5 font-medium">Reviewer</th>
            <th className="px-3 py-2.5 font-medium">Approval</th>
            <th className="px-3 py-2.5 font-medium">Write limit</th>
        </>
    );
}

export default function RoleShow({ role, access_features: features }: Props) {
    const disabledWorkflows = [
        !features.query_access_enabled ? 'Query Access' : null,
        !features.native_client_access_enabled ? 'Native Client Access' : null,
    ].filter(Boolean);

    return (
        <>
            <Head title={role.name} />

            <div className="crucible-page">
                <PageHeader
                    title={role.name}
                    description={role.description ?? 'No description provided.'}
                    actions={
                        !role.is_admin && (
                            <Button asChild>
                                <Link href={edit(role.id)}>
                                    <Edit3 />
                                    Edit role
                                </Link>
                            </Button>
                        )
                    }
                />

                <section className="overflow-hidden border-y bg-card sm:rounded-lg sm:border">
                    <div className="border-b px-4 py-3 sm:px-5">
                        <div className="flex flex-wrap items-center gap-2">
                            <SemanticIcon
                                icon={KeyRound}
                                tone={role.is_admin ? 'success' : 'info'}
                                size="sm"
                            />
                            <h2 className="text-base font-semibold">
                                Role profile
                            </h2>
                            <StatusBadge
                                value={role.is_admin ? 'active' : 'none'}
                                label={
                                    role.is_admin ? 'System admin' : 'Custom'
                                }
                            />
                        </div>
                    </div>
                    <dl className="grid gap-x-8 gap-y-4 px-4 py-5 text-sm sm:grid-cols-3 sm:px-5">
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Slug
                            </dt>
                            <dd className="mt-1 font-mono text-xs">
                                {role.slug}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Assigned people
                            </dt>
                            <dd className="mt-1 tabular-nums">
                                {role.users.length}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Policies
                            </dt>
                            <dd className="mt-1 tabular-nums">
                                {role.policies.length +
                                    role.group_policies.length}
                            </dd>
                        </div>
                    </dl>
                    {disabledWorkflows.length > 0 && (
                        <div className="border-t bg-muted/30 px-4 py-3 text-xs text-muted-foreground sm:px-5">
                            Globally unavailable: {disabledWorkflows.join(', ')}
                            . Stored grants remain configured.
                        </div>
                    )}
                </section>

                <section className="overflow-hidden border-y bg-card sm:rounded-lg sm:border">
                    <div className="border-b px-4 py-3 sm:px-5">
                        <h2 className="text-base font-semibold">
                            Assigned people
                        </h2>
                    </div>
                    {role.users.length === 0 ? (
                        <p className="px-4 py-5 text-sm text-muted-foreground sm:px-5">
                            No people are assigned to this role.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[32rem] text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/45 text-left text-xs text-muted-foreground">
                                        <th className="px-4 py-2.5 font-medium sm:px-5">
                                            Name
                                        </th>
                                        <th className="px-4 py-2.5 font-medium sm:px-5">
                                            Email
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {role.users.map((user) => (
                                        <tr
                                            key={user.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="px-4 py-3 font-medium sm:px-5">
                                                {user.name}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground sm:px-5">
                                                {user.email}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section className="overflow-hidden border-y bg-card sm:rounded-lg sm:border">
                    <div className="border-b px-4 py-3 sm:px-5">
                        <h2 className="text-base font-semibold">
                            Connection group policies
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Defaults applied to every current member of each
                            group.
                        </p>
                    </div>
                    {role.group_policies.length === 0 ? (
                        <p className="px-4 py-5 text-sm text-muted-foreground sm:px-5">
                            No connection group policies are configured.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[72rem] text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/45 text-left text-xs text-muted-foreground">
                                        <th className="px-4 py-2.5 font-medium sm:px-5">
                                            Connection group
                                        </th>
                                        <PolicyHeadCells />
                                    </tr>
                                </thead>
                                <tbody>
                                    {role.group_policies.map((policy) => (
                                        <tr
                                            key={policy.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="px-4 py-3 sm:px-5">
                                                <Link
                                                    href={showConnectionGroup(
                                                        policy.connection_group
                                                            .id,
                                                    )}
                                                    className="font-medium hover:underline"
                                                >
                                                    {
                                                        policy.connection_group
                                                            .name
                                                    }
                                                </Link>
                                                {policy.connection_group
                                                    .description && (
                                                    <p className="mt-1 max-w-sm text-xs text-muted-foreground">
                                                        {
                                                            policy
                                                                .connection_group
                                                                .description
                                                        }
                                                    </p>
                                                )}
                                            </td>
                                            <PolicyCells policy={policy} />
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section className="overflow-hidden border-y bg-card sm:rounded-lg sm:border">
                    <div className="border-b px-4 py-3 sm:px-5">
                        <h2 className="text-base font-semibold">
                            Direct connection policies
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Connection-specific grants and exceptions.
                        </p>
                    </div>
                    {role.policies.length === 0 ? (
                        <p className="px-4 py-5 text-sm text-muted-foreground sm:px-5">
                            No direct connection policies are configured.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[78rem] text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/45 text-left text-xs text-muted-foreground">
                                        <th className="px-4 py-2.5 font-medium sm:px-5">
                                            Connection
                                        </th>
                                        <PolicyHeadCells />
                                    </tr>
                                </thead>
                                <tbody>
                                    {role.policies.map((policy) => (
                                        <tr
                                            key={policy.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="px-4 py-3 sm:px-5">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Link
                                                        href={showConnection(
                                                            policy.connection
                                                                .id,
                                                        )}
                                                        className="font-medium hover:underline"
                                                    >
                                                        {policy.connection.name}
                                                    </Link>
                                                    <StatusBadge
                                                        value={
                                                            policy.connection
                                                                .driver
                                                        }
                                                        label={driverLabel(
                                                            policy.connection
                                                                .driver,
                                                        )}
                                                    />
                                                    <StatusBadge
                                                        value={
                                                            policy.connection
                                                                .is_active
                                                                ? 'active'
                                                                : 'disabled'
                                                        }
                                                    />
                                                </div>
                                                <p className="mt-1 font-mono text-xs text-muted-foreground">
                                                    {policy.connection.endpoint}{' '}
                                                    /{' '}
                                                    {policy.connection.database}
                                                </p>
                                            </td>
                                            <PolicyCells policy={policy} />
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

RoleShow.layout = {
    breadcrumbs: [{ title: 'Roles', href: index() }],
};
