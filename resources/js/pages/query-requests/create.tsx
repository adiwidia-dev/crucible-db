import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    CalendarClock,
    Check,
    Clock3,
    FileCode2,
    KeyRound,
    Sparkles,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { format } from 'sql-formatter';
import QueryRequestController from '@/actions/App/Http/Controllers/QueryRequestController';
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
import { driverLabel } from '@/lib/crucible';
import type { DatabaseConnectionSummary } from '@/lib/crucible';
import { zonedDateTimeLocalToIso } from '@/lib/timezone';
import { index } from '@/routes/query-requests';
import type { Auth } from '@/types';

type Props = {
    connections: Array<
        Pick<DatabaseConnectionSummary, 'id' | 'name' | 'driver'>
    >;
};

export default function QueryRequestCreate({ connections }: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const userTimezone = auth.user.timezone ?? 'UTC';
    const [requestKind, setRequestKind] = useState<
        'single_execution' | 'query_access'
    >('single_execution');
    const [scheduleQuery, setScheduleQuery] = useState(false);
    const [scheduledAtLocal, setScheduledAtLocal] = useState('');
    const [selectedConnectionId, setSelectedConnectionId] = useState('');
    const [sqlValue, setSqlValue] = useState('');
    const selectedConnection = connections.find(
        (connection) => String(connection.id) === selectedConnectionId,
    );
    const formatterLanguage =
        selectedConnection?.driver === 'mysql' ? 'mysql' : 'postgresql';

    function formatSql(): void {
        if (sqlValue.trim() === '') {
            return;
        }

        setSqlValue(
            format(sqlValue, {
                language: formatterLanguage,
                keywordCase: 'upper',
            }),
        );
    }

    return (
        <>
            <Head title="New query request" />

            <div className="crucible-page">
                <PageHeader
                    icon={FileCode2}
                    eyebrow="Request"
                    title="New Query Request"
                    description="Submit SQL for the selected connection and execution window."
                />

                <Card>
                    <CardHeader className="border-b px-4 pb-4 sm:px-6">
                        <CardTitle>Execution Details</CardTitle>
                        <CardDescription>
                            Connection, SQL, and schedule information.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="pt-6">
                        <Form
                            {...QueryRequestController.store.form()}
                            options={{ preserveScroll: true }}
                            className="grid gap-5"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="request_kind">
                                            Request Type
                                        </Label>
                                        <div className="grid gap-3 md:grid-cols-2">
                                            <label
                                                className={`flex cursor-pointer gap-3 rounded-lg border bg-background p-4 shadow-xs ${
                                                    requestKind ===
                                                    'single_execution'
                                                        ? 'border-primary bg-primary/5'
                                                        : ''
                                                }`}
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
                                                        Single Execution
                                                    </span>
                                                    <span className="text-sm text-muted-foreground">
                                                        Submit one SQL statement
                                                        for approval and
                                                        one-time execution.
                                                    </span>
                                                </span>
                                            </label>
                                            <label
                                                className={`flex cursor-pointer gap-3 rounded-lg border bg-background p-4 shadow-xs ${
                                                    requestKind ===
                                                    'query_access'
                                                        ? 'border-primary bg-primary/5'
                                                        : ''
                                                }`}
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
                                                        submitting SQL upfront.
                                                    </span>
                                                </span>
                                            </label>
                                        </div>
                                        <InputError
                                            message={errors.request_kind}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="database_connection_id">
                                            Connection
                                        </Label>
                                        <select
                                            id="database_connection_id"
                                            name="database_connection_id"
                                            value={selectedConnectionId}
                                            onChange={(event) =>
                                                setSelectedConnectionId(
                                                    event.target.value,
                                                )
                                            }
                                            className="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                            required
                                        >
                                            <option value="">
                                                Select a connection
                                            </option>
                                            {connections.map((connection) => (
                                                <option
                                                    key={connection.id}
                                                    value={connection.id}
                                                >
                                                    {connection.name} (
                                                    {driverLabel(
                                                        connection.driver,
                                                    )}
                                                    )
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={
                                                errors.database_connection_id
                                            }
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="title">Title</Label>
                                        <Input id="title" name="title" />
                                        <InputError message={errors.title} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="description">
                                            Description
                                        </Label>
                                        <textarea
                                            id="description"
                                            name="description"
                                            rows={3}
                                            className="min-h-24 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                        />
                                        <InputError
                                            message={errors.description}
                                        />
                                    </div>

                                    {requestKind === 'single_execution' ? (
                                        <>
                                            <div className="grid gap-2">
                                                <div className="flex items-center justify-between gap-2">
                                                    <Label htmlFor="sql">
                                                        SQL
                                                    </Label>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={formatSql}
                                                    >
                                                        <Sparkles />
                                                        Format
                                                    </Button>
                                                </div>
                                                <div className="overflow-hidden rounded-lg border bg-background shadow-xs">
                                                    <div className="flex h-9 items-center gap-2 border-b bg-muted/40 px-3 text-xs text-muted-foreground">
                                                        <FileCode2 className="size-3.5" />
                                                        <span>query.sql</span>
                                                    </div>
                                                    <input
                                                        type="hidden"
                                                        name="sql"
                                                        value={sqlValue}
                                                    />
                                                    <SqlEditor
                                                        value={sqlValue}
                                                        onChange={setSqlValue}
                                                        driver={
                                                            selectedConnection?.driver
                                                        }
                                                        minHeight="20rem"
                                                    />
                                                </div>
                                                <InputError
                                                    message={errors.sql}
                                                />
                                            </div>

                                            <div className="grid gap-3 rounded-lg border bg-muted/30 p-4 md:w-96">
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
                                                    />
                                                    <CalendarClock className="size-4 text-muted-foreground" />
                                                    Schedule query?
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
                                                    message={
                                                        errors.scheduled_at
                                                    }
                                                />
                                            </div>
                                        </>
                                    ) : (
                                        <div className="grid gap-2 rounded-lg border bg-muted/30 p-4 md:w-96">
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
                                                defaultValue={60}
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
                                    )}

                                    <div className="flex items-center gap-3">
                                        <Button disabled={processing}>
                                            <Check />
                                            Submit
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            asChild
                                        >
                                            <Link href={index()}>
                                                <X />
                                                Cancel
                                            </Link>
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>
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
