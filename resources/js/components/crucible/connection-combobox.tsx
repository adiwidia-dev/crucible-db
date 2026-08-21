import { Combobox } from '@cloudflare/kumo/components/combobox';
import { Check, Database, FolderTree, Plus, SearchX } from 'lucide-react';
import { useCallback, useState } from 'react';
import { driverLabel } from '@/lib/crucible';
import type { DatabaseConnectionSummary } from '@/lib/crucible';

type ConnectionOption = Pick<
    DatabaseConnectionSummary,
    'id' | 'name' | 'driver' | 'host' | 'port' | 'database'
>;

type Props = {
    connections: ConnectionOption[];
    description?: string;
    error?: string;
    label?: string;
    name?: string | null;
    onValueChange: (value: string) => void;
    value: string;
};

type MultiProps = Omit<Props, 'onValueChange' | 'value'> & {
    onValueChange: (values: string[]) => void;
    values: string[];
};

type AddProps = {
    connections: ConnectionOption[];
    description?: string;
    disabledValues: string[];
    label?: string;
    onAdd: (value: string) => void;
};

type ConnectionGroupOption = {
    id: number;
    name: string;
    description: string | null;
    database_connections_count: number;
};

type GroupAddProps = {
    connectionGroups: ConnectionGroupOption[];
    description?: string;
    disabledValues: string[];
    label?: string;
    onAdd: (value: string) => void;
};

export function ConnectionCombobox({
    connections,
    description,
    error,
    label,
    name = 'database_connection_id',
    onValueChange,
    value,
}: Props) {
    const { contains } = Combobox.useFilter();
    const selectedConnection = connections.find(
        (connection) => String(connection.id) === value,
    );
    const filter = useCallback(
        (connection: ConnectionOption, query: string): boolean =>
            contains(connection.name, query) ||
            contains(driverLabel(connection.driver), query),
        [contains],
    );

    return (
        <>
            {name && <input type="hidden" name={name} value={value} />}
            <Combobox
                items={connections}
                value={selectedConnection ?? null}
                onValueChange={(connection) =>
                    onValueChange(connection ? String(connection.id) : '')
                }
                filter={filter}
                label={label ?? 'Connection'}
                description={
                    description ?? 'Search by connection name or database type.'
                }
                error={error}
                required
            >
                <Combobox.TriggerValue
                    placeholder="Select a connection"
                    className="w-full"
                >
                    {(connection: ConnectionOption | null) =>
                        connection?.name ?? 'Select a connection'
                    }
                </Combobox.TriggerValue>
                <Combobox.Content className="max-h-72">
                    <Combobox.Input
                        placeholder="Search connections..."
                        aria-label="Search connections"
                    />
                    <Combobox.List>
                        {(connection: ConnectionOption) => (
                            <Combobox.Item
                                key={connection.id}
                                value={connection}
                                className="py-2 text-sm"
                            >
                                <div className="flex min-w-0 items-center gap-3">
                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-kumo-tint text-kumo-default ring-1 ring-kumo-line">
                                        <Database className="size-4" />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-medium">
                                            {connection.name}
                                        </span>
                                        <span className="block text-xs text-kumo-subtle">
                                            {driverLabel(connection.driver)}
                                        </span>
                                    </span>
                                </div>
                            </Combobox.Item>
                        )}
                    </Combobox.List>
                    <Combobox.Empty>
                        <div className="flex items-center gap-2 py-1">
                            <SearchX className="size-4" />
                            <span>No matching connections</span>
                        </div>
                    </Combobox.Empty>
                </Combobox.Content>
            </Combobox>
        </>
    );
}

export function ConnectionMultiCombobox({
    connections,
    description,
    error,
    label,
    name = 'database_connection_ids[]',
    onValueChange,
    values,
}: MultiProps) {
    const { contains } = Combobox.useFilter();
    const selectedConnections = connections.filter((connection) =>
        values.includes(String(connection.id)),
    );
    const filter = useCallback(
        (connection: ConnectionOption, query: string): boolean =>
            contains(connection.name, query) ||
            contains(driverLabel(connection.driver), query),
        [contains],
    );

    return (
        <>
            {name &&
                values.map((value) => (
                    <input
                        key={value}
                        type="hidden"
                        name={name}
                        value={value}
                    />
                ))}
            <Combobox
                multiple
                items={connections}
                value={selectedConnections}
                onValueChange={(selected) =>
                    onValueChange(
                        (selected as ConnectionOption[]).map((connection) =>
                            String(connection.id),
                        ),
                    )
                }
                filter={filter}
                label={label ?? 'Connections'}
                description={
                    description ??
                    'Select every database that should be available in this session.'
                }
                error={error}
                required
            >
                <Combobox.TriggerMultipleWithInput
                    placeholder="Search and select connections..."
                    className="w-full"
                    value={selectedConnections}
                    renderItem={(connection: ConnectionOption) => (
                        <Combobox.Chip>{connection.name}</Combobox.Chip>
                    )}
                />
                <Combobox.Content className="max-h-72">
                    <Combobox.List>
                        {(connection: ConnectionOption) => (
                            <Combobox.Item
                                key={connection.id}
                                value={connection}
                                className="py-2 text-sm"
                            >
                                <div className="flex min-w-0 items-center gap-3">
                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-kumo-tint text-kumo-default ring-1 ring-kumo-line">
                                        <Database className="size-4" />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-medium">
                                            {connection.name}
                                        </span>
                                        <span className="block text-xs text-kumo-subtle">
                                            {driverLabel(connection.driver)}
                                        </span>
                                    </span>
                                </div>
                            </Combobox.Item>
                        )}
                    </Combobox.List>
                    <Combobox.Empty>
                        <div className="flex items-center gap-2 py-1">
                            <SearchX className="size-4" />
                            <span>No matching connections</span>
                        </div>
                    </Combobox.Empty>
                </Combobox.Content>
            </Combobox>
        </>
    );
}

export function ConnectionAddCombobox({
    connections,
    description = 'Search by connection name, host, database, or driver.',
    disabledValues,
    label = 'Add connection policy',
    onAdd,
}: AddProps) {
    const { contains } = Combobox.useFilter();
    const [value, setValue] = useState<ConnectionOption | null>(null);
    const disabledConnectionIds = new Set(disabledValues);
    const filter = useCallback(
        (connection: ConnectionOption, query: string): boolean =>
            contains(connection.name, query) ||
            contains(driverLabel(connection.driver), query) ||
            contains(connection.host ?? '', query) ||
            contains(connection.database ?? '', query),
        [contains],
    );

    return (
        <Combobox
            items={connections}
            value={value}
            onValueChange={(connection) => {
                if (!connection) {
                    setValue(null);

                    return;
                }

                const connectionId = String(
                    (connection as ConnectionOption).id,
                );

                if (!disabledConnectionIds.has(connectionId)) {
                    onAdd(connectionId);
                }

                setValue(null);
            }}
            filter={filter}
            label={label}
            description={description}
        >
            <Combobox.TriggerInput
                placeholder="Search and add a connection..."
                className="max-w-none"
            />
            <Combobox.Content className="max-h-80">
                <Combobox.List>
                    {(connection: ConnectionOption) => {
                        const isAdded = disabledConnectionIds.has(
                            String(connection.id),
                        );

                        return (
                            <Combobox.Item
                                key={connection.id}
                                value={connection}
                                disabled={isAdded}
                                className="py-2 text-sm"
                            >
                                <div className="grid min-w-0 grid-cols-[minmax(0,1fr)_auto] items-center gap-3">
                                    <div className="min-w-0">
                                        <div className="truncate font-medium">
                                            {connection.name}
                                        </div>
                                        <div className="mt-0.5 truncate font-mono text-xs text-kumo-subtle">
                                            {connection.host}:{connection.port}{' '}
                                            / {connection.database}
                                        </div>
                                        <div className="mt-1 text-xs text-kumo-subtle">
                                            {driverLabel(connection.driver)}
                                        </div>
                                    </div>
                                    <span className="inline-flex h-7 items-center gap-1.5 rounded-md border bg-background px-2 text-xs font-medium text-muted-foreground">
                                        {isAdded ? (
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
                                </div>
                            </Combobox.Item>
                        );
                    }}
                </Combobox.List>
                <Combobox.Empty>
                    <div className="flex items-center gap-2 py-1">
                        <SearchX className="size-4" />
                        <span>No matching connections</span>
                    </div>
                </Combobox.Empty>
            </Combobox.Content>
        </Combobox>
    );
}

export function ConnectionGroupAddCombobox({
    connectionGroups,
    description = 'Search by group name or description. Members receive this policy immediately.',
    disabledValues,
    label = 'Add a connection group',
    onAdd,
}: GroupAddProps) {
    const { contains } = Combobox.useFilter();
    const [value, setValue] = useState<ConnectionGroupOption | null>(null);
    const addedGroupIds = new Set(disabledValues);
    const filter = useCallback(
        (connectionGroup: ConnectionGroupOption, query: string): boolean =>
            contains(connectionGroup.name, query) ||
            contains(connectionGroup.description ?? '', query),
        [contains],
    );

    return (
        <Combobox
            items={connectionGroups}
            value={value}
            onValueChange={(connectionGroup) => {
                if (!connectionGroup) {
                    setValue(null);

                    return;
                }

                const connectionGroupId = String(
                    (connectionGroup as ConnectionGroupOption).id,
                );

                if (!addedGroupIds.has(connectionGroupId)) {
                    onAdd(connectionGroupId);
                }

                setValue(null);
            }}
            filter={filter}
            label={label}
            description={description}
        >
            <Combobox.TriggerInput
                placeholder="Search and add a connection group..."
                className="max-w-none"
            />
            <Combobox.Content className="max-h-80">
                <Combobox.List>
                    {(connectionGroup: ConnectionGroupOption) => {
                        const isAdded = addedGroupIds.has(
                            String(connectionGroup.id),
                        );

                        return (
                            <Combobox.Item
                                key={connectionGroup.id}
                                value={connectionGroup}
                                disabled={isAdded}
                                className="py-2 text-sm"
                            >
                                <div className="grid min-w-0 grid-cols-[minmax(0,1fr)_auto] items-center gap-3">
                                    <div className="flex min-w-0 items-center gap-3">
                                        <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-kumo-tint text-kumo-default ring-1 ring-kumo-line">
                                            <FolderTree className="size-4" />
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block truncate font-medium">
                                                {connectionGroup.name}
                                            </span>
                                            <span className="block truncate text-xs text-kumo-subtle">
                                                {
                                                    connectionGroup.database_connections_count
                                                }{' '}
                                                connections
                                                {connectionGroup.description
                                                    ? ` · ${connectionGroup.description}`
                                                    : ''}
                                            </span>
                                        </span>
                                    </div>
                                    <span className="inline-flex h-7 items-center gap-1.5 rounded-md border bg-background px-2 text-xs font-medium text-muted-foreground">
                                        {isAdded ? (
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
                                </div>
                            </Combobox.Item>
                        );
                    }}
                </Combobox.List>
                <Combobox.Empty>
                    <div className="flex items-center gap-2 py-1">
                        <SearchX className="size-4" />
                        <span>No matching connection groups</span>
                    </div>
                </Combobox.Empty>
            </Combobox.Content>
        </Combobox>
    );
}
