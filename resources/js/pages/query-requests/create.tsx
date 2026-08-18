import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    CalendarClock,
    Check,
    Clock3,
    FileCode2,
    KeyRound,
    Plus,
    ShieldAlert,
    Sparkles,
    Trash2,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { format } from 'sql-formatter';
import QueryRequestController from '@/actions/App/Http/Controllers/QueryRequestController';
import {
    ConnectionCombobox,
    ConnectionMultiCombobox,
} from '@/components/crucible/connection-combobox';
import { PageHeader } from '@/components/crucible/page-header';
import { SqlEditor } from '@/components/crucible/sql-editor';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { DatabaseConnectionSummary } from '@/lib/crucible';
import {
    isoToZonedDateTimeLocal,
    zonedDateTimeLocalToIso,
} from '@/lib/timezone';
import { index } from '@/routes/query-requests';
import type { Auth } from '@/types';

type EditableQueryRequest = {
    id: number;
    database_connection_id: number;
    database_connection_ids: number[];
    request_kind: 'single_execution' | 'query_access';
    title: string;
    description: string | null;
    statements: Array<{
        sql: string;
        database_connection_id: number;
    }>;
    scheduled_at: string | null;
    access_duration_minutes: number | null;
    was_approved: boolean;
};

type Props = {
    connections: Array<
        Pick<DatabaseConnectionSummary, 'id' | 'name' | 'driver'>
    >;
    query_request: EditableQueryRequest | null;
};

type StatementDraft = {
    key: string;
    sql: string;
    databaseConnectionId: string;
};

function initialStatements(
    queryRequest: EditableQueryRequest | null,
    defaultConnectionId: string,
): StatementDraft[] {
    const statements = queryRequest?.statements ?? [];

    if (statements.length === 0) {
        return [
            {
                key: 'statement-1',
                sql: '',
                databaseConnectionId: defaultConnectionId,
            },
        ];
    }

    return statements.map((statement, index) => ({
        key: `statement-${index + 1}`,
        sql: statement.sql,
        databaseConnectionId: String(
            statement.database_connection_id ?? defaultConnectionId,
        ),
    }));
}

export default function QueryRequestCreate({
    connections,
    query_request,
}: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const userTimezone = auth.user.timezone ?? 'UTC';
    const isEditing = query_request !== null;
    const [requestKind, setRequestKind] = useState<
        'single_execution' | 'query_access'
    >(query_request?.request_kind ?? 'single_execution');
    const [scheduleQuery, setScheduleQuery] = useState(
        query_request?.scheduled_at !== null &&
            query_request?.scheduled_at !== undefined,
    );
    const [scheduledAtLocal, setScheduledAtLocal] = useState(() =>
        query_request?.scheduled_at
            ? isoToZonedDateTimeLocal(query_request.scheduled_at, userTimezone)
            : '',
    );
    const defaultConnectionId = query_request
        ? String(query_request.database_connection_id)
        : connections.length === 1
          ? String(connections[0].id)
          : '';
    const [selectedConnectionIds, setSelectedConnectionIds] = useState(
        () =>
            query_request?.database_connection_ids.map(String) ??
            (defaultConnectionId === '' ? [] : [defaultConnectionId]),
    );
    const [statements, setStatements] = useState<StatementDraft[]>(() =>
        initialStatements(query_request, defaultConnectionId),
    );
    const form = query_request
        ? QueryRequestController.update.form(query_request.id)
        : QueryRequestController.store.form();

    function updateStatement(index: number, sql: string): void {
        setStatements((current) =>
            current.map((statement, statementIndex) =>
                statementIndex === index ? { ...statement, sql } : statement,
            ),
        );
    }

    function updateStatementConnection(
        index: number,
        databaseConnectionId: string,
    ): void {
        setStatements((current) =>
            current.map((statement, statementIndex) =>
                statementIndex === index
                    ? { ...statement, databaseConnectionId }
                    : statement,
            ),
        );
    }

    function addStatement(): void {
        setStatements((current) => [
            ...current,
            {
                key: `statement-${Date.now()}-${current.length}`,
                sql: '',
                databaseConnectionId:
                    current.at(-1)?.databaseConnectionId ?? defaultConnectionId,
            },
        ]);
    }

    function removeStatement(index: number): void {
        setStatements((current) =>
            current.filter((_, statementIndex) => statementIndex !== index),
        );
    }

    function moveStatement(index: number, direction: -1 | 1): void {
        setStatements((current) => {
            const target = index + direction;

            if (target < 0 || target >= current.length) {
                return current;
            }

            const reordered = [...current];
            [reordered[index], reordered[target]] = [
                reordered[target],
                reordered[index],
            ];

            return reordered;
        });
    }

    function formatStatement(index: number): void {
        const sql = statements[index]?.sql.trim();
        const statementConnection = connections.find(
            (connection) =>
                String(connection.id) ===
                statements[index]?.databaseConnectionId,
        );

        if (!sql) {
            return;
        }

        updateStatement(
            index,
            format(sql, {
                language:
                    statementConnection?.driver === 'mysql'
                        ? 'mysql'
                        : 'postgresql',
                keywordCase: 'upper',
            }),
        );
    }

    return (
        <>
            <Head
                title={isEditing ? 'Edit query request' : 'New query request'}
            />

            <div className="crucible-page">
                <PageHeader
                    icon={FileCode2}
                    title={
                        isEditing ? 'Edit Query Request' : 'New Query Request'
                    }
                    description={
                        isEditing
                            ? 'Revise the deployment batch and send the complete request back for approval.'
                            : 'Prepare a governed deployment batch or request time-boxed query access.'
                    }
                />

                {query_request?.was_approved && (
                    <div className="flex gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-900/70 dark:bg-amber-950/40 dark:text-amber-200">
                        <ShieldAlert className="mt-0.5 size-4 shrink-0" />
                        <div>
                            <p className="font-medium">
                                Saving will invalidate the current approval.
                            </p>
                            <p className="mt-1 text-amber-800 dark:text-amber-300">
                                The updated SQL cannot be executed until a
                                reviewer approves this request again.
                            </p>
                        </div>
                    </div>
                )}

                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    className="grid gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <section className="border-y bg-card sm:rounded-lg sm:border">
                                <div className="border-b px-4 py-3 sm:px-5">
                                    <h2 className="text-sm font-semibold">
                                        Request details
                                    </h2>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Start with the operational context, then
                                        choose how the work should run.
                                    </p>
                                </div>
                                <div className="grid gap-5 p-4 sm:p-5">
                                    <div className="grid gap-5">
                                        <div className="grid gap-2">
                                            <Label htmlFor="title">
                                                Ticket / request title
                                            </Label>
                                            <Input
                                                id="title"
                                                name="title"
                                                defaultValue={
                                                    query_request?.title ?? ''
                                                }
                                                placeholder="DEP-1234: customer data migration"
                                                autoFocus={!isEditing}
                                            />
                                            <InputError
                                                message={errors.title}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="description">
                                                {requestKind ===
                                                'single_execution'
                                                    ? 'Deployment notes (optional)'
                                                    : 'Access purpose (optional)'}
                                            </Label>
                                            <textarea
                                                id="description"
                                                name="description"
                                                rows={4}
                                                defaultValue={
                                                    query_request?.description ??
                                                    ''
                                                }
                                                className="min-h-24 rounded-md border border-input bg-background px-3 py-2 text-sm transition-[color,border-color,box-shadow] duration-150 ease-out outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/30 motion-reduce:transition-none"
                                                placeholder={
                                                    requestKind ===
                                                    'single_execution'
                                                        ? 'Purpose, expected impact, and rollback context'
                                                        : 'Why access is needed and what you plan to investigate'
                                                }
                                            />
                                            <InputError
                                                message={errors.description}
                                            />
                                        </div>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="request_kind">
                                            Workflow
                                        </Label>
                                        <div className="grid gap-3 md:grid-cols-2">
                                            <label
                                                className={`flex cursor-pointer gap-3 rounded-md border bg-background p-3.5 transition-colors duration-150 ease-out hover:bg-accent/35 motion-reduce:transition-none ${requestKind === 'single_execution' ? 'border-primary bg-primary/5' : ''}`}
                                            >
                                                <input
                                                    type="radio"
                                                    name="request_kind"
                                                    value="single_execution"
                                                    checked={
                                                        requestKind ===
                                                        'single_execution'
                                                    }
                                                    onChange={() =>
                                                        setRequestKind(
                                                            'single_execution',
                                                        )
                                                    }
                                                    className="mt-0.5 accent-primary"
                                                />
                                                <span className="grid gap-1">
                                                    <span className="flex items-center gap-2 font-medium">
                                                        <FileCode2 className="size-4 text-primary" />
                                                        Deployment Batch
                                                    </span>
                                                    <span className="text-sm text-muted-foreground">
                                                        Run one or more SQL
                                                        statements in order as
                                                        one approved request.
                                                    </span>
                                                </span>
                                            </label>
                                            <label
                                                className={`flex cursor-pointer gap-3 rounded-md border bg-background p-3.5 transition-colors duration-150 ease-out hover:bg-accent/35 motion-reduce:transition-none ${requestKind === 'query_access' ? 'border-primary bg-primary/5' : ''}`}
                                            >
                                                <input
                                                    type="radio"
                                                    name="request_kind"
                                                    value="query_access"
                                                    checked={
                                                        requestKind ===
                                                        'query_access'
                                                    }
                                                    onChange={() =>
                                                        setRequestKind(
                                                            'query_access',
                                                        )
                                                    }
                                                    className="mt-0.5 accent-primary"
                                                />
                                                <span className="grid gap-1">
                                                    <span className="flex items-center gap-2 font-medium">
                                                        <KeyRound className="size-4 text-primary" />
                                                        Query Access
                                                    </span>
                                                    <span className="text-sm text-muted-foreground">
                                                        Request a time-boxed
                                                        browser session without
                                                        SQL upfront.
                                                    </span>
                                                </span>
                                            </label>
                                        </div>
                                        <InputError
                                            message={errors.request_kind}
                                        />
                                    </div>

                                    {requestKind === 'query_access' && (
                                        <div className="grid gap-3 rounded-md border bg-muted/20 p-3.5">
                                            <ConnectionMultiCombobox
                                                connections={connections}
                                                values={selectedConnectionIds}
                                                onValueChange={
                                                    setSelectedConnectionIds
                                                }
                                                error={
                                                    errors.database_connection_ids
                                                }
                                                label="Session connections"
                                                description="Choose every database that this time-boxed session may access. Approval is evaluated across all selected targets."
                                            />
                                        </div>
                                    )}
                                </div>
                            </section>

                            {requestKind === 'single_execution' ? (
                                <section className="overflow-hidden border-y bg-card sm:rounded-lg sm:border">
                                    <div className="border-b px-4 py-3 sm:px-5">
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                            <div>
                                                <h2 className="text-sm font-semibold">
                                                    Ordered statements
                                                </h2>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    Statements run top to
                                                    bottom. Execution stops at
                                                    the first failure.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="grid gap-4 p-4 sm:p-5">
                                        <InputError
                                            message={errors.statements}
                                        />
                                        {statements.map((statement, index) => (
                                            <section
                                                key={statement.key}
                                                className="overflow-hidden border bg-background sm:rounded-md"
                                            >
                                                <div className="flex min-h-11 flex-wrap items-center justify-between gap-2 border-b bg-muted/20 px-3 py-2">
                                                    <div className="flex items-center gap-2 text-sm font-medium">
                                                        <span className="flex size-6 items-center justify-center rounded-full border bg-background font-mono text-xs">
                                                            {index + 1}
                                                        </span>
                                                        <span>
                                                            Statement{' '}
                                                            {index + 1}
                                                        </span>
                                                    </div>
                                                    <div className="flex items-center gap-1">
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label="Move statement up"
                                                            disabled={
                                                                index === 0
                                                            }
                                                            onClick={() =>
                                                                moveStatement(
                                                                    index,
                                                                    -1,
                                                                )
                                                            }
                                                        >
                                                            <ArrowUp />
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label="Move statement down"
                                                            disabled={
                                                                index ===
                                                                statements.length -
                                                                    1
                                                            }
                                                            onClick={() =>
                                                                moveStatement(
                                                                    index,
                                                                    1,
                                                                )
                                                            }
                                                        >
                                                            <ArrowDown />
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                formatStatement(
                                                                    index,
                                                                )
                                                            }
                                                        >
                                                            <Sparkles />
                                                            Format
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label="Remove statement"
                                                            disabled={
                                                                statements.length ===
                                                                1
                                                            }
                                                            onClick={() =>
                                                                removeStatement(
                                                                    index,
                                                                )
                                                            }
                                                        >
                                                            <Trash2 />
                                                        </Button>
                                                    </div>
                                                </div>
                                                <input
                                                    type="hidden"
                                                    name={`statements[${index}][sql]`}
                                                    value={statement.sql}
                                                />
                                                <div className="border-b px-3 py-3">
                                                    <ConnectionCombobox
                                                        connections={
                                                            connections
                                                        }
                                                        name={`statements[${index}][database_connection_id]`}
                                                        label="Target connection"
                                                        description="This statement runs on the selected target."
                                                        value={
                                                            statement.databaseConnectionId
                                                        }
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            updateStatementConnection(
                                                                index,
                                                                value,
                                                            )
                                                        }
                                                        error={
                                                            errors[
                                                                `statements.${index}.database_connection_id`
                                                            ]
                                                        }
                                                    />
                                                </div>
                                                <SqlEditor
                                                    value={statement.sql}
                                                    onChange={(sql) =>
                                                        updateStatement(
                                                            index,
                                                            sql,
                                                        )
                                                    }
                                                    driver={
                                                        connections.find(
                                                            (connection) =>
                                                                String(
                                                                    connection.id,
                                                                ) ===
                                                                statement.databaseConnectionId,
                                                        )?.driver
                                                    }
                                                    minHeight="13rem"
                                                    placeholder={`-- Statement ${index + 1}\nSELECT * FROM table_name`}
                                                />
                                                <div className="border-t px-3 py-2">
                                                    <InputError
                                                        message={
                                                            errors[
                                                                `statements.${index}.sql`
                                                            ]
                                                        }
                                                    />
                                                </div>
                                            </section>
                                        ))}
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="self-start border-dashed"
                                            onClick={addStatement}
                                            disabled={statements.length >= 50}
                                        >
                                            <Plus />
                                            Add statement
                                        </Button>
                                    </div>
                                </section>
                            ) : (
                                <section className="border-y bg-card sm:rounded-lg sm:border">
                                    <div className="border-b px-4 py-3 sm:px-5">
                                        <h2 className="text-sm font-semibold">
                                            Access window
                                        </h2>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Set how long the approved browser
                                            session remains available.
                                        </p>
                                    </div>
                                    <div className="p-4 sm:p-5">
                                        <div className="grid gap-2 md:w-96">
                                            <div className="flex items-center gap-2">
                                                <Clock3 className="size-4 text-muted-foreground" />
                                                <Label htmlFor="access_duration_minutes">
                                                    Access Duration
                                                </Label>
                                            </div>
                                            <Input
                                                id="access_duration_minutes"
                                                name="access_duration_minutes"
                                                type="number"
                                                min={5}
                                                max={1440}
                                                defaultValue={
                                                    query_request?.access_duration_minutes ??
                                                    60
                                                }
                                                className="bg-background"
                                                required
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Minutes. Approved sessions can
                                                run queries until this timer
                                                expires.
                                            </p>
                                            <InputError
                                                message={
                                                    errors.access_duration_minutes
                                                }
                                            />
                                        </div>
                                    </div>
                                </section>
                            )}

                            {requestKind === 'single_execution' && (
                                <section className="overflow-hidden border-y bg-card sm:rounded-lg sm:border">
                                    <div className="border-b px-4 py-3 sm:px-5">
                                        <h2 className="text-sm font-semibold">
                                            Execution plan
                                        </h2>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Choose whether the approved batch
                                            runs immediately or at a planned
                                            time.
                                        </p>
                                    </div>
                                    <div className="grid gap-5 px-4 py-5 sm:px-5 lg:grid-cols-[minmax(11rem,0.55fr)_minmax(0,2fr)] lg:gap-8">
                                        <div>
                                            <h3 className="text-sm font-semibold">
                                                Schedule execution
                                            </h3>
                                            <p className="mt-2 max-w-xs text-sm text-muted-foreground">
                                                Unscheduled batches are ready to
                                                execute after approval.
                                            </p>
                                        </div>
                                        <div className="grid gap-3">
                                            <label className="flex items-center gap-2 text-sm font-medium">
                                                <input
                                                    type="checkbox"
                                                    name="schedule_query"
                                                    value="1"
                                                    checked={scheduleQuery}
                                                    onChange={(event) =>
                                                        setScheduleQuery(
                                                            event.target
                                                                .checked,
                                                        )
                                                    }
                                                    className="accent-primary"
                                                />
                                                <CalendarClock className="size-4 text-muted-foreground" />
                                                Schedule after approval
                                            </label>
                                            {scheduleQuery && (
                                                <div className="grid gap-2">
                                                    <Label htmlFor="scheduled_at">
                                                        Scheduled time
                                                    </Label>
                                                    <Input
                                                        id="scheduled_at"
                                                        type="datetime-local"
                                                        value={scheduledAtLocal}
                                                        onChange={(event) =>
                                                            setScheduledAtLocal(
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        required
                                                    />
                                                    <input
                                                        type="hidden"
                                                        name="scheduled_at"
                                                        value={
                                                            scheduledAtLocal
                                                                ? zonedDateTimeLocalToIso(
                                                                      scheduledAtLocal,
                                                                      userTimezone,
                                                                  )
                                                                : ''
                                                        }
                                                    />
                                                    <p className="text-xs text-muted-foreground">
                                                        Interpreted in{' '}
                                                        <span className="font-mono">
                                                            {userTimezone}
                                                        </span>
                                                        .
                                                    </p>
                                                </div>
                                            )}
                                            <InputError
                                                message={errors.scheduled_at}
                                            />
                                        </div>
                                    </div>
                                </section>
                            )}

                            <section className="overflow-hidden border-y bg-card sm:rounded-lg sm:border">
                                <div className="border-b px-4 py-3 sm:px-5">
                                    <h2 className="text-sm font-semibold">
                                        Review and submit
                                    </h2>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Review is determined from the access
                                        policy for every selected target.
                                    </p>
                                </div>
                                <div className="flex flex-col gap-4 px-4 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                                    <div className="text-sm text-muted-foreground">
                                        {requestKind === 'single_execution' ? (
                                            <p>
                                                {statements.length}{' '}
                                                {statements.length === 1
                                                    ? 'ordered statement'
                                                    : 'ordered statements'}{' '}
                                                will run in sequence.
                                                {scheduleQuery
                                                    ? ' The batch is scheduled after approval.'
                                                    : ' The batch is ready after approval.'}
                                            </p>
                                        ) : (
                                            <p>
                                                {selectedConnectionIds.length}{' '}
                                                {selectedConnectionIds.length ===
                                                1
                                                    ? 'connection is'
                                                    : 'connections are'}{' '}
                                                included in this time-boxed
                                                session.
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:items-center">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            asChild
                                        >
                                            <Link
                                                href={
                                                    query_request
                                                        ? QueryRequestController.show(
                                                              query_request.id,
                                                          )
                                                        : index()
                                                }
                                            >
                                                <X />
                                                Cancel
                                            </Link>
                                        </Button>
                                        <Button disabled={processing}>
                                            <Check />
                                            {processing
                                                ? 'Saving...'
                                                : isEditing &&
                                                    query_request?.was_approved
                                                  ? 'Save & request review'
                                                  : isEditing
                                                    ? 'Save changes'
                                                    : 'Submit request'}
                                        </Button>
                                    </div>
                                </div>
                            </section>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

QueryRequestCreate.layout = {
    breadcrumbs: [
        {
            title: 'Query Requests',
            href: index(),
        },
    ],
};
