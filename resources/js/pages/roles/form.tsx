import { Form, Head, Link } from '@inertiajs/react';
import {
    Check,
    ChevronDown,
    Database,
    FolderTree,
    Trash2,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import RoleController from '@/actions/App/Http/Controllers/RoleController';
import {
    ConnectionAddCombobox,
    ConnectionGroupAddCombobox,
} from '@/components/crucible/connection-combobox';
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
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
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
    group_policies: Record<
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
    connection_groups: ConnectionGroup[];
    access_modes: AccessMode[];
};

type ConnectionGroup = {
    id: number;
    name: string;
    description: string | null;
    database_connections_count: number;
};

type PolicyDraft = {
    database_connection_id: number;
    access_mode: AccessMode;
    can_review: boolean;
    read_requires_approval: boolean;
    write_requires_approval: boolean;
    max_write_session_minutes: number | null;
};

type GroupPolicyDraft = {
    connection_group_id: number;
    access_mode: AccessMode;
    can_review: boolean;
    read_requires_approval: boolean;
    write_requires_approval: boolean;
    max_write_session_minutes: number | null;
};

export default function RoleForm({
    role,
    connections,
    connection_groups: connectionGroups,
    access_modes,
}: Props) {
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
    const [groupPolicies, setGroupPolicies] = useState<GroupPolicyDraft[]>(
        () =>
            role
                ? Object.entries(role.group_policies).map(
                      ([connectionGroupId, policy]) => ({
                          connection_group_id: Number(connectionGroupId),
                          access_mode: policy.access_mode,
                          can_review: policy.can_review,
                          read_requires_approval: policy.read_requires_approval,
                          write_requires_approval:
                              policy.write_requires_approval,
                          max_write_session_minutes:
                              policy.max_write_session_minutes,
                      }),
                  )
                : [],
    );
    const selectedConnectionGroupIds = useMemo(
        () =>
            new Set(groupPolicies.map((policy) => policy.connection_group_id)),
        [groupPolicies],
    );
    const [isConnectionExceptionsOpen, setIsConnectionExceptionsOpen] =
        useState(policies.length > 0);
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
                write_requires_approval: false,
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

    const addGroupPolicy = (connectionGroupId: number) => {
        if (selectedConnectionGroupIds.has(connectionGroupId)) {
            return;
        }

        setGroupPolicies((currentPolicies) => [
            ...currentPolicies,
            {
                connection_group_id: connectionGroupId,
                access_mode: 'read',
                can_review: false,
                read_requires_approval: false,
                write_requires_approval: false,
                max_write_session_minutes: null,
            },
        ]);
    };

    const updateGroupPolicy = (
        index: number,
        attributes: Partial<Omit<GroupPolicyDraft, 'connection_group_id'>>,
    ) => {
        setGroupPolicies((currentPolicies) =>
            currentPolicies.map((policy, currentIndex) =>
                currentIndex === index ? { ...policy, ...attributes } : policy,
            ),
        );
    };

    const removeGroupPolicy = (index: number) => {
        setGroupPolicies((currentPolicies) =>
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
                                        Set the access and approval rules for
                                        this role&apos;s connections.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="p-0">
                                    {connections.length === 0 &&
                                    connectionGroups.length === 0 ? (
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
                                        <div className="grid gap-7 p-4 sm:p-5">
                                            <section className="grid gap-4">
                                                <div className="grid gap-1">
                                                    <h3 className="text-base font-medium">
                                                        Connection groups
                                                    </h3>
                                                    <p className="max-w-3xl text-sm leading-5 text-muted-foreground">
                                                        Apply one policy to
                                                        every connection in a
                                                        group.
                                                    </p>
                                                </div>

                                                <ConnectionGroupAddCombobox
                                                    connectionGroups={
                                                        connectionGroups
                                                    }
                                                    label="Add a group"
                                                    description=""
                                                    disabledValues={groupPolicies.map(
                                                        (policy) =>
                                                            String(
                                                                policy.connection_group_id,
                                                            ),
                                                    )}
                                                    onAdd={(
                                                        connectionGroupId,
                                                    ) =>
                                                        addGroupPolicy(
                                                            Number(
                                                                connectionGroupId,
                                                            ),
                                                        )
                                                    }
                                                />

                                                {groupPolicies.length > 0 ? (
                                                    <div className="grid gap-3">
                                                        {groupPolicies.map(
                                                            (policy, index) => {
                                                                const connectionGroup =
                                                                    connectionGroups.find(
                                                                        (
                                                                            item,
                                                                        ) =>
                                                                            item.id ===
                                                                            policy.connection_group_id,
                                                                    );

                                                                if (
                                                                    !connectionGroup
                                                                ) {
                                                                    return null;
                                                                }

                                                                return (
                                                                    <div
                                                                        key={
                                                                            connectionGroup.id
                                                                        }
                                                                        className="rounded-md border bg-muted/10 p-4"
                                                                    >
                                                                        <input
                                                                            type="hidden"
                                                                            name={`group_policies[${index}][connection_group_id]`}
                                                                            value={
                                                                                connectionGroup.id
                                                                            }
                                                                        />
                                                                        <div className="flex flex-col gap-3 border-b pb-3 sm:flex-row sm:items-start sm:justify-between">
                                                                            <div className="flex min-w-0 items-start gap-3">
                                                                                <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-background text-muted-foreground ring-1 ring-border">
                                                                                    <FolderTree className="size-4" />
                                                                                </span>
                                                                                <div className="min-w-0">
                                                                                    <p className="font-medium">
                                                                                        {
                                                                                            connectionGroup.name
                                                                                        }
                                                                                    </p>
                                                                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                                                                        {
                                                                                            connectionGroup.database_connections_count
                                                                                        }{' '}
                                                                                        explicit
                                                                                        connections
                                                                                        {connectionGroup.description
                                                                                            ? ` · ${connectionGroup.description}`
                                                                                            : ''}
                                                                                    </p>
                                                                                </div>
                                                                            </div>
                                                                            <Button
                                                                                type="button"
                                                                                variant="outline"
                                                                                size="sm"
                                                                                onClick={() =>
                                                                                    removeGroupPolicy(
                                                                                        index,
                                                                                    )
                                                                                }
                                                                            >
                                                                                <Trash2 />
                                                                                Remove
                                                                            </Button>
                                                                        </div>
                                                                        <div className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                                                                            <div className="grid gap-2">
                                                                                <Label>
                                                                                    Maximum
                                                                                    access
                                                                                </Label>
                                                                                <select
                                                                                    name={`group_policies[${index}][access_mode]`}
                                                                                    value={
                                                                                        policy.access_mode
                                                                                    }
                                                                                    onChange={(
                                                                                        event,
                                                                                    ) => {
                                                                                        const accessMode =
                                                                                            event
                                                                                                .target
                                                                                                .value as AccessMode;

                                                                                        updateGroupPolicy(
                                                                                            index,
                                                                                            {
                                                                                                access_mode:
                                                                                                    accessMode,
                                                                                                write_requires_approval:
                                                                                                    accessMode ===
                                                                                                    'write',
                                                                                                max_write_session_minutes:
                                                                                                    accessMode ===
                                                                                                    'write'
                                                                                                        ? policy.max_write_session_minutes
                                                                                                        : null,
                                                                                            },
                                                                                        );
                                                                                    }}
                                                                                    className="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                                                                >
                                                                                    {access_modes.map(
                                                                                        (
                                                                                            accessMode,
                                                                                        ) => (
                                                                                            <option
                                                                                                key={
                                                                                                    accessMode
                                                                                                }
                                                                                                value={
                                                                                                    accessMode
                                                                                                }
                                                                                            >
                                                                                                {accessMode ===
                                                                                                'none'
                                                                                                    ? 'No access'
                                                                                                    : accessMode ===
                                                                                                        'write'
                                                                                                      ? 'Write'
                                                                                                      : 'Read'}
                                                                                            </option>
                                                                                        ),
                                                                                    )}
                                                                                </select>
                                                                            </div>
                                                                            <div className="grid gap-2">
                                                                                <Label>
                                                                                    Reviewer
                                                                                </Label>
                                                                                <label className="flex h-9 items-center gap-2 rounded-md border border-input bg-background px-3 text-sm shadow-xs">
                                                                                    <input
                                                                                        type="hidden"
                                                                                        name={`group_policies[${index}][can_review]`}
                                                                                        value="0"
                                                                                    />
                                                                                    <input
                                                                                        type="checkbox"
                                                                                        name={`group_policies[${index}][can_review]`}
                                                                                        value="1"
                                                                                        checked={
                                                                                            policy.can_review
                                                                                        }
                                                                                        onChange={(
                                                                                            event,
                                                                                        ) =>
                                                                                            updateGroupPolicy(
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
                                                                            </div>
                                                                            <div className="grid gap-2">
                                                                                <Label>
                                                                                    Read
                                                                                    approval
                                                                                </Label>
                                                                                <label className="flex h-9 items-center gap-2 rounded-md border border-input bg-background px-3 text-sm shadow-xs">
                                                                                    <input
                                                                                        type="hidden"
                                                                                        name={`group_policies[${index}][read_requires_approval]`}
                                                                                        value="0"
                                                                                    />
                                                                                    <input
                                                                                        type="checkbox"
                                                                                        name={`group_policies[${index}][read_requires_approval]`}
                                                                                        value="1"
                                                                                        checked={
                                                                                            policy.read_requires_approval
                                                                                        }
                                                                                        onChange={(
                                                                                            event,
                                                                                        ) =>
                                                                                            updateGroupPolicy(
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
                                                                                    Required
                                                                                </label>
                                                                            </div>
                                                                            <div className="grid gap-2">
                                                                                <Label>
                                                                                    Write
                                                                                    approval
                                                                                </Label>
                                                                                <label className="flex h-9 items-center gap-2 rounded-md border border-input bg-background px-3 text-sm shadow-xs">
                                                                                    <input
                                                                                        type="hidden"
                                                                                        name={`group_policies[${index}][write_requires_approval]`}
                                                                                        value="0"
                                                                                    />
                                                                                    <input
                                                                                        type="checkbox"
                                                                                        name={`group_policies[${index}][write_requires_approval]`}
                                                                                        value="1"
                                                                                        checked={
                                                                                            policy.access_mode ===
                                                                                                'write' &&
                                                                                            policy.write_requires_approval
                                                                                        }
                                                                                        disabled={
                                                                                            policy.access_mode !==
                                                                                            'write'
                                                                                        }
                                                                                        onChange={(
                                                                                            event,
                                                                                        ) =>
                                                                                            updateGroupPolicy(
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
                                                                                    Required
                                                                                </label>
                                                                            </div>
                                                                            <div className="grid gap-2">
                                                                                <Label>
                                                                                    Write-session
                                                                                    limit
                                                                                </Label>
                                                                                <Input
                                                                                    type="number"
                                                                                    min={
                                                                                        5
                                                                                    }
                                                                                    max={
                                                                                        1440
                                                                                    }
                                                                                    name={`group_policies[${index}][max_write_session_minutes]`}
                                                                                    value={
                                                                                        policy.max_write_session_minutes ??
                                                                                        ''
                                                                                    }
                                                                                    onChange={(
                                                                                        event,
                                                                                    ) =>
                                                                                        updateGroupPolicy(
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
                                                                                />
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                );
                                                            },
                                                        )}
                                                    </div>
                                                ) : null}
                                            </section>

                                            <section className="border-t pt-2">
                                                <Collapsible
                                                    open={
                                                        isConnectionExceptionsOpen
                                                    }
                                                    onOpenChange={
                                                        setIsConnectionExceptionsOpen
                                                    }
                                                >
                                                    <CollapsibleTrigger
                                                        type="button"
                                                        className="flex w-full items-center justify-between gap-4 rounded-md px-1 py-3 text-left transition-colors hover:bg-muted/35 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                    >
                                                        <span className="grid gap-1">
                                                            <span className="text-base font-medium">
                                                                Individual
                                                                connection
                                                                exceptions
                                                            </span>
                                                            <span className="text-sm leading-5 text-muted-foreground">
                                                                Use only when
                                                                one connection
                                                                needs a
                                                                different policy
                                                                than its group.
                                                            </span>
                                                        </span>
                                                        <ChevronDown
                                                            className={`size-4 shrink-0 text-muted-foreground transition-transform motion-reduce:transition-none ${
                                                                isConnectionExceptionsOpen
                                                                    ? 'rotate-180'
                                                                    : ''
                                                            }`}
                                                        />
                                                    </CollapsibleTrigger>
                                                    <CollapsibleContent>
                                                        <div className="grid gap-4 pt-3">
                                                            {policies.length >
                                                                0 && (
                                                                <div>
                                                                    <table className="w-full text-sm">
                                                                        <thead className="sr-only">
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
                                                                                            className="relative mb-3 grid grid-cols-1 gap-4 rounded-md border bg-muted/10 p-4 text-left transition-colors last:mb-0 hover:bg-muted/20 sm:grid-cols-2 xl:grid-cols-5"
                                                                                        >
                                                                                            <td className="col-span-full min-w-0 border-b pr-12 pb-3">
                                                                                                <input
                                                                                                    type="hidden"
                                                                                                    name={`policies[${index}][database_connection_id]`}
                                                                                                    value={
                                                                                                        connection.id
                                                                                                    }
                                                                                                />
                                                                                                <div className="flex min-w-0 items-start gap-3">
                                                                                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-background text-muted-foreground ring-1 ring-border">
                                                                                                        <Database className="size-4" />
                                                                                                    </span>
                                                                                                    <div className="min-w-0">
                                                                                                        <p className="font-medium">
                                                                                                            {
                                                                                                                connection.name
                                                                                                            }
                                                                                                        </p>
                                                                                                        <p className="mt-0.5 font-mono text-xs text-muted-foreground">
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
                                                                                                        </p>
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
                                                                                                    </div>
                                                                                                </div>
                                                                                            </td>
                                                                                            <td className="grid gap-2">
                                                                                                <Label>
                                                                                                    Maximum
                                                                                                    access
                                                                                                </Label>
                                                                                                <select
                                                                                                    name={`policies[${index}][access_mode]`}
                                                                                                    value={
                                                                                                        policy.access_mode
                                                                                                    }
                                                                                                    onChange={(
                                                                                                        event,
                                                                                                    ) => {
                                                                                                        const accessMode =
                                                                                                            event
                                                                                                                .target
                                                                                                                .value as AccessMode;

                                                                                                        updatePolicy(
                                                                                                            index,
                                                                                                            {
                                                                                                                access_mode:
                                                                                                                    accessMode,
                                                                                                                write_requires_approval:
                                                                                                                    accessMode ===
                                                                                                                    'write',
                                                                                                                max_write_session_minutes:
                                                                                                                    accessMode ===
                                                                                                                    'write'
                                                                                                                        ? policy.max_write_session_minutes
                                                                                                                        : null,
                                                                                                            },
                                                                                                        );
                                                                                                    }}
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
                                                                                            <td className="grid gap-2">
                                                                                                <Label>
                                                                                                    Reviewer
                                                                                                </Label>
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
                                                                                            <td className="grid gap-2">
                                                                                                <Label>
                                                                                                    Read
                                                                                                    approval
                                                                                                </Label>
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
                                                                                                    Required
                                                                                                </label>
                                                                                            </td>
                                                                                            <td className="grid gap-2">
                                                                                                <Label>
                                                                                                    Write
                                                                                                    approval
                                                                                                </Label>
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
                                                                                                            policy.access_mode ===
                                                                                                                'write' &&
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
                                                                                                    Required
                                                                                                </label>
                                                                                            </td>
                                                                                            <td className="grid gap-2">
                                                                                                <Label>
                                                                                                    Write-session
                                                                                                    limit
                                                                                                </Label>
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
                                                                                            </td>
                                                                                            <td className="absolute top-4 right-4">
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

                                                            <div>
                                                                <ConnectionAddCombobox
                                                                    connections={
                                                                        connections
                                                                    }
                                                                    label="Add connection exception"
                                                                    description=""
                                                                    disabledValues={policies.map(
                                                                        (
                                                                            policy,
                                                                        ) =>
                                                                            String(
                                                                                policy.database_connection_id,
                                                                            ),
                                                                    )}
                                                                    onAdd={(
                                                                        connectionId,
                                                                    ) =>
                                                                        addPolicy(
                                                                            Number(
                                                                                connectionId,
                                                                            ),
                                                                        )
                                                                    }
                                                                />
                                                            </div>
                                                        </div>
                                                    </CollapsibleContent>
                                                </Collapsible>
                                            </section>
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
