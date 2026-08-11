import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Database, Plus, Server } from 'lucide-react';
import { EmptyState } from '@/components/crucible/empty-state';
import { PageHeader } from '@/components/crucible/page-header';
import { StatusBadge } from '@/components/crucible/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { driverLabel } from '@/lib/crucible';
import type { DatabaseConnectionSummary, Paginated } from '@/lib/crucible';
import { create, index, show } from '@/routes/connections';

type Props = {
    connections: Paginated<DatabaseConnectionSummary>;
    can_create: boolean;
};

export default function ConnectionsIndex({ connections, can_create }: Props) {
    return (
        <>
            <Head title="Database connections" />

            <div className="crucible-page">
                <PageHeader
                    icon={Database}
                    eyebrow="Inventory"
                    title="Database Connections"
                    description={`${connections.total} configured targets`}
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
                    <CardContent className="p-0">
                        {connections.data.length === 0 ? (
                            <div className="p-6">
                                <EmptyState
                                    icon={Server}
                                    title="No connections found"
                                    detail="Register a database target before submitting query requests."
                                    action={
                                        can_create && (
                                            <Button asChild size="sm">
                                                <Link href={create()}>
                                                    <Plus />
                                                    New Connection
                                                </Link>
                                            </Button>
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
