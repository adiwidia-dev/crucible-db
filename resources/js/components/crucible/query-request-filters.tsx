import { Form, Link } from '@inertiajs/react';
import { Filter, RotateCcw, Search } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { statusLabel } from '@/lib/crucible';
import type {
    QueryRequestFilterOptions,
    QueryRequestFilters,
} from '@/lib/crucible';

type Props = {
    action: string;
    clearHref: string;
    filters: QueryRequestFilters;
    options: QueryRequestFilterOptions;
};

export function QueryRequestFilters({
    action,
    clearHref,
    filters,
    options,
}: Props) {
    return (
        <Form
            action={action}
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
                    <div className="grid gap-2 md:grid-cols-[minmax(14rem,1.3fr)_repeat(4,minmax(8rem,1fr))_auto]">
                        <div className="relative">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                name="search"
                                defaultValue={filters.search}
                                placeholder="Request, requester, connection"
                                className="pl-9"
                            />
                        </div>
                        <select
                            name="status"
                            defaultValue={filters.status}
                            className="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                        >
                            <option value="">All statuses</option>
                            {options.statuses.map((status) => (
                                <option key={status} value={status}>
                                    {statusLabel(status)}
                                </option>
                            ))}
                        </select>
                        <select
                            name="request_kind"
                            defaultValue={filters.request_kind}
                            className="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                        >
                            <option value="">All request types</option>
                            {options.request_kinds.map((kind) => (
                                <option key={kind} value={kind}>
                                    {kind === 'query_access'
                                        ? 'Query Access'
                                        : 'Single Execution'}
                                </option>
                            ))}
                        </select>
                        <select
                            name="query_type"
                            defaultValue={filters.query_type}
                            className="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                        >
                            <option value="">All access modes</option>
                            {options.query_types.map((type) => (
                                <option key={type} value={type}>
                                    {statusLabel(type)}
                                </option>
                            ))}
                        </select>
                        <select
                            name="connection_id"
                            defaultValue={filters.connection_id}
                            className="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                        >
                            <option value="">All connections</option>
                            {options.connections.map((connection) => (
                                <option
                                    key={connection.id}
                                    value={String(connection.id)}
                                >
                                    {connection.name}
                                </option>
                            ))}
                        </select>
                        <div className="flex gap-2">
                            <Button disabled={processing}>
                                <Filter />
                                Apply
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href={clearHref}>
                                    <RotateCcw />
                                    Reset
                                </Link>
                            </Button>
                        </div>
                    </div>
                </>
            )}
        </Form>
    );
}
