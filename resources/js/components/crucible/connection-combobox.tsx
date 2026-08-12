import { Combobox } from '@cloudflare/kumo/components/combobox';
import { Database, SearchX } from 'lucide-react';
import { useCallback } from 'react';
import { driverLabel } from '@/lib/crucible';
import type { DatabaseConnectionSummary } from '@/lib/crucible';

type ConnectionOption = Pick<
    DatabaseConnectionSummary,
    'id' | 'name' | 'driver'
>;

type Props = {
    connections: ConnectionOption[];
    error?: string;
    onValueChange: (value: string) => void;
    value: string;
};

export function ConnectionCombobox({
    connections,
    error,
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
            <input type="hidden" name="database_connection_id" value={value} />
            <Combobox
                items={connections}
                value={selectedConnection ?? null}
                onValueChange={(connection) =>
                    onValueChange(connection ? String(connection.id) : '')
                }
                itemToStringValue={(connection) =>
                    `${connection.name} (${driverLabel(connection.driver)})`
                }
                filter={filter}
                label="Connection"
                description="Search by connection name or database type."
                error={error}
                required
            >
                <Combobox.TriggerValue
                    placeholder="Select a connection"
                    className="w-full"
                />
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
