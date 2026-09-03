import { Form, Head, usePoll } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRightLeft,
    CheckCircle2,
    CircleDashed,
    Copy,
    Database,
    HardDrive,
    RefreshCw,
    RotateCcw,
    Server,
    ShieldCheck,
} from 'lucide-react';
import type { ComponentProps } from 'react';
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
        | 'failed';
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
    created_at: string;
    updated_at: string;
};

type Props = {
    configuration_mode: 'managed' | 'environment';
    active_database: DatabaseSummary;
    migration: MigrationState | null;
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
};

export default function ApplicationDatabase({
    configuration_mode: configurationMode,
    active_database: activeDatabase,
    migration,
    migration_error: migrationError,
    drivers,
    sqlite_path: sqlitePath,
    confirmations,
}: Props) {
    const { start, stop } = usePoll(
        3000,
        {
            only: ['active_database', 'migration', 'migration_error'],
            preserveErrors: true,
        },
        { autoStart: false, mode: 'rest' },
    );

    useEffect(() => {
        if (migration?.status === 'copying') {
            start();
        } else {
            stop();
        }

        return stop;
    }, [migration?.status, start, stop]);

    const canPlan =
        configurationMode === 'managed' &&
        (!migration || ['active', 'rolled_back'].includes(migration.status));

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
            </div>
        </>
    );
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

            <CardContent className="grid gap-5 px-4 py-5 sm:px-5">
                <div className="grid items-center gap-3 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]">
                    <Endpoint label="Source" endpoint={migration.source} />
                    <ArrowRightLeft className="mx-auto size-5 text-muted-foreground" />
                    <Endpoint
                        label="Destination"
                        endpoint={migration.destination}
                    />
                </div>

                <div className="grid gap-3 sm:grid-cols-3">
                    <Metric
                        label="Tables copied"
                        value={`${copiedCount} / ${plannedCount}`}
                    />
                    <Metric
                        label="Live access"
                        value={String(
                            migration.activity.query_sessions +
                                migration.activity.native_leases +
                                migration.activity.native_connections,
                        )}
                        detail="Must be zero before copy or rollback"
                    />
                    <Metric
                        label="Queued jobs"
                        value={String(queueSize)}
                        detail="Must drain before the fence stays active"
                    />
                </div>

                {migration.maintenance_fence && (
                    <div className="flex gap-3 rounded-md border border-amber-200 bg-amber-50/70 p-3 text-sm text-amber-950">
                        <ShieldCheck className="mt-0.5 size-4 shrink-0 text-amber-600" />
                        <p>
                            Maintenance fence is active. Normal web requests,
                            queued work, and all native-client control requests
                            remain blocked until this operation is finalized.
                        </p>
                    </div>
                )}

                <MigrationActions
                    migration={migration}
                    confirmations={confirmations}
                />

                <div className="border-t pt-4">
                    <h3 className="text-sm font-medium">
                        Recent migration activity
                    </h3>
                    <div className="mt-3 divide-y rounded-md border">
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
                                            <p className="mt-0.5 text-xs break-words text-red-700">
                                                {event.message}
                                            </p>
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
                </div>
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
            <Form
                {...ApplicationDatabaseMigrationController.migrate.form({
                    migration: migration.id,
                })}
                disableWhileProcessing
                className="grid gap-3 rounded-md border bg-muted/20 p-4 sm:grid-cols-[minmax(0,1fr)_10rem_auto] sm:items-end"
            >
                {({ processing, errors }) => (
                    <>
                        <div>
                            <p className="font-medium">
                                {migration.status === 'failed'
                                    ? 'Retry a clean copy'
                                    : migration.status === 'copying'
                                      ? 'Resume interrupted copy'
                                      : 'Copy application data'}
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Access must be idle and queues must drain first.
                            </p>
                            <InputError message={errors.migration_operation} />
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
        );
    }

    if (migration.status === 'copied') {
        return (
            <Form
                {...ApplicationDatabaseMigrationController.verify.form({
                    migration: migration.id,
                })}
                disableWhileProcessing
                className="flex flex-col gap-3 rounded-md border bg-muted/20 p-4 sm:flex-row sm:items-center sm:justify-between"
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
            />
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

function RestartAction({
    form,
    phrase,
    title,
    submitLabel,
}: {
    form: { action: string; method: 'post' };
    phrase: string;
    title: string;
    submitLabel: string;
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
                        separate app, worker, and scheduler containers. Do not
                        finalize until every process reports healthy on the new
                        configuration.
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
                </div>
            </div>
            <ConfirmationAction
                form={form}
                phrase={phrase}
                title={title}
                description="Finalization checks that this running process has actually loaded the expected database fingerprint before it removes the maintenance fence."
                triggerLabel={submitLabel}
                submitLabel={submitLabel}
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
}: {
    form: { action: string; method: 'post' };
    phrase: string;
    title: string;
    description: string;
    triggerLabel: string;
    submitLabel: string;
    destructive?: boolean;
}) {
    const [confirmation, setConfirmation] = useState('');

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    variant={destructive ? 'destructive' : 'default'}
                    className="w-fit"
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
                className={`mt-1 truncate text-sm font-medium ${mono ? 'font-mono' : ''}`}
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
        <div className="min-w-0 rounded-md border bg-muted/20 p-3">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="mt-1 font-medium uppercase">{endpoint.driver}</p>
            <p className="mt-1 truncate font-mono text-xs text-muted-foreground">
                {endpoint.database}
            </p>
        </div>
    );
}

function Metric({
    label,
    value,
    detail,
}: {
    label: string;
    value: string;
    detail?: string;
}) {
    return (
        <div className="rounded-md border px-3 py-2.5">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="mt-1 text-lg font-semibold tabular-nums">{value}</p>
            {detail && (
                <p className="mt-1 text-xs text-muted-foreground">{detail}</p>
            )}
        </div>
    );
}
