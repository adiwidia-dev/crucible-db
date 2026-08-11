import { Form, Head, Link } from '@inertiajs/react';
import {
    Check,
    ChevronDown,
    Database,
    Plus,
    Shield,
    Trash2,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import RoleController from '@/actions/App/Http/Controllers/RoleController';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
            requires_approval: boolean;
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
    requires_approval: boolean;
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
                  requires_approval: policy.requires_approval,
              }))
            : [],
    );
    const selectedConnectionIds = useMemo(
        () => new Set(policies.map((policy) => policy.database_connection_id)),
        [policies],
    );
    const availableConnections = useMemo(() => {
        return connections.filter(
            (connection) => !selectedConnectionIds.has(connection.id),
        );
    }, [connections, selectedConnectionIds]);
    const allConnectionsAdded =
        connections.length > 0 && availableConnections.length === 0;

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
                requires_approval: true,
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
                    icon={Shield}
                    eyebrow="Identity"
                    title={isEditing ? 'Edit Role' : 'New Role'}
                    description={
                        isEditing
                            ? `${role?.name} controls database access and review requirements.`
                            : 'Create a role and define its database access policy.'
                    }
                />

                <Form
                    {...action}
                    options={{ preserveScroll: true }}
                    className="grid gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <Card>
                                <CardHeader className="border-b px-4 pb-4 sm:px-6">
                                    <CardTitle>Role Profile</CardTitle>
                                    <CardDescription>
                                        Role name and operator-facing
                                        description.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid max-w-2xl gap-5 pt-6">
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

                            <Card>
                                <CardHeader className="border-b px-4 pb-4 sm:px-6">
                                    <CardTitle>Database Policies</CardTitle>
                                    <CardDescription>
                                        Define connection access, reviewer
                                        authority, and whether query requests
                                        need approval.
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
                                        <div className="grid gap-5 p-4 sm:p-6">
                                            {policies.length === 0 ? (
                                                <div className="rounded-md border border-dashed bg-muted/20 p-6">
                                                    <div className="flex items-center gap-2 font-medium">
                                                        <Database className="size-4" />
                                                        No connection policies
                                                    </div>
                                                    <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
                                                        This role will not grant
                                                        database access until a
                                                        connection policy is
                                                        added.
                                                    </p>
                                                </div>
                                            ) : (
                                                <div className="overflow-x-auto rounded-md border">
                                                    <table className="w-full min-w-[1040px] text-sm">
                                                        <thead>
                                                            <tr className="border-b bg-muted/40 text-left text-xs text-muted-foreground uppercase">
                                                                <th className="py-3 pr-4 pl-4 font-medium">
                                                                    Connection
                                                                </th>
                                                                <th className="py-3 pr-4 font-medium">
                                                                    Access
                                                                </th>
                                                                <th className="py-3 pr-4 font-medium">
                                                                    Review
                                                                </th>
                                                                <th className="py-3 pr-4 font-medium">
                                                                    Approval
                                                                </th>
                                                                <th className="py-3 pr-4 font-medium">
                                                                    Remove
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
                                                                            className="border-b align-top last:border-0"
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
                                                                                    Can
                                                                                    review
                                                                                </label>
                                                                            </td>
                                                                            <td className="py-4 pr-4">
                                                                                <label className="inline-flex min-h-9 items-center gap-2 rounded-md border bg-background px-3 text-sm shadow-xs">
                                                                                    <input
                                                                                        type="hidden"
                                                                                        name={`policies[${index}][requires_approval]`}
                                                                                        value="0"
                                                                                    />
                                                                                    <input
                                                                                        type="checkbox"
                                                                                        name={`policies[${index}][requires_approval]`}
                                                                                        value="1"
                                                                                        checked={
                                                                                            policy.requires_approval
                                                                                        }
                                                                                        onChange={(
                                                                                            event,
                                                                                        ) =>
                                                                                            updatePolicy(
                                                                                                index,
                                                                                                {
                                                                                                    requires_approval:
                                                                                                        event
                                                                                                            .target
                                                                                                            .checked,
                                                                                                },
                                                                                            )
                                                                                        }
                                                                                        className="size-4 rounded border-input"
                                                                                    />
                                                                                    Needs
                                                                                    approval
                                                                                </label>
                                                                                <p className="mt-2 max-w-64 text-xs text-muted-foreground">
                                                                                    Uncheck
                                                                                    to
                                                                                    bypass
                                                                                    approval
                                                                                    for
                                                                                    this
                                                                                    connection.
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

                                            <div className="max-w-xl">
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger
                                                        asChild
                                                    >
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            className="h-10 w-full justify-between px-3 sm:w-96"
                                                        >
                                                            <span className="flex min-w-0 items-center gap-2">
                                                                <Database className="size-4 text-muted-foreground" />
                                                                <span className="truncate">
                                                                    {allConnectionsAdded
                                                                        ? 'All connections added'
                                                                        : 'Choose connection'}
                                                                </span>
                                                            </span>
                                                            <ChevronDown className="size-4 text-muted-foreground" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent
                                                        align="start"
                                                        className="w-[min(28rem,calc(100vw-2rem))] p-1"
                                                    >
                                                        {connections.map(
                                                            (connection) => {
                                                                const isSelected =
                                                                    selectedConnectionIds.has(
                                                                        connection.id,
                                                                    );

                                                                return (
                                                                    <DropdownMenuItem
                                                                        key={
                                                                            connection.id
                                                                        }
                                                                        disabled={
                                                                            isSelected
                                                                        }
                                                                        onSelect={() =>
                                                                            addPolicy(
                                                                                connection.id,
                                                                            )
                                                                        }
                                                                        className="grid cursor-pointer grid-cols-[1fr_auto] items-center gap-3 p-2"
                                                                    >
                                                                        <div className="min-w-0">
                                                                            <div className="truncate font-medium">
                                                                                {
                                                                                    connection.name
                                                                                }
                                                                            </div>
                                                                            <div className="mt-0.5 truncate font-mono text-xs text-muted-foreground">
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
                                                                            <div className="mt-2">
                                                                                <StatusBadge
                                                                                    value={
                                                                                        connection.driver
                                                                                    }
                                                                                    label={driverLabel(
                                                                                        connection.driver,
                                                                                    )}
                                                                                />
                                                                            </div>
                                                                        </div>
                                                                        <span className="inline-flex h-7 items-center gap-1.5 rounded-md border bg-background px-2 text-xs font-medium text-muted-foreground">
                                                                            {isSelected ? (
                                                                                <>
                                                                                    <Check className="size-3.5" />
                                                                                    Added
                                                                                </>
                                                                            ) : (
                                                                                <>
                                                                                    <Plus className="size-3.5" />
                                                                                    Add
                                                                                </>
                                                                            )}
                                                                        </span>
                                                                    </DropdownMenuItem>
                                                                );
                                                            },
                                                        )}
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </div>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            <div className="flex flex-wrap items-center gap-3">
                                <Button disabled={processing}>
                                    <Check />
                                    {isEditing ? 'Save Role' : 'Create Role'}
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href={rolesIndex()}>
                                        <X />
                                        Cancel
                                    </Link>
                                </Button>
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
