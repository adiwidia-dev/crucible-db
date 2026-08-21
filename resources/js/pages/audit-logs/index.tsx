import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    Download,
    Filter,
    Fingerprint,
    RotateCcw,
    Search,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import { DataRegistry } from '@/components/crucible/data-registry';
import { EmptyState } from '@/components/crucible/empty-state';
import { PageHeader } from '@/components/crucible/page-header';
import { Pagination } from '@/components/crucible/pagination';
import { Button } from '@/components/ui/button';
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
    const [expandedLogIds, setExpandedLogIds] = useState<Set<number>>(
        () => new Set(),
    );

    const toggleLogDetails = (logId: number): void => {
        setExpandedLogIds((currentLogIds) => {
            const nextLogIds = new Set(currentLogIds);

            if (nextLogIds.has(logId)) {
                nextLogIds.delete(logId);
            } else {
                nextLogIds.add(logId);
            }

            return nextLogIds;
        });
    };

    return (
        <>
            <Head title="Audit logs" />

            <div className="crucible-page">
                <PageHeader
                    title="Audit logs"
                    description={`${audit_logs.total} recorded events`}
                    actions={
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
                    }
                />

                <DataRegistry
                    title="Event stream"
                    description="Expand an event to inspect its recorded metadata."
                >
                    <Form
                        action={index.url()}
                        method="get"
                        options={{ preserveScroll: true, preserveState: true }}
                        className="grid gap-3 border-b bg-muted/15 p-3 sm:px-4"
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
                                            aria-label="Search audit events"
                                        />
                                    </div>
                                    <select
                                        name="action"
                                        defaultValue={filters.action}
                                        aria-label="Filter by event action"
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
                                        aria-label="Filter by actor"
                                    />
                                    <Input
                                        name="ip_address"
                                        defaultValue={filters.ip_address}
                                        placeholder="IP address"
                                        aria-label="Filter by IP address"
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
                    <div>
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
                                <table className="w-full min-w-[58rem] text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/45 text-left text-xs text-muted-foreground">
                                            <th className="w-11 py-2.5 pl-3 sm:pl-4">
                                                <span className="sr-only">
                                                    Event details
                                                </span>
                                            </th>
                                            <th className="py-2.5 pr-4 pl-3 font-medium sm:pl-4">
                                                Action
                                            </th>
                                            <th className="py-2.5 pr-4 font-medium">
                                                Actor
                                            </th>
                                            <th className="py-2.5 pr-4 font-medium">
                                                Subject
                                            </th>
                                            <th className="py-2.5 pr-4 font-medium">
                                                IP
                                            </th>
                                            <th className="py-2.5 pr-3 font-medium sm:pr-4">
                                                Time
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {audit_logs.data.map((log) => {
                                            const isExpanded =
                                                expandedLogIds.has(log.id);
                                            const hasMetadata =
                                                log.metadata !== null &&
                                                Object.keys(log.metadata)
                                                    .length > 0;

                                            return (
                                                <Fragment key={log.id}>
                                                    <tr
                                                        key={log.id}
                                                        className="border-b align-top transition-colors hover:bg-accent/40"
                                                    >
                                                        <td className="py-2.5 pl-3 sm:pl-4">
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    toggleLogDetails(
                                                                        log.id,
                                                                    )
                                                                }
                                                                aria-expanded={
                                                                    isExpanded
                                                                }
                                                                aria-label={`${isExpanded ? 'Hide' : 'Show'} details for ${log.action}`}
                                                                className="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                                            >
                                                                {isExpanded ? (
                                                                    <ChevronDown className="size-4" />
                                                                ) : (
                                                                    <ChevronRight className="size-4" />
                                                                )}
                                                            </button>
                                                        </td>
                                                        <td className="py-3 pr-4 pl-3">
                                                            <span className="inline-flex items-center rounded-full border bg-background px-2.5 py-1 font-mono text-xs font-medium shadow-xs">
                                                                {log.action}
                                                            </span>
                                                        </td>
                                                        <td className="py-3 pr-4 font-medium">
                                                            {log.actor}
                                                        </td>
                                                        <td className="py-3 pr-4 text-muted-foreground">
                                                            {log.auditable_type
                                                                ? `${log.auditable_type} #${log.auditable_id}`
                                                                : 'None'}
                                                        </td>
                                                        <td className="py-3 pr-4 font-mono text-xs">
                                                            {log.ip_address ??
                                                                'n/a'}
                                                        </td>
                                                        <td className="py-3 pr-3 text-muted-foreground sm:pr-4">
                                                            {formatDate(
                                                                log.created_at,
                                                                userTimezone,
                                                            )}
                                                        </td>
                                                    </tr>
                                                    {isExpanded && (
                                                        <tr
                                                            key={`${log.id}-details`}
                                                            className="border-b bg-muted/20 last:border-0"
                                                        >
                                                            <td
                                                                colSpan={6}
                                                                className="px-3 py-3 sm:px-4"
                                                            >
                                                                <div className="grid gap-2 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-start">
                                                                    <p className="pt-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                                                        Metadata
                                                                    </p>
                                                                    {hasMetadata ? (
                                                                        <pre className="max-h-72 overflow-auto rounded-md border bg-background p-3 font-mono text-xs leading-5 text-foreground">
                                                                            {JSON.stringify(
                                                                                log.metadata,
                                                                                null,
                                                                                2,
                                                                            )}
                                                                        </pre>
                                                                    ) : (
                                                                        <p className="rounded-md border border-dashed bg-background px-3 py-2 text-sm text-muted-foreground">
                                                                            No
                                                                            metadata
                                                                            was
                                                                            recorded
                                                                            for
                                                                            this
                                                                            event.
                                                                        </p>
                                                                    )}
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    )}
                                                </Fragment>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                    <Pagination pagination={audit_logs} />
                </DataRegistry>
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
