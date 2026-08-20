import type { EditorView } from '@codemirror/view';
import { Form, Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Clock3,
    Database,
    Download,
    FileCode2,
    Play,
    Search,
    Sparkles,
    CircleStop,
    Table2,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { format } from 'sql-formatter';
import QuerySessionController from '@/actions/App/Http/Controllers/QuerySessionController';
import QuerySessionQueryController from '@/actions/App/Http/Controllers/QuerySessionQueryController';
import QuerySessionQueryExportController from '@/actions/App/Http/Controllers/QuerySessionQueryExportController';
import { ConnectionCombobox } from '@/components/crucible/connection-combobox';
import { SqlEditor } from '@/components/crucible/sql-editor';
import type { SchemaTable } from '@/components/crucible/sql-editor';
import { StatusBadge } from '@/components/crucible/status-badge';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { driverLabel, formatDate, statusLabel } from '@/lib/crucible';
import type {
    DatabaseDriver,
    ExecutionStatus,
    QueryType,
} from '@/lib/crucible';
import { show as queryRequestShow } from '@/routes/query-requests';
import { show as querySessionShow } from '@/routes/query-sessions';
import type { Auth } from '@/types';

type SessionQuery = {
    id: number;
    sql: string;
    query_type: QueryType;
    status: ExecutionStatus;
    duration_ms: number | null;
    row_count: number | null;
    result_truncated: boolean;
    sample_rows?: Array<Record<string, unknown>> | null;
    error_message: string | null;
    created_at: string | null;
    connection: {
        id: number;
        name: string;
        driver: DatabaseDriver;
    } | null;
};

type QuerySession = {
    id: number;
    started_at: string;
    expires_at: string;
    ended_at: string | null;
    is_active: boolean;
    request: {
        id: number;
        title: string;
        requester: string;
        access_mode: 'read' | 'write';
    };
    connection: {
        id: number;
        name: string;
        driver: DatabaseDriver;
    };
    connections: Array<{
        id: number;
        name: string;
        driver: DatabaseDriver;
    }>;
    latest_query: SessionQuery | null;
    queries: SessionQuery[];
};

type Props = {
    session: QuerySession;
    tables: SchemaTable[];
};

function remainingSeconds(expiresAt: string): number {
    return Math.max(
        0,
        Math.floor((new Date(expiresAt).getTime() - Date.now()) / 1000),
    );
}

function remainingLabel(seconds: number): string {
    const minutes = Math.floor(seconds / 60);
    const remainder = seconds % 60;

    return `${minutes}:${remainder.toString().padStart(2, '0')}`;
}

function ResultTable({ rows }: { rows: Array<Record<string, unknown>> }) {
    const columns = Array.from(
        rows.reduce((set, row) => {
            Object.keys(row).forEach((key) => set.add(key));

            return set;
        }, new Set<string>()),
    );

    if (rows.length === 0) {
        return (
            <div className="flex h-full min-h-40 items-center justify-center text-sm text-muted-foreground">
                Run a query to see results here.
            </div>
        );
    }

    return (
        <div className="h-full overflow-auto">
            <table className="w-full min-w-max text-sm">
                <thead>
                    <tr className="border-b bg-muted/40 text-left text-xs text-muted-foreground uppercase">
                        {columns.map((column, index) => (
                            <th
                                key={column}
                                className={`py-3 pr-4 font-medium ${
                                    index === 0 ? 'pl-4 sm:pl-6' : ''
                                }`}
                            >
                                {column}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, index) => (
                        <tr key={index} className="border-b last:border-0">
                            {columns.map((column, columnIndex) => (
                                <td
                                    key={column}
                                    className={`max-w-80 truncate py-3.5 pr-4 font-mono text-xs ${
                                        columnIndex === 0 ? 'pl-4 sm:pl-6' : ''
                                    }`}
                                >
                                    {String(row[column] ?? '')}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export default function QuerySessionShow({ session, tables }: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const userTimezone = auth.user.timezone ?? 'UTC';
    const [secondsRemaining, setSecondsRemaining] = useState(() =>
        remainingSeconds(session.expires_at),
    );
    const [schemaSearch, setSchemaSearch] = useState('');
    const [editorView, setEditorView] = useState<EditorView | null>(null);
    const latestRows = session.latest_query?.sample_rows ?? [];
    const defaultSql = useMemo(
        () => session.latest_query?.sql ?? '',
        [session.latest_query?.sql],
    );
    const { data, setData, post, processing, errors } = useForm({
        database_connection_id: String(session.connection.id),
        sql: defaultSql,
    });
    const isSessionActive = session.is_active && secondsRemaining > 0;
    const remaining = remainingLabel(secondsRemaining);
    const formatterLanguage =
        session.connection.driver === 'mysql' ? 'mysql' : 'postgresql';
    const sqlLineCount = Math.max(1, data.sql.split('\n').length);
    const sqlPanelHeight = Math.min(
        640,
        Math.max(300, 230 + sqlLineCount * 24),
    );
    const resultPanelHeight = Math.min(
        620,
        Math.max(330, 230 + Math.min(latestRows.length, 10) * 34),
    );
    const filteredTables = tables.filter((table) => {
        const query = schemaSearch.trim().toLowerCase();

        if (query === '') {
            return true;
        }

        return (
            table.name.toLowerCase().includes(query) ||
            table.columns.some((column) =>
                column.name.toLowerCase().includes(query),
            )
        );
    });

    function updateSql(value: string): void {
        setData('sql', value);
    }

    function insertSnippet(snippet: string): void {
        if (editorView) {
            const selection = editorView.state.selection.main;

            editorView.dispatch({
                changes: {
                    from: selection.from,
                    to: selection.to,
                    insert: snippet,
                },
                selection: {
                    anchor: selection.from + snippet.length,
                },
            });
            editorView.focus();

            return;
        }

        updateSql(`${data.sql.trimEnd()} ${snippet}`.trimStart());
    }

    function formatSql(): void {
        if (data.sql.trim() === '') {
            return;
        }

        updateSql(
            format(data.sql, {
                language: formatterLanguage,
                keywordCase: 'upper',
            }),
        );
    }

    function runQuery(): void {
        if (!isSessionActive) {
            return;
        }

        post(QuerySessionQueryController.store.url(session.id), {
            preserveScroll: true,
        });
    }

    function switchConnection(databaseConnectionId: string): void {
        if (databaseConnectionId === String(session.connection.id)) {
            return;
        }

        router.get(
            querySessionShow.url(session.id, {
                query: { connection_id: Number(databaseConnectionId) },
            }),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    }

    useEffect(() => {
        const initialTick = window.setTimeout(() => {
            setSecondsRemaining(remainingSeconds(session.expires_at));
        }, 0);

        const interval = window.setInterval(() => {
            setSecondsRemaining(remainingSeconds(session.expires_at));
        }, 1000);

        return () => {
            window.clearTimeout(initialTick);
            window.clearInterval(interval);
        };
    }, [session.expires_at]);

    useEffect(() => {
        setData('database_connection_id', String(session.connection.id));
    }, [session.connection.id, setData]);

    return (
        <>
            <Head title={`Session: ${session.request.title}`} />

            <div className="flex min-h-[calc(100vh-6rem)] flex-col">
                <div className="border-b px-4 py-4 sm:px-6 lg:px-8">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="grid gap-1">
                            <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                <Database className="size-4" />
                                <span>{session.connection.name}</span>
                                <span>/</span>
                                <span>
                                    {driverLabel(session.connection.driver)}
                                </span>
                            </div>
                            <h1 className="text-2xl font-semibold tracking-normal">
                                {session.request.title}
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                {session.request.access_mode === 'write'
                                    ? 'Read + write session. Data-changing SQL is permitted for the approved access window.'
                                    : 'Read-only session. Data-changing SQL is blocked for the approved access window.'}
                            </p>
                            <div className="mt-3 max-w-md">
                                <ConnectionCombobox
                                    connections={session.connections}
                                    name={null}
                                    label="Active connection"
                                    description="Schema and queries use this approved connection."
                                    value={String(session.connection.id)}
                                    onValueChange={switchConnection}
                                />
                            </div>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Button variant="outline" asChild>
                                <Link
                                    href={queryRequestShow(session.request.id)}
                                >
                                    <ArrowLeft />
                                    Back
                                </Link>
                            </Button>
                            <div className="inline-flex h-9 items-center gap-2 rounded-md border bg-background px-3 text-sm font-medium">
                                <Clock3 className="size-4 text-muted-foreground" />
                                {isSessionActive ? remaining : 'Expired'}
                            </div>
                            {isSessionActive && (
                                <Dialog>
                                    <DialogTrigger asChild>
                                        <Button variant="destructive">
                                            <CircleStop />
                                            End session
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>
                                                End this access session?
                                            </DialogTitle>
                                            <DialogDescription>
                                                This ends every active session for
                                                this request now. The request
                                                remains in audit history as
                                                cancelled.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <Form
                                            {...QuerySessionController.end.form(
                                                session.id,
                                            )}
                                            className="grid gap-2"
                                        >
                                            {({ processing, errors }) => (
                                                <>
                                                    <Label htmlFor="end-session-reason">
                                                        Reason for ending access
                                                    </Label>
                                                    <textarea
                                                        id="end-session-reason"
                                                        name="reason"
                                                        rows={3}
                                                        required
                                                        className="min-h-24 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                                        placeholder="Why should this access session end?"
                                                    />
                                                    <InputError
                                                        message={errors.reason}
                                                    />
                                                    <DialogFooter className="mt-2">
                                                        <DialogClose asChild>
                                                            <Button variant="outline">
                                                                Keep session
                                                            </Button>
                                                        </DialogClose>
                                                        <Button
                                                            variant="destructive"
                                                            disabled={processing}
                                                        >
                                                            <CircleStop />
                                                            {processing
                                                                ? 'Ending session...'
                                                                : 'End session'}
                                                        </Button>
                                                    </DialogFooter>
                                                </>
                                            )}
                                        </Form>
                                    </DialogContent>
                                </Dialog>
                            )}
                        </div>
                    </div>
                </div>

                <div className="grid flex-1 items-start gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_420px] lg:p-6 xl:grid-cols-[minmax(0,1fr)_460px]">
                    <div className="grid min-w-0 gap-4">
                        <Card
                            className="grid min-h-72 min-w-0 resize-y grid-rows-[auto_minmax(0,1fr)] overflow-hidden"
                            style={{ height: sqlPanelHeight }}
                        >
                            <CardHeader className="border-b px-4 pb-4 sm:px-6">
                                <div className="flex items-center gap-2">
                                    <FileCode2 className="size-4 text-muted-foreground" />
                                    <CardTitle>SQL</CardTitle>
                                </div>
                            </CardHeader>
                            <CardContent className="grid min-h-0 min-w-0 grid-rows-[minmax(0,1fr)_auto] overflow-hidden p-0">
                                <div className="min-h-0 min-w-0 overflow-hidden">
                                    <SqlEditor
                                        value={data.sql}
                                        onChange={updateSql}
                                        driver={session.connection.driver}
                                        tables={tables}
                                        readOnly={!isSessionActive}
                                        height="100%"
                                        minHeight="100%"
                                        onEditorReady={setEditorView}
                                    />
                                </div>
                                <div className="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3">
                                    <div>
                                        <InputError
                                            message={
                                                errors.database_connection_id
                                            }
                                        />
                                        <InputError message={errors.sql} />
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={formatSql}
                                            disabled={!isSessionActive}
                                        >
                                            <Sparkles />
                                            Format
                                        </Button>
                                        <Button
                                            type="button"
                                            disabled={
                                                processing || !isSessionActive
                                            }
                                            onClick={runQuery}
                                        >
                                            <Play />
                                            Run
                                        </Button>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <Card
                            className="grid min-h-80 min-w-0 resize-y grid-rows-[auto_minmax(0,1fr)] overflow-hidden"
                            style={{ height: resultPanelHeight }}
                        >
                            <CardHeader className="border-b px-4 pb-4 sm:px-6">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex items-center gap-2">
                                        <Table2 className="size-4 text-muted-foreground" />
                                        <CardTitle>Results</CardTitle>
                                    </div>
                                    {session.latest_query && (
                                        <div className="flex flex-wrap items-center gap-2">
                                            <StatusBadge
                                                value={
                                                    session.latest_query.status
                                                }
                                            />
                                            {session.latest_query
                                                .connection && (
                                                <span className="text-xs text-muted-foreground">
                                                    {
                                                        session.latest_query
                                                            .connection.name
                                                    }
                                                </span>
                                            )}
                                            <span className="text-sm text-muted-foreground">
                                                {session.latest_query
                                                    .row_count ?? 0}
                                                {session.latest_query
                                                    .result_truncated
                                                    ? '+'
                                                    : ''}{' '}
                                                rows /{' '}
                                                {session.latest_query
                                                    .duration_ms ?? 0}{' '}
                                                ms
                                            </span>
                                            {latestRows.length > 0 && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <a
                                                        href={QuerySessionQueryExportController.url(
                                                            session.latest_query
                                                                .id,
                                                        )}
                                                    >
                                                        <Download />
                                                        Export CSV
                                                    </a>
                                                </Button>
                                            )}
                                        </div>
                                    )}
                                </div>
                            </CardHeader>
                            <CardContent className="min-h-0 overflow-hidden p-0">
                                {session.latest_query?.error_message ? (
                                    <div className="h-full overflow-auto p-4">
                                        <div className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900/70 dark:bg-red-950/40 dark:text-red-300">
                                            {session.latest_query.error_message}
                                        </div>
                                    </div>
                                ) : (
                                    <ResultTable rows={latestRows} />
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <aside className="grid min-h-0 min-w-0 gap-4 lg:sticky lg:top-4 lg:h-[calc(100vh-12rem)] lg:grid-rows-[minmax(0,1.2fr)_minmax(0,0.8fr)]">
                        <Card className="grid h-[28rem] min-h-0 min-w-0 grid-rows-[auto_minmax(0,1fr)] overflow-hidden lg:h-full">
                            <CardHeader className="border-b px-4 pb-4">
                                <div className="flex items-center justify-between gap-3">
                                    <CardTitle>Schema</CardTitle>
                                    <span className="text-xs text-muted-foreground">
                                        {tables.length} tables
                                    </span>
                                </div>
                                <div className="relative mt-3">
                                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <input
                                        value={schemaSearch}
                                        onChange={(event) =>
                                            setSchemaSearch(event.target.value)
                                        }
                                        placeholder="Search tables or columns"
                                        className="h-9 w-full rounded-md border bg-background pr-3 pl-9 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                    />
                                </div>
                            </CardHeader>
                            <CardContent className="min-h-0 overflow-y-auto p-0">
                                {filteredTables.map((table) => (
                                    <details
                                        key={table.name}
                                        className="group border-b last:border-0"
                                    >
                                        <summary className="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm hover:bg-muted/40">
                                            <button
                                                type="button"
                                                className="flex min-w-0 items-center gap-2 text-left font-medium text-primary"
                                                onClick={(event) => {
                                                    event.preventDefault();
                                                    insertSnippet(table.name);
                                                }}
                                            >
                                                <Table2 className="size-4 shrink-0 text-muted-foreground" />
                                                <span className="break-all">
                                                    {table.name}
                                                </span>
                                            </button>
                                            <span className="text-xs text-muted-foreground">
                                                {table.columns.length}
                                            </span>
                                        </summary>
                                        <div className="grid border-t bg-muted/20 py-1">
                                            {table.columns.map((column) => (
                                                <button
                                                    key={`${table.name}.${column.name}`}
                                                    type="button"
                                                    className="grid grid-cols-[minmax(0,1fr)_auto] gap-3 px-8 py-2 text-left text-xs hover:bg-background"
                                                    onClick={() =>
                                                        insertSnippet(
                                                            column.name,
                                                        )
                                                    }
                                                >
                                                    <span className="truncate font-mono">
                                                        {column.name}
                                                    </span>
                                                    <span className="text-muted-foreground">
                                                        {column.type ??
                                                            'column'}
                                                    </span>
                                                </button>
                                            ))}
                                            {table.columns.length === 0 && (
                                                <div className="px-8 py-2 text-xs text-muted-foreground">
                                                    No columns discovered.
                                                </div>
                                            )}
                                        </div>
                                    </details>
                                ))}
                                {filteredTables.length === 0 && (
                                    <div className="p-4 text-sm text-muted-foreground">
                                        No matching schema items.
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <Card className="grid h-[22rem] min-h-0 min-w-0 grid-rows-[auto_minmax(0,1fr)] overflow-hidden lg:h-full">
                            <CardHeader className="border-b px-4 pb-4">
                                <CardTitle>Query History</CardTitle>
                            </CardHeader>
                            <CardContent className="min-h-0 overflow-y-auto p-0">
                                {session.queries.map((query) => (
                                    <div
                                        key={query.id}
                                        className="grid gap-2 border-b px-4 py-3 text-sm last:border-0"
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <StatusBadge value={query.status} />
                                            <StatusBadge
                                                value={query.query_type}
                                                label={statusLabel(
                                                    query.query_type,
                                                )}
                                            />
                                            {query.connection && (
                                                <span className="text-xs text-muted-foreground">
                                                    {query.connection.name}
                                                </span>
                                            )}
                                        </div>
                                        <code className="line-clamp-3 font-mono text-xs text-muted-foreground">
                                            {query.sql}
                                        </code>
                                        {query.error_message && (
                                            <div className="line-clamp-2 text-xs text-red-700 dark:text-red-300">
                                                {query.error_message}
                                            </div>
                                        )}
                                        <div className="text-xs text-muted-foreground">
                                            {query.row_count ?? 0}
                                            {query.result_truncated
                                                ? '+'
                                                : ''}{' '}
                                            rows / {query.duration_ms ?? 0} ms /{' '}
                                            {formatDate(
                                                query.created_at,
                                                userTimezone,
                                            )}
                                        </div>
                                    </div>
                                ))}
                                {session.queries.length === 0 && (
                                    <div className="p-4 text-sm text-muted-foreground">
                                        No session queries yet.
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </aside>
                </div>
            </div>
        </>
    );
}
