import { Form, Head, Link } from '@inertiajs/react';
import { Check, Database, Trash2, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import RoleController from '@/actions/App/Http/Controllers/RoleController';
import { ConnectionAddCombobox } from '@/components/crucible/connection-combobox';
import { PageHeader } from '@/components/crucible/page-header';
import { StatusBadge } from '@/components/crucible/status-badge';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { driverLabel, statusLabel } from '@/lib/crucible';
import type { AccessMode, DatabaseDriver } from '@/lib/crucible';
import { index as connectionsIndex } from '@/routes/connections';
import { index as rolesIndex } from '@/routes/roles';

type RoleFormData = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    is_admin: boolean;
    policies: Record<
        string,
        {
            access_mode: AccessMode;
            can_review: boolean;
            read_requires_approval: boolean;
            write_requires_approval: boolean;
            max_write_session_minutes: number | null;
        }
    >;
} | null;

type Connection = {
    id: number;
    name: string;
    driver: DatabaseDriver;
    host: string;
    port: number;
    database: string;
    is_active: boolean;
};

type Props = {
    role: RoleFormData;
    connections: Connection[];
    access_modes: AccessMode[];
};

type PolicyDraft = {
    database_connection_id: number;
    access_mode: AccessMode;
    can_review: boolean;
    read_requires_approval: boolean;
    write_requires_approval: boolean;
    max_write_session_minutes: number | null;
};

export default function RoleForm({ role, connections, access_modes }: Props) {
    const isEditing = Boolean(role);
    const action = role
        ? RoleController.update.form(role.id)
        : RoleController.store.form();
    const [policies, setPolicies] = useState<PolicyDraft[]>(() =>
        role
            ? Object.entries(role.policies).map(([connectionId, policy]) => ({
                  database_connection_id: Number(connectionId),
                  access_mode: policy.access_mode,
                  can_review: policy.can_review,
                  read_requires_approval: policy.read_requires_approval,
                  write_requires_approval: policy.write_requires_approval,
                  max_write_session_minutes: policy.max_write_session_minutes,
              }))
            : [],
    );
    const selectedConnectionIds = useMemo(
        () => new Set(policies.map((policy) => policy.database_connection_id)),
        [policies],
    );
    const addPolicy = (connectionId: number) => {
        if (selectedConnectionIds.has(connectionId)) {
            return;
        }

        setPolicies((currentPolicies) => [
            ...currentPolicies,
            {
                database_connection_id: connectionId,
                access_mode: 'read',
                can_review: false,
                read_requires_approval: false,
                write_requires_approval: true,
                max_write_session_minutes: null,
            },
        ]);
    };

    const updatePolicy = (
        index: number,
        attributes: Partial<Omit<PolicyDraft, 'database_connection_id'>>,
    ) => {
        setPolicies((currentPolicies) =>
            currentPolicies.map((policy, currentIndex) =>
                currentIndex === index ? { ...policy, ...attributes } : policy,
            ),
        );
    };

    const removePolicy = (index: number) => {
        setPolicies((currentPolicies) =>
            currentPolicies.filter((_, currentIndex) => currentIndex !== index),
        );
    };

    return (
        <>
            <Head title={isEditing ? 'Edit role' : 'New role'} />

            <div className="crucible-page">
                <PageHeader
                    title={isEditing ? 'Edit Role' : 'New Role'}
                    description={
                        isEditing
                            ? `Manage ${role?.name}'s connection permissions and approval requirements.`
                            : 'Create a reusable permission set for governed database work.'
                    }
                />

                <Form
                    {...action}
                    options={{ preserveScroll: true }}
                    className="grid gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <Card className="gap-0 overflow-hidden border-y py-0 sm:rounded-lg sm:border">
                                <CardHeader className="border-b px-4 py-3 sm:px-5">
                                    <CardTitle>Role Profile</CardTitle>
                                    <CardDescription>
                                        Give this permission set a clear name
                                        and purpose for people assigning it.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid max-w-3xl gap-5 px-4 py-5 sm:grid-cols-2 sm:px-5">
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">Role Name</Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            defaultValue={role?.name ?? ''}
                                            required
                                        />
                                        <InputError message={errors.name} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="description">
                                            Description
                                        </Label>
                                        <Input
                                            id="description"
                                            name="description"
                                            defaultValue={
                                                role?.description ?? ''
                                            }
                                        />
                                        <InputError
                                            message={errors.description}
                                        />
                                    </div>
                                </CardContent>
                            </Card>

                            <Card className="gap-0 overflow-hidden border-y py-0 sm:rounded-lg sm:border">
                                <CardHeader className="border-b px-4 py-3 sm:px-5">
                                    <CardTitle>Database Policies</CardTitle>
                                    <CardDescription>
                                        Set the highest privilege this role may
                                        request on each connection.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="p-0">
                                    {connections.length === 0 ? (
                                        <div className="grid gap-3 p-6 text-sm text-muted-foreground">
                                            <div className="flex items-center gap-2 font-medium text-foreground">
                                                <Database className="size-4" />
                                                No database connections found
                                            </div>
                                            <p className="max-w-2xl">
                                                Add a connection before
                                                assigning runtime access
                                                policies to this role.
                                            </p>
                                            <div>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link
                                                        href={connectionsIndex()}
                                                    >
                                                        Open Connections
                                                    </Link>
                                                </Button>
                                            </div>
                                        </div>
                                    ) : (
                                        <div>
                                            <div className="border-b bg-muted/20 px-4 py-3 sm:px-5">
                                                <p className="text-sm font-medium">
                                                    Access and approval work
                                                    together
                                                </p>
                                                <p className="mt-1 max-w-4xl text-xs leading-5 text-muted-foreground">
                                                    Maximum access controls the
                                                    strongest work this role can
                                                    request. Approval controls
                                                    whether read or write work
                                                    must wait for a reviewer.
                                                </p>
                                            </div>

                                            <div className="grid gap-5 p-4 sm:p-5">
                                                {policies.length === 0 ? (
                                                    <div className="rounded-md border border-dashed bg-muted/15 px-4 py-5">
                                                        <div className="flex items-center gap-2 text-sm font-medium">
                                                            <Database className="size-4" />
                                                            No connection
                                                            policies
                                                        </div>
                                                        <p className="mt-1 max-w-2xl text-xs leading-5 text-muted-foreground">
                                                            This role grants no
                                                            database access
                                                            until a connection
                                                            policy is added
                                                            below.
                                                        </p>
                                                    </div>
                                                ) : (
                                                    <div className="overflow-x-auto rounded-md border">
                                                        <table className="w-full min-w-[1180px] text-sm">
                                                            <thead>
                                                                <tr className="border-b bg-muted/45 text-left text-xs text-muted-foreground">
                                                                    <th className="py-2.5 pr-4 pl-3 font-medium sm:pl-4">
                                                                        Connection
                                                                    </th>
                                                                    <th
                                                                        className="py-2.5 pr-4 font-medium"
                                                                        title="The highest privilege this role may request on this connection."
                                                                    >
                                                                        Maximum
                                                                        access
                                                                    </th>
                                                                    <th className="py-2.5 pr-4 font-medium">
                                                                        Reviewer
                                                                    </th>
                                                                    <th className="py-2.5 pr-4 font-medium">
                                                                        Read
                                                                        approval
                                                                    </th>
                                                                    <th className="py-2.5 pr-4 font-medium">
                                                                        Write
                                                                        approval
                                                                    </th>
                                                                    <th className="py-2.5 pr-4 font-medium">
                                                                        Write-session
                                                                        limit
                                                                    </th>
                                                                    <th className="py-2.5 pr-3 text-right font-medium sm:pr-4">
                                                                        Actions
                                                                    </th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                {policies.map(
                                                                    (
                                                                        policy,
                                                                        index,
                                                                    ) => {
                                                                        const connection =
                                                                            connections.find(
                                                                                (
                                                                                    item,
                                                                                ) =>
                                                                                    item.id ===
                                                                                    policy.database_connection_id,
                                                                            );

                                                                        if (
                                                                            !connection
                                                                        ) {
                                                                            return null;
                                                                        }

                                                                        return (
                                                                            <tr
                                                                                key={
                                                                                    connection.id
                                                                                }
                                                                                className="border-b align-top transition-colors last:border-0 hover:bg-muted/20"
                                                                            >
                                                                                <td className="py-4 pr-4 pl-4">
                                                                                    <input
                                                                                        type="hidden"
                                                                                        name={`policies[${index}][database_connection_id]`}
                                                                                        value={
                                                                                            connection.id
                                                                                        }
                                                                                    />
                                                                                    <div className="font-medium">
                                                                                        {
                                                                                            connection.name
                                                                                        }
                                                                                    </div>
                                                                                    <div className="mt-1 font-mono text-xs text-muted-foreground">
                                                                                        {
                                                                                            connection.host
                                                                                        }

                                                                                        :
                                                                                        {
                                                                                            connection.port
                                                                                        }{' '}
                                                                                        /{' '}
                                                                                        {
                                                                                            connection.database
                                                                                        }
                                                                                    </div>
                                                                                    <div className="mt-2 flex flex-wrap gap-2">
                                                                                        <StatusBadge
                                                                                            value={
                                                                                                connection.driver
                                                                                            }
                                                                                            label={driverLabel(
                                                                                                connection.driver,
                                                                                            )}
                                                                                        />
                                                                                        <StatusBadge
                                                                                            value={
                                                                                                connection.is_active
                                                                                                    ? 'active'
                                                                                                    : 'disabled'
                                                                                            }
                                                                                            label={
                                                                                                connection.is_active
                                                                                                    ? 'Active'
                                                                                                    : 'Disabled'
                                                                                            }
                                                                                        />
                                                                                    </div>
                                                                                </td>
                                                                                <td className="py-4 pr-4">
                                                                                    <select
                                                                                        name={`policies[${index}][access_mode]`}
                                                                                        value={
                                                                                            policy.access_mode
                                                                                        }
                                                                                        onChange={(
                                                                                            event,
                                                                                        ) =>
                                                                                            updatePolicy(
                                                                                                index,
                                                                                                {
                                                                                                    access_mode:
                                                                                                        event
                                                                                                            .target
                                                                                                            .value as AccessMode,
                                                                                                },
                                                                                            )
                                                                                        }
                                                                                        className="h-9 min-w-40 rounded-md border border-input bg-background px-3 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                                                                    >
                                                                                        {access_modes.map(
                                                                                            (
                                                                                                mode,
                                                                                            ) => (
                                                                                                <option
                                                                                                    key={
                                                                                                        mode
                                                                                                    }
                                                                                                    value={
                                                                                                        mode
                                                                                                    }
                                                                                                >
                                                                                                    {statusLabel(
                                                                                                        mode,
                                                                                                    )}
                                                                                                </option>
                                                                                            ),
                                                                                        )}
                                                                                    </select>
                                                                                    <InputError
                                                                                        message={
                                                                                            errors[
                                                                                                `policies.${index}.access_mode`
                                                                                            ]
                                                                                        }
                                                                                    />
                                                                                </td>
                                                                                <td className="py-4 pr-4">
                                                                                    <label className="inline-flex min-h-9 items-center gap-2 rounded-md border bg-background px-3 text-sm shadow-xs">
                                                                                        <input
                                                                                            type="hidden"
                                                                                            name={`policies[${index}][can_review]`}
                                                                                            value="0"
                                                                                        />
                                                                                        <input
                                                                                            type="checkbox"
                                                                                            name={`policies[${index}][can_review]`}
                                                                                            value="1"
                                                                                            checked={
                                                                                                policy.can_review
                                                                                            }
                                                                                            onChange={(
                                                                                                event,
                                                                                            ) =>
                                                                                                updatePolicy(
                                                                                                    index,
                                                                                                    {
                                                                                                        can_review:
                                                                                                            event
                                                                                                                .target
                                                                                                                .checked,
                                                                                                    },
                                                                                                )
                                                                                            }
                                                                                            className="size-4 rounded border-input"
                                                                                        />
                                                                                        May
                                                                                        review
                                                                                    </label>
                                                                                </td>
                                                                                <td className="py-4 pr-4">
                                                                                    <label className="inline-flex min-h-9 items-center gap-2 rounded-md border bg-background px-3 text-sm shadow-xs">
                                                                                        <input
                                                                                            type="hidden"
                                                                                            name={`policies[${index}][read_requires_approval]`}
                                                                                            value="0"
                                                                                        />
                                                                                        <input
                                                                                            type="checkbox"
                                                                                            name={`policies[${index}][read_requires_approval]`}
                                                                                            value="1"
                                                                                            checked={
                                                                                                policy.read_requires_approval
                                                                                            }
                                                                                            onChange={(
                                                                                                event,
                                                                                            ) =>
                                                                                                updatePolicy(
                                                                                                    index,
                                                                                                    {
                                                                                                        read_requires_approval:
                                                                                                            event
                                                                                                                .target
                                                                                                                .checked,
                                                                                                    },
                                                                                                )
                                                                                            }
                                                                                            className="size-4 rounded border-input"
                                                                                        />
                                                                                        Read
                                                                                        needs
                                                                                        approval
                                                                                    </label>
                                                                                    <p className="mt-2 max-w-60 text-xs leading-5 text-muted-foreground">
                                                                                        Clear
                                                                                        to
                                                                                        allow
                                                                                        read-only
                                                                                        requests
                                                                                        and
                                                                                        sessions
                                                                                        without
                                                                                        review.
                                                                                    </p>
                                                                                </td>
                                                                                <td className="py-4 pr-4">
                                                                                    <label className="inline-flex min-h-9 items-center gap-2 rounded-md border bg-background px-3 text-sm shadow-xs">
                                                                                        <input
                                                                                            type="hidden"
                                                                                            name={`policies[${index}][write_requires_approval]`}
                                                                                            value="0"
                                                                                        />
                                                                                        <input
                                                                                            type="checkbox"
                                                                                            name={`policies[${index}][write_requires_approval]`}
                                                                                            value="1"
                                                                                            checked={
                                                                                                policy.write_requires_approval
                                                                                            }
                                                                                            disabled={
                                                                                                policy.access_mode !==
                                                                                                'write'
                                                                                            }
                                                                                            onChange={(
                                                                                                event,
                                                                                            ) =>
                                                                                                updatePolicy(
                                                                                                    index,
                                                                                                    {
                                                                                                        write_requires_approval:
                                                                                                            event
                                                                                                                .target
                                                                                                                .checked,
                                                                                                    },
                                                                                                )
                                                                                            }
                                                                                            className="size-4 rounded border-input disabled:cursor-not-allowed disabled:opacity-50"
                                                                                        />
                                                                                        Write
                                                                                        needs
                                                                                        approval
                                                                                    </label>
                                                                                    <p className="mt-2 max-w-60 text-xs leading-5 text-muted-foreground">
                                                                                        Applies
                                                                                        to
                                                                                        DML,
                                                                                        DDL,
                                                                                        and
                                                                                        read
                                                                                        +
                                                                                        write
                                                                                        sessions.
                                                                                    </p>
                                                                                </td>
                                                                                <td className="py-4 pr-4">
                                                                                    <Input
                                                                                        type="number"
                                                                                        min={
                                                                                            5
                                                                                        }
                                                                                        max={
                                                                                            1440
                                                                                        }
                                                                                        name={`policies[${index}][max_write_session_minutes]`}
                                                                                        value={
                                                                                            policy.max_write_session_minutes ??
                                                                                            ''
                                                                                        }
                                                                                        onChange={(
                                                                                            event,
                                                                                        ) =>
                                                                                            updatePolicy(
                                                                                                index,
                                                                                                {
                                                                                                    max_write_session_minutes:
                                                                                                        event
                                                                                                            .target
                                                                                                            .value ===
                                                                                                        ''
                                                                                                            ? null
                                                                                                            : Number(
                                                                                                                  event
                                                                                                                      .target
                                                                                                                      .value,
                                                                                                              ),
                                                                                                },
                                                                                            )
                                                                                        }
                                                                                        placeholder="No limit"
                                                                                        disabled={
                                                                                            policy.access_mode !==
                                                                                            'write'
                                                                                        }
                                                                                        className="w-32 bg-background"
                                                                                    />
                                                                                    <p className="mt-2 max-w-52 text-xs leading-5 text-muted-foreground">
                                                                                        Optional
                                                                                        minutes.
                                                                                        Only
                                                                                        limits
                                                                                        read
                                                                                        +
                                                                                        write
                                                                                        sessions.
                                                                                    </p>
                                                                                </td>
                                                                                <td className="py-4 pr-4">
                                                                                    <Button
                                                                                        type="button"
                                                                                        variant="outline"
                                                                                        size="sm"
                                                                                        onClick={() =>
                                                                                            removePolicy(
                                                                                                index,
                                                                                            )
                                                                                        }
                                                                                    >
                                                                                        <Trash2 />
                                                                                        Remove
                                                                                    </Button>
                                                                                </td>
                                                                            </tr>
                                                                        );
                                                                    },
                                                                )}
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                )}

                                                <div className="border-t pt-5">
                                                    <ConnectionAddCombobox
                                                        connections={
                                                            connections
                                                        }
                                                        disabledValues={policies.map(
                                                            (policy) =>
                                                                String(
                                                                    policy.database_connection_id,
                                                                ),
                                                        )}
                                                        onAdd={(connectionId) =>
                                                            addPolicy(
                                                                Number(
                                                                    connectionId,
                                                                ),
                                                            )
                                                        }
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            <div className="flex flex-col gap-3 border-t pt-5 sm:flex-row sm:items-center sm:justify-between">
                                <p className="max-w-2xl text-xs leading-5 text-muted-foreground">
                                    Policy changes apply to newly created
                                    requests. Existing approved work keeps its
                                    recorded permissions.
                                </p>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button variant="outline" asChild>
                                        <Link href={rolesIndex()}>
                                            <X />
                                            Cancel
                                        </Link>
                                    </Button>
                                    <Button disabled={processing}>
                                        <Check />
                                        {isEditing
                                            ? 'Save changes'
                                            : 'Create role'}
                                    </Button>
                                </div>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

RoleForm.layout = {
    breadcrumbs: [
        {
            title: 'Roles',
            href: rolesIndex(),
        },
    ],
};
