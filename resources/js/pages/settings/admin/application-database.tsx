import { Form, Head, usePoll } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    ArrowRightLeft,
    Ban,
    CheckCircle2,
    ChevronDown,
    CircleDashed,
    Copy,
    Database,
    HardDrive,
    History as HistoryIcon,
    RefreshCw,
    RotateCcw,
    Server,
    ShieldCheck,
} from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';
import { useEffect, useState } from 'react';
import ApplicationDatabaseMigrationController from '@/actions/App/Http/Controllers/Settings/ApplicationDatabaseMigrationController';
import { PageHeader } from '@/components/crucible/page-header';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit } from '@/routes/application-database-migrations';

type Driver = {
    value: 'sqlite' | 'mysql' | 'pgsql';
    label: string;
    default_port: number | null;
};

type DatabaseSummary = {
    driver: Driver['value'];
    driver_label: string;
    database: string | null;
    host: string | null;
    port: number | null;
};

type MigrationEndpoint = {
    driver: Driver['value'];
    database: string | null;
};

type MigrationEvent = {
    at: string;
    event: string;
    message?: string;
    actor?: { id: number; name: string };
};

type MigrationState = {
    id: string;
    status:
        | 'planned'
        | 'copying'
        | 'copied'
        | 'verified'
        | 'activation_pending_restart'
        | 'active'
        | 'rollback_pending_restart'
        | 'rolled_back'
        | 'failed'
        | 'cancelled';
    source: MigrationEndpoint;
    destination: MigrationEndpoint;
    planned_tables: Record<string, { rows: number }>;
    tables: Record<string, { rows: number }>;
    rollback_tables?: Record<string, { rows: number }>;
    events: MigrationEvent[];
    activity: {
        query_sessions: number;
        native_leases: number;
        native_connections: number;
    };
    queue_sizes: Record<string, number>;
    maintenance_fence: { plan_id: string; engaged_at: string } | null;
    restart_ready: boolean | null;
    created_at: string;
    updated_at: string;
};

type MigrationHistoryItem = {
    id: string;
    status: MigrationState['status'];
    source: MigrationEndpoint;
    destination: MigrationEndpoint;
    tables_copied: number;
    tables_planned: number;
    created_at: string;
    updated_at: string;
    is_current: boolean;
    can_rollback: boolean;
};

type Props = {
    configuration_mode: 'managed' | 'environment';
    active_database: DatabaseSummary;
    migration: MigrationState | null;
    migration_history: MigrationHistoryItem[];
    migration_error: string | null;
    drivers: Driver[];
    sqlite_path: string;
    confirmations: {
        activate: string;
        finalize: string;
        rollback: string;
    };
};

const statusPresentation: Record<
    MigrationState['status'],
    { label: string; className: string }
> = {
    planned: {
        label: 'Planned',
        className: 'border-blue-200 bg-blue-50 text-blue-800',
    },
    copying: {
        label: 'Copying',
        className: 'border-blue-200 bg-blue-50 text-blue-800',
    },
    copied: {
        label: 'Copied',
        className: 'border-amber-200 bg-amber-50 text-amber-800',
    },
    verified: {
        label: 'Verified',
        className: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    },
    activation_pending_restart: {
        label: 'Restart required',
        className: 'border-amber-200 bg-amber-50 text-amber-800',
    },
    active: {
        label: 'Active',
        className: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    },
    rollback_pending_restart: {
        label: 'Rollback restart required',
        className: 'border-amber-200 bg-amber-50 text-amber-800',
    },
    rolled_back: {
        label: 'Rolled back',
        className: 'border-slate-200 bg-slate-50 text-slate-700',
    },
    failed: {
        label: 'Copy failed',
        className: 'border-red-200 bg-red-50 text-red-800',
    },
    cancelled: {
        label: 'Cancelled',
        className: 'border-slate-200 bg-slate-50 text-slate-700',
    },
};

export default function ApplicationDatabase({
    configuration_mode: configurationMode,
    active_database: activeDatabase,
    migration,
    migration_history: migrationHistory,
    migration_error: migrationError,
    drivers,
    sqlite_path: sqlitePath,
    confirmations,
}: Props) {
    const { start, stop } = usePoll(
        3000,
        {
            only: [
                'active_database',
                'migration',
                'migration_history',
                'migration_error',
            ],
            preserveErrors: true,
        },
        { autoStart: false, mode: 'rest' },
    );
    const shouldPoll = [
        'copying',
        'activation_pending_restart',
        'rollback_pending_restart',
    ].includes(migration?.status ?? '');

    useEffect(() => {
        if (shouldPoll) {
            start();
        } else {
            stop();
        }

        return stop;
    }, [shouldPoll, start, stop]);

    const canPlan = configurationMode === 'managed' && !migration;

    return (
        <>
            <Head title="Application database" />

            <div className="crucible-page">
                <PageHeader
                    title="Application database"
                    description="Move Crucible control-plane data between SQLite, PostgreSQL, and MySQL with verified cutover and rollback."
                />

                <Card className="max-w-4xl gap-0 overflow-hidden py-0">
                    <CardHeader className="border-b px-4 py-3 sm:px-5">
                        <CardTitle className="flex items-center gap-2">
                            <Database className="size-4 text-blue-600" />
                            Active control database
                        </CardTitle>
                        <CardDescription>
                            Stores users, policy, requests, audit history, and
                            native-client control state.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4 px-4 py-5 sm:grid-cols-3 sm:px-5">
                        <Detail
                            label="Driver"
                            value={activeDatabase.driver_label}
                        />
                        <Detail
                            label="Database"
                            value={activeDatabase.database ?? 'Not configured'}
                            mono
                        />
                        <Detail
                            label="Endpoint"
                            value={
                                activeDatabase.host
                                    ? `${activeDatabase.host}:${activeDatabase.port}`
                                    : 'Local filesystem'
                            }
                            mono
                        />
                    </CardContent>
                </Card>

                {configurationMode !== 'managed' && (
                    <div className="flex max-w-4xl gap-3 rounded-lg border border-amber-200 bg-amber-50/70 p-4 text-amber-950">
                        <AlertTriangle className="mt-0.5 size-5 shrink-0 text-amber-600" />
                        <div>
                            <p className="font-medium">
                                Managed migration is unavailable
                            </p>
                            <p className="mt-1 text-sm leading-6 text-amber-900/75">
                                This installation uses environment-owned
                                database configuration. Change it through the
                                deployment environment and use the CLI migration
                                workflow.
                            </p>
                        </div>
                    </div>
                )}

                {migrationError && (
                    <div className="flex max-w-4xl gap-3 rounded-lg border border-red-200 bg-red-50/70 p-4 text-red-950">
                        <AlertTriangle className="mt-0.5 size-5 shrink-0 text-red-600" />
                        <div>
                            <p className="font-medium">
                                Migration state could not be read
                            </p>
                            <p className="mt-1 text-sm leading-6 text-red-900/75">
                                {migrationError}
                            </p>
                        </div>
                    </div>
                )}

                {migration && !migrationError && (
                    <MigrationPanel
                        migration={migration}
                        confirmations={confirmations}
                    />
                )}

                {canPlan && (
                    <PlanMigrationCard
                        drivers={drivers}
                        sqlitePath={sqlitePath}
                    />
                )}

                {migrationHistory.length > 0 && !migrationError && (
                    <MigrationHistory
                        migrations={migrationHistory}
                        rollbackPhrase={confirmations.rollback}
                    />
                )}
            </div>
        </>
    );
}

function MigrationHistory({
    migrations,
    rollbackPhrase,
}: {
    migrations: MigrationHistoryItem[];
    rollbackPhrase: string;
}) {
    return (
        <Card className="max-w-4xl gap-0 overflow-hidden py-0">
            <details className="group">
                <summary className="flex cursor-pointer list-none items-center justify-between gap-4 px-4 py-3 hover:bg-muted/30 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset sm:px-5">
                    <div className="min-w-0">
                        <CardTitle className="flex items-center gap-2">
                            <HistoryIcon className="size-4 text-muted-foreground" />
                            Migration history
                            <span className="font-normal text-muted-foreground">
                                ({migrations.length})
                            </span>
                        </CardTitle>
                        <CardDescription className="mt-1">
                            Completed and cancelled application database moves.
                        </CardDescription>
                    </div>
                    <ChevronDown className="size-4 shrink-0 text-muted-foreground transition-transform duration-200 group-open:rotate-180 motion-reduce:transition-none" />
                </summary>

                <div className="divide-y border-t">
                    {migrations.map((migration) => {
                        const presentation =
                            statusPresentation[migration.status];

                        return (
                            <div
                                key={migration.id}
                                className="grid gap-4 px-4 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-5"
                            >
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge
                                            variant="outline"
                                            className={presentation.className}
                                        >
                                            {presentation.label}
                                        </Badge>
                                        {migration.is_current && (
                                            <Badge variant="outline">
                                                Current rollback point
                                            </Badge>
                                        )}
                                        <time className="text-xs text-muted-foreground">
                                            {formatDateTime(
                                                migration.updated_at,
                                            )}
                                        </time>
                                    </div>

                                    <div className="mt-3 grid items-start gap-2 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]">
                                        <HistoryEndpoint
                                            endpoint={migration.source}
                                        />
                                        <ArrowRight className="mt-0.5 size-4 rotate-90 text-muted-foreground sm:rotate-0" />
                                        <HistoryEndpoint
                                            endpoint={migration.destination}
                                        />
                                    </div>

                                    <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 font-mono text-xs text-muted-foreground">
                                        <span>{migration.id}</span>
                                        <span>
                                            {migration.tables_copied} /{' '}
                                            {migration.tables_planned} tables
                                        </span>
                                    </div>
                                </div>

                                {migration.can_rollback && (
                                    <ConfirmationAction
                                        form={ApplicationDatabaseMigrationController.rollback.form(
                                            { migration: migration.id },
                                        )}
                                        phrase={rollbackPhrase}
                                        title="Synchronize and prepare rollback?"
                                        description="Current destination data will be copied back to the original database and verified before the active configuration changes. Access must be idle and queued jobs must drain."
                                        triggerLabel="Prepare rollback"
                                        submitLabel="Prepare rollback"
                                        destructive
                                    />
                                )}
                            </div>
                        );
                    })}
                </div>
            </details>
        </Card>
    );
}

function HistoryEndpoint({ endpoint }: { endpoint: MigrationEndpoint }) {
    return (
        <div className="min-w-0">
            <p className="text-sm font-medium">
                {driverLabel(endpoint.driver)}
            </p>
            <p className="mt-0.5 truncate font-mono text-xs text-muted-foreground">
                {endpoint.database ?? 'Not configured'}
            </p>
        </div>
    );
}

function driverLabel(driver: Driver['value']): string {
    if (driver === 'pgsql') {
        return 'PostgreSQL';
    }

    if (driver === 'mysql') {
        return 'MySQL';
    }

    return 'SQLite';
}

function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

ApplicationDatabase.layout = {
    breadcrumbs: [{ title: 'Application Database', href: edit() }],
};

function MigrationPanel({
    migration,
    confirmations,
}: {
    migration: MigrationState;
    confirmations: Props['confirmations'];
}) {
    const presentation = statusPresentation[migration.status];
    const plannedCount = Object.keys(migration.planned_tables ?? {}).length;
    const copiedCount = Object.keys(migration.tables ?? {}).length;
    const queueSize = Object.values(migration.queue_sizes ?? {}).reduce(
        (total, size) => total + size,
        0,
    );
    const liveAccess =
        migration.activity.query_sessions +
        migration.activity.native_leases +
        migration.activity.native_connections;

    return (
        <Card className="max-w-4xl gap-0 overflow-hidden py-0">
            <CardHeader className="border-b px-4 py-3 sm:px-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <CardTitle className="flex items-center gap-2">
                            <ArrowRightLeft className="size-4 text-blue-600" />
                            Migration plan
                        </CardTitle>
                        <CardDescription className="mt-1 font-mono text-xs">
                            {migration.id}
                        </CardDescription>
                    </div>
                    <Badge variant="outline" className={presentation.className}>
                        {migration.status === 'copying' && (
                            <CircleDashed className="animate-spin" />
                        )}
                        {presentation.label}
                    </Badge>
                </div>
            </CardHeader>

            <CardContent className="p-0">
                <div className="grid items-center gap-4 px-4 py-5 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] sm:px-5">
                    <Endpoint label="Source" endpoint={migration.source} />
                    <ArrowRight className="mx-auto size-5 rotate-90 text-muted-foreground sm:rotate-0" />
                    <Endpoint
                        label="Destination"
                        endpoint={migration.destination}
                    />
                </div>

                <div className="grid border-y bg-muted/15 sm:grid-cols-3 sm:divide-x">
                    <MigrationSignal
                        icon={<Copy className="size-4 text-blue-600" />}
                        label="Tables copied"
                        value={`${copiedCount} / ${plannedCount}`}
                        detail={
                            copiedCount === plannedCount
                                ? 'Copy set complete'
                                : `${plannedCount - copiedCount} remaining`
                        }
                    />
                    <MigrationSignal
                        icon={
                            liveAccess === 0 ? (
                                <CheckCircle2 className="size-4 text-emerald-600" />
                            ) : (
                                <AlertTriangle className="size-4 text-amber-600" />
                            )
                        }
                        label="Live access"
                        value={liveAccess === 0 ? 'Idle' : String(liveAccess)}
                        detail={
                            liveAccess === 0
                                ? 'Ready for migration'
                                : 'End access before continuing'
                        }
                    />
                    <MigrationSignal
                        icon={
                            queueSize === 0 ? (
                                <CheckCircle2 className="size-4 text-emerald-600" />
                            ) : (
                                <CircleDashed className="size-4 text-amber-600" />
                            )
                        }
                        label="Queued jobs"
                        value={queueSize === 0 ? 'Drained' : String(queueSize)}
                        detail={
                            queueSize === 0
                                ? 'Ready for migration'
                                : 'Wait for jobs to finish'
                        }
                    />
                </div>

                {migration.maintenance_fence && (
                    <div className="mx-4 mt-5 flex gap-3 rounded-md border border-amber-200 bg-amber-50/70 p-3 text-sm text-amber-950 sm:mx-5">
                        <ShieldCheck className="mt-0.5 size-4 shrink-0 text-amber-600" />
                        <p>
                            Maintenance fence is active. Normal web requests,
                            queued work, and all native-client control requests
                            remain blocked until this operation is finalized.
                        </p>
                    </div>
                )}

                <div className="px-4 py-5 sm:px-5">
                    <MigrationActions
                        migration={migration}
                        confirmations={confirmations}
                    />
                </div>

                <details className="group border-t">
                    <summary className="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-medium hover:bg-muted/30 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset sm:px-5">
                        <span className="flex items-center gap-2">
                            <Activity className="size-4 text-muted-foreground" />
                            Recent activity
                            <span className="font-normal text-muted-foreground">
                                ({Math.min(migration.events.length, 8)})
                            </span>
                        </span>
                        <ChevronDown className="size-4 text-muted-foreground transition-transform duration-200 group-open:rotate-180 motion-reduce:transition-none" />
                    </summary>
                    <div className="divide-y border-t">
                        {migration.events
                            .slice(-8)
                            .reverse()
                            .map((event, index) => (
                                <div
                                    key={`${event.at}-${event.event}-${index}`}
                                    className="grid gap-1 px-3 py-2.5 text-sm sm:grid-cols-[minmax(0,1fr)_auto]"
                                >
                                    <div className="min-w-0">
                                        <p className="capitalize">
                                            {event.event.replaceAll('_', ' ')}
                                        </p>
                                        {event.message && (
                                            <details className="mt-1 text-xs text-red-700">
                                                <summary className="w-fit cursor-pointer font-medium hover:underline">
                                                    View error details
                                                </summary>
                                                <pre className="mt-2 max-h-48 overflow-auto rounded-md bg-red-50 p-3 font-mono text-[11px] leading-5 break-words whitespace-pre-wrap">
                                                    {event.message}
                                                </pre>
                                            </details>
                                        )}
                                        {event.actor && (
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                {event.actor.name}
                                            </p>
                                        )}
                                    </div>
                                    <time className="text-xs whitespace-nowrap text-muted-foreground">
                                        {new Intl.DateTimeFormat(undefined, {
                                            dateStyle: 'medium',
                                            timeStyle: 'short',
                                        }).format(new Date(event.at))}
                                    </time>
                                </div>
                            ))}
                    </div>
                </details>
            </CardContent>
        </Card>
    );
}

function MigrationActions({
    migration,
    confirmations,
}: {
    migration: MigrationState;
    confirmations: Props['confirmations'];
}) {
    if (['planned', 'copying', 'failed'].includes(migration.status)) {
        return (
            <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <Form
                    {...ApplicationDatabaseMigrationController.migrate.form({
                        migration: migration.id,
                    })}
                    disableWhileProcessing
                    className="grid flex-1 gap-4 sm:grid-cols-[minmax(0,1fr)_9rem_auto] sm:items-end"
                >
                    {({ processing, errors }) => (
                        <>
                            <div>
                                <p className="font-medium">
                                    {migration.status === 'failed'
                                        ? 'Retry the copy'
                                        : migration.status === 'copying'
                                          ? 'Resume interrupted copy'
                                          : 'Copy application data'}
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {migration.status === 'failed'
                                        ? 'The destination is cleaned before the copy starts again.'
                                        : 'Access must be idle and queues must drain first.'}
                                </p>
                                <InputError
                                    message={errors.migration_operation}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="drain_timeout_seconds">
                                    Drain timeout
                                </Label>
                                <Input
                                    id="drain_timeout_seconds"
                                    name="drain_timeout_seconds"
                                    type="number"
                                    min="0"
                                    max="3600"
                                    defaultValue="30"
                                    required
                                />
                            </div>
                            <Button disabled={processing}>
                                {processing ? <Spinner /> : <Copy />}
                                {migration.status === 'planned'
                                    ? 'Start copy'
                                    : 'Retry copy'}
                            </Button>
                        </>
                    )}
                </Form>
                {['planned', 'failed'].includes(migration.status) && (
                    <CancelPlanAction migration={migration} />
                )}
            </div>
        );
    }

    if (migration.status === 'copied') {
        return (
            <Form
                {...ApplicationDatabaseMigrationController.verify.form({
                    migration: migration.id,
                })}
                disableWhileProcessing
                className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                {({ processing, errors }) => (
                    <>
                        <div>
                            <p className="font-medium">
                                Verify the copied data
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Recheck every table count and deterministic
                                digest.
                            </p>
                            <InputError message={errors.migration_operation} />
                        </div>
                        <Button disabled={processing}>
                            {processing ? <Spinner /> : <ShieldCheck />}
                            Verify copy
                        </Button>
                    </>
                )}
            </Form>
        );
    }

    if (migration.status === 'verified') {
        return (
            <ConfirmationAction
                form={ApplicationDatabaseMigrationController.activate.form({
                    migration: migration.id,
                })}
                phrase={confirmations.activate}
                title="Prepare application database cutover?"
                description="Crucible will write the destination as the active configuration. The maintenance fence stays active until every runtime process is restarted and activation is finalized."
                triggerLabel="Prepare cutover"
                submitLabel="Prepare cutover"
            />
        );
    }

    if (migration.status === 'activation_pending_restart') {
        return (
            <RestartAction
                form={ApplicationDatabaseMigrationController.finalizeActivation.form(
                    { migration: migration.id },
                )}
                phrase={confirmations.finalize}
                title="Finalize destination activation?"
                submitLabel="Finalize activation"
                restartReady={migration.restart_ready === true}
            />
        );
    }

    if (migration.status === 'active') {
        return (
            <ConfirmationAction
                form={ApplicationDatabaseMigrationController.rollback.form({
                    migration: migration.id,
                })}
                phrase={confirmations.rollback}
                title="Synchronize and prepare rollback?"
                description="Current destination data will be copied back to the original database and verified before the active configuration changes. Access must be idle and queued jobs must drain."
                triggerLabel="Prepare rollback"
                submitLabel="Prepare rollback"
                destructive
            />
        );
    }

    if (migration.status === 'rollback_pending_restart') {
        return (
            <RestartAction
                form={ApplicationDatabaseMigrationController.finalizeRollback.form(
                    { migration: migration.id },
                )}
                phrase={confirmations.finalize}
                title="Finalize rollback?"
                submitLabel="Finalize rollback"
                restartReady={migration.restart_ready === true}
            />
        );
    }

    if (migration.status === 'cancelled') {
        return (
            <div className="flex items-start gap-3 rounded-md border border-slate-200 bg-slate-50/70 p-4 text-slate-950">
                <Ban className="mt-0.5 size-5 shrink-0 text-slate-600" />
                <div>
                    <p className="font-medium">Migration plan cancelled</p>
                    <p className="mt-1 text-sm text-slate-700">
                        The application database was not changed.
                    </p>
                </div>
            </div>
        );
    }

    return (
        <div className="flex items-start gap-3 rounded-md border border-emerald-200 bg-emerald-50/70 p-4 text-emerald-950">
            <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-emerald-600" />
            <div>
                <p className="font-medium">Rollback completed</p>
                <p className="mt-1 text-sm text-emerald-900/75">
                    The original database is active and normal access has
                    resumed.
                </p>
            </div>
        </div>
    );
}

function CancelPlanAction({ migration }: { migration: MigrationState }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button type="button" variant="outline" className="w-fit">
                    <Ban />
                    Cancel plan
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Cancel this migration plan?</DialogTitle>
                    <DialogDescription>
                        The active database will not change. Data already copied
                        to {migration.destination.driver.toUpperCase()} remains
                        there and must be removed before that database can be
                        used in a new migration plan.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...ApplicationDatabaseMigrationController.destroy.form({
                        migration: migration.id,
                    })}
                    disableWhileProcessing
                >
                    {({ processing, errors }) => (
                        <>
                            <InputError message={errors.migration_operation} />
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        Keep plan
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    {processing ? <Spinner /> : <Ban />}
                                    Cancel plan
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function RestartAction({
    form,
    phrase,
    title,
    submitLabel,
    restartReady,
}: {
    form: { action: string; method: 'post' };
    phrase: string;
    title: string;
    submitLabel: string;
    restartReady: boolean;
}) {
    return (
        <div className="grid gap-4 rounded-md border border-amber-200 bg-amber-50/60 p-4">
            <div className="flex gap-3">
                <RefreshCw className="mt-0.5 size-5 shrink-0 text-amber-700" />
                <div className="min-w-0">
                    <p className="font-medium text-amber-950">
                        Restart all Laravel runtimes
                    </p>
                    <p className="mt-1 text-sm leading-6 text-amber-900/75">
                        Production Compose runs Octane, Horizon, and the
                        scheduler in the app container. Development uses
                        separate app, worker, and scheduler containers. Restart
                        every listed runtime, then wait for this page to confirm
                        that the web runtime loaded the new database.
                    </p>
                    <div className="mt-3 grid gap-2 font-mono text-xs">
                        <code className="overflow-x-auto rounded bg-amber-100/70 px-2 py-1.5">
                            docker compose --env-file .env.production -f
                            compose.production.yaml restart app
                        </code>
                        <code className="overflow-x-auto rounded bg-amber-100/70 px-2 py-1.5">
                            docker compose restart app worker scheduler
                        </code>
                    </div>
                    <div
                        className={`mt-3 flex items-start gap-2 rounded-md border px-3 py-2.5 text-sm ${
                            restartReady
                                ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
                                : 'border-amber-200 bg-amber-100/60 text-amber-950'
                        }`}
                    >
                        {restartReady ? (
                            <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-emerald-600" />
                        ) : (
                            <CircleDashed className="mt-0.5 size-4 shrink-0 animate-spin text-amber-700 motion-reduce:animate-none" />
                        )}
                        <div>
                            <p className="font-medium">
                                {restartReady
                                    ? 'Ready to finalize'
                                    : 'Waiting for the restarted web runtime'}
                            </p>
                            <p className="mt-0.5 text-xs leading-5 opacity-80">
                                {restartReady
                                    ? 'The active runtime is using the expected application database.'
                                    : 'This status updates automatically after the app container is ready.'}
                            </p>
                        </div>
                    </div>
                    <p className="mt-3 text-xs leading-5 text-amber-900/75">
                        The native-proxy container may report unhealthy during
                        this step because the maintenance fence intentionally
                        blocks its control requests. It should recover after
                        finalization releases the fence.
                    </p>
                </div>
            </div>
            <ConfirmationAction
                form={form}
                phrase={phrase}
                title={title}
                description="Finalization checks that this running process has actually loaded the expected database fingerprint before it removes the maintenance fence."
                triggerLabel={submitLabel}
                submitLabel={submitLabel}
                disabled={!restartReady}
            />
        </div>
    );
}

function ConfirmationAction({
    form,
    phrase,
    title,
    description,
    triggerLabel,
    submitLabel,
    destructive = false,
    disabled = false,
}: {
    form: { action: string; method: 'post' };
    phrase: string;
    title: string;
    description: string;
    triggerLabel: string;
    submitLabel: string;
    destructive?: boolean;
    disabled?: boolean;
}) {
    const [confirmation, setConfirmation] = useState('');

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    variant={destructive ? 'destructive' : 'default'}
                    className="w-fit"
                    disabled={disabled}
                >
                    {destructive ? <RotateCcw /> : <ArrowRightLeft />}
                    {triggerLabel}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <Form
                    {...form}
                    disableWhileProcessing
                    onSuccess={() => setConfirmation('')}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor={`confirmation-${phrase}`}>
                                    Type {phrase} to confirm
                                </Label>
                                <Input
                                    id={`confirmation-${phrase}`}
                                    name="confirmation"
                                    value={confirmation}
                                    onChange={(event) =>
                                        setConfirmation(event.target.value)
                                    }
                                    autoComplete="off"
                                />
                                <InputError message={errors.confirmation} />
                                <InputError
                                    message={errors.migration_operation}
                                />
                            </div>
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant={
                                        destructive ? 'destructive' : 'default'
                                    }
                                    disabled={
                                        processing || confirmation !== phrase
                                    }
                                >
                                    {processing && <Spinner />}
                                    {submitLabel}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function PlanMigrationCard({
    drivers,
    sqlitePath,
}: {
    drivers: Driver[];
    sqlitePath: string;
}) {
    const [driver, setDriver] = useState<Driver>(drivers[0]);
    const isNetworkDatabase = driver.value !== 'sqlite';

    return (
        <Card className="max-w-4xl gap-0 overflow-hidden py-0">
            <CardHeader className="border-b px-4 py-3 sm:px-5">
                <CardTitle className="flex items-center gap-2">
                    <ArrowRightLeft className="size-4 text-blue-600" />
                    Plan a migration
                </CardTitle>
                <CardDescription>
                    The destination must be dedicated and empty. Planning tests
                    connectivity without changing the active database.
                </CardDescription>
            </CardHeader>
            <CardContent className="px-4 py-5 sm:px-5">
                <Form
                    {...ApplicationDatabaseMigrationController.store.form()}
                    disableWhileProcessing
                    resetOnError={['password']}
                    className="grid max-w-2xl gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="driver">Destination type</Label>
                                <select
                                    id="driver"
                                    name="driver"
                                    value={driver.value}
                                    onChange={(event) => {
                                        const selected = drivers.find(
                                            (item) =>
                                                item.value ===
                                                event.currentTarget.value,
                                        );

                                        if (selected) {
                                            setDriver(selected);
                                        }
                                    }}
                                    className="h-10 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                >
                                    {drivers.map((item) => (
                                        <option
                                            key={item.value}
                                            value={item.value}
                                        >
                                            {item.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.driver} />
                            </div>

                            {!isNetworkDatabase ? (
                                <div className="grid gap-3 rounded-md border bg-muted/20 p-4">
                                    <div className="flex items-center gap-3">
                                        <span className="flex size-9 items-center justify-center rounded-md bg-orange-100 text-orange-700">
                                            <HardDrive className="size-5" />
                                        </span>
                                        <div>
                                            <p className="font-medium">
                                                New SQLite file
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                The file must not exist or must
                                                be empty.
                                            </p>
                                        </div>
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="sqlite_database">
                                            Database path
                                        </Label>
                                        <Input
                                            id="sqlite_database"
                                            name="sqlite_database"
                                            defaultValue={sqlitePath}
                                            required
                                            className="font-mono text-xs"
                                        />
                                        <InputError
                                            message={errors.sqlite_database}
                                        />
                                    </div>
                                </div>
                            ) : (
                                <NetworkDatabaseFields
                                    driver={driver}
                                    errors={errors}
                                />
                            )}

                            <InputError message={errors.migration_operation} />
                            <Button className="w-fit" disabled={processing}>
                                {processing ? <Spinner /> : <ShieldCheck />}
                                Test and create plan
                            </Button>
                        </>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}

function NetworkDatabaseFields({
    driver,
    errors,
}: {
    driver: Driver;
    errors: Partial<Record<string, string>>;
}) {
    return (
        <div className="grid gap-4 rounded-md border bg-muted/20 p-4">
            <div className="flex items-start gap-3">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-emerald-100 text-emerald-700">
                    <Server className="size-5" />
                </span>
                <div>
                    <p className="font-medium">Dedicated empty database</p>
                    <p className="text-sm text-muted-foreground">
                        Credentials are encrypted in the migration plan and are
                        never returned to the browser.
                    </p>
                </div>
            </div>
            <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_9rem]">
                <Field label="Host" name="host" error={errors.host} required />
                <Field
                    key={driver.value}
                    label="Port"
                    name="port"
                    error={errors.port}
                    type="number"
                    defaultValue={driver.default_port ?? ''}
                    required
                />
            </div>
            <Field
                label="Database name"
                name="database"
                error={errors.database}
                required
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Username"
                    name="username"
                    error={errors.username}
                    required
                />
                <div className="grid gap-2">
                    <Label htmlFor="password">Password</Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        autoComplete="new-password"
                        required
                    />
                    <InputError message={errors.password} />
                </div>
            </div>
            {driver.value === 'pgsql' && (
                <div className="grid gap-2">
                    <Label htmlFor="pgsql_sslmode">PostgreSQL SSL mode</Label>
                    <select
                        id="pgsql_sslmode"
                        name="pgsql_sslmode"
                        defaultValue="prefer"
                        className="h-10 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        <option value="disable">Disable</option>
                        <option value="prefer">Prefer</option>
                        <option value="require">Require</option>
                        <option value="verify-ca">Verify CA</option>
                        <option value="verify-full">Verify identity</option>
                    </select>
                    <InputError message={errors.pgsql_sslmode} />
                </div>
            )}
            {driver.value === 'mysql' && (
                <Field
                    label="MySQL CA file path (optional)"
                    name="mysql_ssl_ca"
                    error={errors.mysql_ssl_ca}
                    placeholder="/run/secrets/mysql-ca.pem"
                />
            )}
        </div>
    );
}

function Field({
    label,
    name,
    error,
    ...inputProps
}: {
    label: string;
    name: string;
    error?: string;
} & ComponentProps<typeof Input>) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={name}>{label}</Label>
            <Input id={name} name={name} {...inputProps} />
            <InputError message={error} />
        </div>
    );
}

function Detail({
    label,
    value,
    mono = false,
}: {
    label: string;
    value: string;
    mono?: boolean;
}) {
    return (
        <div className="min-w-0">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p
                className={`mt-1 text-sm leading-5 font-medium break-all ${mono ? 'font-mono' : ''}`}
            >
                {value}
            </p>
        </div>
    );
}

function Endpoint({
    label,
    endpoint,
}: {
    label: string;
    endpoint: MigrationEndpoint;
}) {
    return (
        <div className="min-w-0">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="mt-1 text-lg font-medium uppercase">
                {endpoint.driver}
            </p>
            <p className="mt-1 font-mono text-xs leading-5 break-all text-muted-foreground">
                {endpoint.database ?? 'Not configured'}
            </p>
        </div>
    );
}

function MigrationSignal({
    icon,
    label,
    value,
    detail,
}: {
    icon: ReactNode;
    label: string;
    value: string;
    detail: string;
}) {
    return (
        <div className="flex min-w-0 items-start gap-3 border-b px-4 py-3 last:border-b-0 sm:border-b-0 sm:px-5">
            <span className="mt-0.5 shrink-0">{icon}</span>
            <div className="min-w-0">
                <p className="text-xs text-muted-foreground">{label}</p>
                <p className="mt-0.5 font-medium tabular-nums">{value}</p>
                <p className="mt-0.5 text-xs text-muted-foreground">{detail}</p>
            </div>
        </div>
    );
}
