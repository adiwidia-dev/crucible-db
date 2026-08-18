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
import { ConnectionCombobox } from '@/components/crucible/connection-combobox';
import { PageHeader } from '@/components/crucible/page-header';
import { SqlEditor } from '@/components/crucible/sql-editor';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
    request_kind: 'single_execution' | 'query_access';
    title: string;
    description: string | null;
    statements: Array<{ sql: string }>;
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
};

function initialStatements(
    queryRequest: EditableQueryRequest | null,
): StatementDraft[] {
    const statements = queryRequest?.statements ?? [];

    if (statements.length === 0) {
        return [{ key: 'statement-1', sql: '' }];
    }

    return statements.map((statement, index) => ({
        key: `statement-${index + 1}`,
        sql: statement.sql,
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
            ? isoToZonedDateTimeLocal(
                  query_request.scheduled_at,
                  userTimezone,
              )
            : '',
    );
    const [selectedConnectionId, setSelectedConnectionId] = useState(
        query_request ? String(query_request.database_connection_id) : '',
    );
    const [statements, setStatements] = useState<StatementDraft[]>(() =>
        initialStatements(query_request),
    );
    const selectedConnection = connections.find(
        (connection) => String(connection.id) === selectedConnectionId,
    );
    const formatterLanguage =
        selectedConnection?.driver === 'mysql' ? 'mysql' : 'postgresql';
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

    function addStatement(): void {
        setStatements((current) => [
            ...current,
            {
                key: `statement-${Date.now()}-${current.length}`,
                sql: '',
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

        if (!sql) {
            return;
        }

        updateStatement(
            index,
            format(sql, {
                language: formatterLanguage,
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
                    eyebrow="Deployment Request"
                    title={isEditing ? 'Edit Query Request' : 'New Query Request'}
                    description={
                        isEditing
                            ? 'Revise the deployment batch and send the complete request back for approval.'
                            : 'Group every ordered SQL statement for one deployment ticket into a single request.'
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
                            <Card>
                                <CardHeader className="border-b px-4 pb-4 sm:px-6">
                                    <CardTitle>Request Details</CardTitle>
                                    <CardDescription>
                                        Identify the ticket, target, and access
                                        pattern.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid gap-5 pt-6">
                                    <div className="grid gap-2">
                                        <Label htmlFor="request_kind">
                                            Request Type
                                        </Label>
                                        <div className="grid gap-3 md:grid-cols-2">
                                            <label
                                                className={`flex cursor-pointer gap-3 rounded-lg border bg-background p-4 shadow-xs ${requestKind === 'single_execution' ? 'border-primary bg-primary/5' : ''}`}
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
                                                    className="mt-1"
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
                                                className={`flex cursor-pointer gap-3 rounded-lg border bg-background p-4 shadow-xs ${requestKind === 'query_access' ? 'border-primary bg-primary/5' : ''}`}
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
                                                    className="mt-1"
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

                                    <ConnectionCombobox
                                        connections={connections}
                                        value={selectedConnectionId}
                                        onValueChange={setSelectedConnectionId}
                                        error={errors.database_connection_id}
                                    />

                                    <div className="grid gap-4 md:grid-cols-2">
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
                                                placeholder="DEP-1234 — customer data migration"
                                            />
                                            <InputError
                                                message={errors.title}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="description">
                                                Deployment notes
                                            </Label>
                                            <textarea
                                                id="description"
                                                name="description"
                                                rows={3}
                                                defaultValue={
                                                    query_request?.description ??
                                                    ''
                                                }
                                                className="min-h-20 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                                placeholder="Purpose, expected impact, and rollback context"
                                            />
                                            <InputError
                                                message={errors.description}
                                            />
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>

                            {requestKind === 'single_execution' ? (
                                <Card>
                                    <CardHeader className="border-b px-4 pb-4 sm:px-6">
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                            <div>
                                                <CardTitle>
                                                    Ordered Statements
                                                </CardTitle>
                                                <CardDescription className="mt-1">
                                                    Statements run top to
                                                    bottom. Execution stops at
                                                    the first failure.
                                                </CardDescription>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={addStatement}
                                                disabled={
                                                    statements.length >= 50
                                                }
                                            >
                                                <Plus />
                                                Add statement
                                            </Button>
                                        </div>
                                    </CardHeader>
                                    <CardContent className="grid gap-4 pt-6">
                                        <InputError
                                            message={errors.statements}
                                        />
                                        {statements.map((statement, index) => (
                                            <section
                                                key={statement.key}
                                                className="overflow-hidden rounded-lg border bg-background shadow-xs"
                                            >
                                                <div className="flex min-h-11 flex-wrap items-center justify-between gap-2 border-b bg-muted/40 px-3 py-2">
                                                    <div className="flex items-center gap-2 text-sm font-medium">
                                                        <span className="flex size-6 items-center justify-center rounded-full border bg-background font-mono text-xs">
                                                            {index + 1}
                                                        </span>
                                                        <span>
                                                            Statement {index + 1}
                                                        </span>
                                                    </div>
                                                    <div className="flex items-center gap-1">
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label="Move statement up"
                                                            disabled={index === 0}
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
                                                <SqlEditor
                                                    value={statement.sql}
                                                    onChange={(sql) =>
                                                        updateStatement(
                                                            index,
                                                            sql,
                                                        )
                                                    }
                                                    driver={
                                                        selectedConnection?.driver
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

                                        <div className="grid gap-3 rounded-lg border bg-muted/30 p-4 md:w-96">
                                            <label className="flex items-center gap-2 text-sm font-medium">
                                                <input
                                                    type="checkbox"
                                                    name="schedule_query"
                                                    value="1"
                                                    checked={scheduleQuery}
                                                    onChange={(event) =>
                                                        setScheduleQuery(
                                                            event.target.checked,
                                                        )
                                                    }
                                                />
                                                <CalendarClock className="size-4 text-muted-foreground" />
                                                Schedule after approval
                                            </label>
                                            {scheduleQuery && (
                                                <div className="grid gap-2">
                                                    <Label htmlFor="scheduled_at">
                                                        Scheduled At
                                                    </Label>
                                                    <Input
                                                        id="scheduled_at"
                                                        type="datetime-local"
                                                        value={
                                                            scheduledAtLocal
                                                        }
                                                        onChange={(event) =>
                                                            setScheduledAtLocal(
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        className="bg-background"
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
                                    </CardContent>
                                </Card>
                            ) : (
                                <Card>
                                    <CardHeader className="border-b px-4 pb-4 sm:px-6">
                                        <CardTitle>Access Window</CardTitle>
                                    </CardHeader>
                                    <CardContent className="pt-6">
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
                                    </CardContent>
                                </Card>
                            )}

                            <div className="flex items-center gap-3">
                                <Button disabled={processing}>
                                    <Check />
                                    {processing
                                        ? 'Saving...'
                                        : isEditing
                                          ? 'Save & request approval'
                                          : 'Submit for approval'}
                                </Button>
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
                            </div>
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
