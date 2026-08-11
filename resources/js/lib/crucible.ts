export type AccessMode = 'none' | 'read' | 'write';
export type DatabaseDriver = 'mysql' | 'pgsql';
export type QueryRequestStatus =
    | 'pending_review'
    | 'approved'
    | 'rejected'
    | 'scheduled'
    | 'running'
    | 'completed'
    | 'failed'
    | 'cancelled';
export type QueryType = 'read' | 'write';
export type QueryRequestKind = 'single_execution' | 'query_access';
export type ExecutionStatus = 'running' | 'succeeded' | 'failed';

export type Paginated<T> = {
    data: T[];
    links: Array<{
        url: string | null;
        label: string;
        active: boolean;
    }>;
    from: number | null;
    to: number | null;
    total: number;
};

export type Role = {
    id: number;
    name: string;
    slug: string;
    description?: string | null;
    is_admin?: boolean;
};

export type DatabaseConnectionSummary = {
    id: number;
    name: string;
    driver: DatabaseDriver;
    host?: string;
    port?: number;
    database?: string;
    username?: string;
    is_active?: boolean;
    query_requests_count?: number;
};

export type QueryRequestSummary = {
    id: number;
    title: string;
    status: QueryRequestStatus;
    query_type: QueryType;
    latest_query_type: QueryType | null;
    effective_query_type: QueryType;
    request_kind: QueryRequestKind;
    requires_approval: boolean;
    scheduled_at: string | null;
    requester: string;
    connection: string;
    created_at: string | null;
    active_session_expires_at: string | null;
    latest_session_expires_at: string | null;
};

export function visibleQueryRequestStatus(
    request: Pick<
        QueryRequestSummary,
        'request_kind' | 'status' | 'latest_session_expires_at'
    >,
): QueryRequestStatus {
    if (
        request.request_kind === 'query_access' &&
        request.status === 'running' &&
        request.latest_session_expires_at &&
        new Date(request.latest_session_expires_at).getTime() <= Date.now()
    ) {
        return 'completed';
    }

    return request.status;
}

export type QueryRequestFilters = {
    search: string;
    status: string;
    request_kind: string;
    query_type: string;
    connection_id: string;
};

export type QueryRequestFilterOptions = {
    connections: Array<{
        id: number;
        name: string;
    }>;
    statuses: QueryRequestStatus[];
    request_kinds: QueryRequestKind[];
    query_types: QueryType[];
};

export function formatDate(value?: string | null, timezone?: string): string {
    if (!value) {
        return 'Not set';
    }

    return new Intl.DateTimeFormat(undefined, {
        timeZone: timezone,
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

export function formatRemaining(value?: string | null): string {
    if (!value) {
        return 'Not active';
    }

    const seconds = Math.max(
        0,
        Math.floor((new Date(value).getTime() - Date.now()) / 1000),
    );

    if (seconds <= 0) {
        return 'Expired';
    }

    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    if (hours > 0) {
        return `${hours}h ${minutes}m left`;
    }

    return `${Math.max(1, minutes)}m left`;
}

export function statusLabel(value: string): string {
    return value
        .split('_')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

export function driverLabel(value: DatabaseDriver | string): string {
    return value === 'pgsql' ? 'PostgreSQL' : 'MySQL';
}

export function statusTone(
    status: QueryRequestStatus | ExecutionStatus,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'failed' || status === 'rejected') {
        return 'destructive';
    }

    if (status === 'completed' || status === 'succeeded') {
        return 'default';
    }

    if (status === 'running' || status === 'approved') {
        return 'secondary';
    }

    return 'outline';
}
