import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Download, Filter, Fingerprint, RotateCcw, Search } from 'lucide-react';
import { EmptyState } from '@/components/crucible/empty-state';
import { Pagination } from '@/components/crucible/pagination';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/crucible';
import type { Paginated } from '@/lib/crucible';
import { exportMethod as exportAuditLogs, index } from '@/routes/audit-logs';
import type { Auth } from '@/types';

type AuditLog = {
    id: number;
    action: string;
    actor: string;
    auditable_type: string | null;
    auditable_id: number | null;
    ip_address: string | null;
    metadata: Record<string, unknown> | null;
    created_at: string | null;
};

type AuditLogFilters = {
    search: string;
    action: string;
    actor: string;
    ip_address: string;
};

type AuditLogFilterOptions = {
    actions: string[];
};

type Props = {
    audit_logs: Paginated<AuditLog>;
    filters: AuditLogFilters;
    filter_options: AuditLogFilterOptions;
};

export default function AuditLogsIndex({
    audit_logs,
    filters,
    filter_options,
}: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const userTimezone = auth.user.timezone ?? 'UTC';

    return (
        <>
            <Head title="Audit logs" />

            <div className="space-y-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <Heading
                        variant="small"
                        title="Audit logs"
                        description={`${audit_logs.total} recorded events`}
                    />
                    <Button variant="outline" asChild>
                        <a
                            href={exportAuditLogs.url({
                                query: filters,
                            })}
                        >
                            <Download />
                            Export CSV
                        </a>
                    </Button>
                </div>

                <Card>
                    <CardHeader className="border-b px-4 pb-4 sm:px-6">
                        <CardTitle>Event Stream</CardTitle>
                        <CardDescription>
                            Actor, subject, source, metadata, and timestamp.
                        </CardDescription>
                    </CardHeader>
                    <Form
                        action={index.url()}
                        method="get"
                        options={{ preserveScroll: true, preserveState: true }}
                        className="grid gap-3 border-b bg-muted/20 p-4 sm:px-6"
                    >
                        {({ processing }) => (
                            <>
                                <div className="flex items-center gap-2 text-sm font-medium">
                                    <Filter className="size-4 text-muted-foreground" />
                                    Filters
                                </div>
                                <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                                    <div className="relative">
                                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                        <Input
                                            name="search"
                                            defaultValue={filters.search}
                                            placeholder="Action, subject, actor, metadata"
                                            className="pl-9"
                                        />
                                    </div>
                                    <select
                                        name="action"
                                        defaultValue={filters.action}
                                        className="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                    >
                                        <option value="">All actions</option>
                                        {filter_options.actions.map(
                                            (action) => (
                                                <option
                                                    key={action}
                                                    value={action}
                                                >
                                                    {action}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                    <Input
                                        name="actor"
                                        defaultValue={filters.actor}
                                        placeholder="Actor"
                                    />
                                    <Input
                                        name="ip_address"
                                        defaultValue={filters.ip_address}
                                        placeholder="IP address"
                                    />
                                </div>
                                <div className="flex flex-wrap gap-2">
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
                            </>
                        )}
                    </Form>
                    <CardContent className="p-0">
                        {audit_logs.data.length === 0 ? (
                            <div className="p-6">
                                <EmptyState
                                    icon={Fingerprint}
                                    title="No audit events found"
                                    detail="Events will appear here as users change access, submit reviews, and execute queries."
                                />
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[76rem] text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/40 text-left text-xs text-muted-foreground uppercase">
                                            <th className="py-3 pr-4 pl-4 font-medium sm:pl-6">
                                                Action
                                            </th>
                                            <th className="py-3 pr-4 font-medium">
                                                Actor
                                            </th>
                                            <th className="py-3 pr-4 font-medium">
                                                Subject
                                            </th>
                                            <th className="py-3 pr-4 font-medium">
                                                IP
                                            </th>
                                            <th className="py-3 pr-4 font-medium">
                                                Metadata
                                            </th>
                                            <th className="py-3 pr-4 font-medium">
                                                Time
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {audit_logs.data.map((log) => (
                                            <tr
                                                key={log.id}
                                                className="border-b align-top transition-colors last:border-0 hover:bg-accent/40"
                                            >
                                                <td className="py-3.5 pr-4 pl-4 sm:pl-6">
                                                    <span className="inline-flex items-center rounded-full border bg-background px-2.5 py-1 font-mono text-xs font-medium shadow-xs">
                                                        {log.action}
                                                    </span>
                                                </td>
                                                <td className="py-3.5 pr-4 font-medium">
                                                    {log.actor}
                                                </td>
                                                <td className="py-3.5 pr-4 text-muted-foreground">
                                                    {log.auditable_type
                                                        ? `${log.auditable_type} #${log.auditable_id}`
                                                        : 'None'}
                                                </td>
                                                <td className="py-3.5 pr-4 font-mono text-xs">
                                                    {log.ip_address ?? 'n/a'}
                                                </td>
                                                <td className="max-w-[28rem] py-3.5 pr-4">
                                                    <pre className="max-h-28 overflow-auto rounded-md border bg-muted/50 p-3 text-xs leading-5">
                                                        {JSON.stringify(
                                                            log.metadata ?? {},
                                                            null,
                                                            2,
                                                        )}
                                                    </pre>
                                                </td>
                                                <td className="py-3.5 pr-4 text-muted-foreground">
                                                    {formatDate(
                                                        log.created_at,
                                                        userTimezone,
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                    <Pagination pagination={audit_logs} />
                </Card>
            </div>
        </>
    );
}

AuditLogsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Audit Logs',
            href: index(),
        },
    ],
};
