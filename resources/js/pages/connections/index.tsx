import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    Database,
    Filter,
    Plus,
    RotateCcw,
    Search,
    Server,
} from 'lucide-react';
import { EmptyState } from '@/components/crucible/empty-state';
import { PageHeader } from '@/components/crucible/page-header';
import { Pagination } from '@/components/crucible/pagination';
import { StatusBadge } from '@/components/crucible/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { driverLabel } from '@/lib/crucible';
import type { DatabaseConnectionSummary, Paginated } from '@/lib/crucible';
import { create, index, show } from '@/routes/connections';

type Props = {
    connections: Paginated<DatabaseConnectionSummary>;
    filters: {
        search: string;
        driver: string;
        status: string;
    };
    driver_options: Array<{
        value: string;
        label: string;
    }>;
    connection_count: number;
    can_create: boolean;
};

export default function ConnectionsIndex({
    connections,
    filters,
    driver_options,
    connection_count,
    can_create,
}: Props) {
    const hasActiveFilters = Boolean(
        filters.search || filters.driver || filters.status,
    );

    return (
        <>
            <Head title="Database connections" />

            <div className="crucible-page">
                <PageHeader
                    icon={Database}
                    eyebrow="Inventory"
                    title="Database Connections"
                    description={`${connection_count} configured targets`}
                    actions={
                        can_create && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus />
                                    New Connection
                                </Link>
                            </Button>
                        )
                    }
                />

                <Card>
                    <CardHeader className="border-b px-4 pb-4 sm:px-6">
                        <CardTitle>Connection Registry</CardTitle>
                    </CardHeader>
                    <Form
                        {...index.form()}
                        options={{
                            preserveScroll: true,
                            preserveState: true,
                            replace: true,
                        }}
                        className="grid gap-3 border-b bg-muted/20 p-4 sm:px-6"
                    >
                        {({ processing }) => (
                            <>
                                <div className="flex items-center gap-2 text-sm font-medium">
                                    <Filter className="size-4 text-muted-foreground" />
                                    Filter connections
                                </div>
                                <div className="grid gap-2 md:grid-cols-[minmax(16rem,1fr)_minmax(9rem,0.35fr)_minmax(9rem,0.35fr)_auto]">
                                    <div className="relative">
                                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                        <Input
                                            name="search"
                                            defaultValue={filters.search}
                                            placeholder="Name, host, database, or username"
                                            aria-label="Search connections"
                                            className="pl-9"
                                        />
                                    </div>
                                    <select
                                        name="driver"
                                        defaultValue={filters.driver}
                                        aria-label="Filter by driver"
                                        className="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                    >
                                        <option value="">All drivers</option>
                                        {driver_options.map((driver) => (
                                            <option
                                                key={driver.value}
                                                value={driver.value}
                                            >
                                                {driver.label}
                                            </option>
                                        ))}
                                    </select>
                                    <select
                                        name="status"
                                        defaultValue={filters.status}
                                        aria-label="Filter by status"
                                        className="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                    >
                                        <option value="">All statuses</option>
                                        <option value="active">Active</option>
                                        <option value="disabled">
                                            Disabled
                                        </option>
                                    </select>
                                    <div className="flex gap-2">
                                        <Button disabled={processing}>
                                            <Filter />
                                            Apply
                                        </Button>
                                        <Button variant="outline" asChild>
                                            <Link href={index()}>
                                                <RotateCcw />
                                                Reset
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            </>
                        )}
                    </Form>
                    <CardContent className="p-0">
                        {connections.data.length === 0 ? (
                            <div className="p-6">
                                <EmptyState
                                    icon={Server}
                                    title={
                                        hasActiveFilters
                                            ? 'No connections match these filters'
                                            : 'No connections found'
                                    }
                                    detail={
                                        hasActiveFilters
                                            ? 'Try a broader search or reset the filters.'
                                            : 'Register a database target before submitting query requests.'
                                    }
                                    action={
                                        hasActiveFilters ? (
                                            <Button
                                                asChild
                                                size="sm"
                                                variant="outline"
                                            >
                                                <Link href={index()}>
                                                    <RotateCcw />
                                                    Reset filters
                                                </Link>
                                            </Button>
                                        ) : (
                                            can_create && (
                                                <Button asChild size="sm">
                                                    <Link href={create()}>
                                                        <Plus />
                                                        New Connection
                                                    </Link>
                                                </Button>
                                            )
                                        )
                                    }
                                />
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/40 text-left text-xs text-muted-foreground uppercase">
                                            <th className="py-3 pr-4 pl-4 font-medium sm:pl-6">
                                                Name
                                            </th>
                                            <th className="py-3 pr-4 font-medium">
                                                Driver
                                            </th>
                                            <th className="py-3 pr-4 font-medium">
                                                Endpoint
                                            </th>
                                            <th className="py-3 pr-4 font-medium">
                                                Database
                                            </th>
                                            <th className="py-3 pr-4 font-medium">
                                                Requests
                                            </th>
                                            <th className="py-3 pr-4 font-medium">
                                                Status
                                            </th>
                                            <th className="py-3 pr-4 font-medium">
                                                Open
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {connections.data.map((connection) => (
                                            <tr
                                                key={connection.id}
                                                className="border-b transition-colors last:border-0 hover:bg-accent/40"
                                            >
                                                <td className="py-3.5 pr-4 pl-4 sm:pl-6">
                                                    <Link
                                                        href={show(
                                                            connection.id,
                                                        )}
                                                        className="font-medium hover:text-primary"
                                                    >
                                                        {connection.name}
                                                    </Link>
                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                        {connection.username}
                                                    </div>
                                                </td>
                                                <td className="py-3.5 pr-4">
                                                    <StatusBadge
                                                        value={
                                                            connection.driver
                                                        }
                                                        label={driverLabel(
                                                            connection.driver,
                                                        )}
                                                    />
                                                </td>
                                                <td className="py-3.5 pr-4 font-mono text-xs text-muted-foreground">
                                                    {connection.host}:
                                                    {connection.port}
                                                </td>
                                                <td className="py-3.5 pr-4 font-medium">
                                                    {connection.database}
                                                </td>
                                                <td className="py-3.5 pr-4">
                                                    <span className="tabular-nums">
                                                        {
                                                            connection.query_requests_count
                                                        }
                                                    </span>
                                                </td>
                                                <td className="py-3.5 pr-4">
                                                    <StatusBadge
                                                        value={
                                                            connection.is_active
                                                                ? 'active'
                                                                : 'disabled'
                                                        }
                                                    />
                                                </td>
                                                <td className="py-3.5 pr-4">
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={show(
                                                                connection.id,
                                                            )}
                                                            aria-label={`Open ${connection.name}`}
                                                        >
                                                            <ArrowRight />
                                                        </Link>
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                    <Pagination pagination={connections} />
                </Card>
            </div>
        </>
    );
}

ConnectionsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Connections',
            href: index(),
        },
    ],
};
