import { useHttp } from '@inertiajs/react';
import {
    Check,
    Copy,
    Download,
    ExternalLink,
    KeyRound,
    Monitor,
    RotateCw,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import NativeProxyConnectionController from '@/actions/App/Http/Controllers/NativeProxy/ConnectionController';
import {
    rotate,
    store,
} from '@/actions/App/Http/Controllers/NativeProxy/LeaseController';
import { Pagination } from '@/components/crucible/pagination';
import { StatusBadge } from '@/components/crucible/status-badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { driverLabel, formatDate } from '@/lib/crucible';
import type {
    DatabaseDriver,
    ExecutionStatus,
    Paginated,
    QueryType,
} from '@/lib/crucible';

type Credential = {
    lease_id: string;
    username: string;
    password: string;
    credential_version: number;
    expires_at: string;
};

type Lease = {
    id: string | null;
    status: string;
    credential_version: number;
    credentials_created_at: string | null;
};

type NativeProxyConnection = {
    id: string;
    protocol: DatabaseDriver;
    status: string;
    client_application: string | null;
    connected_at: string | null;
    last_activity_at: string | null;
    statement_count: number;
};

type AuthorizedDevice = {
    id: string;
    device_label: string | null;
    operating_system: string;
    architecture: string;
    authorized_at: string | null;
};

type ConnectionsResponse = {
    data: NativeProxyConnection[];
    authorized_devices: AuthorizedDevice[];
};

type Props = {
    session: {
        id: number;
        expires_at: string;
        connection: { name: string; driver: DatabaseDriver };
        native_proxy: {
            can_manage: boolean;
            lease: Lease | null;
            connections: NativeProxyConnection[];
            authorized_devices: AuthorizedDevice[];
            statements: Paginated<{
                id: number;
                protocol_command: string | null;
                sql_fingerprint: string | null;
                parameter_count: number;
                query_type: QueryType;
                status: ExecutionStatus;
                row_count: number | null;
                duration_ms: number | null;
                created_at: string | null;
            }>;
        };
    };
    serverUrl: string;
    cliDownloadUrl?: string;
    timezone: string;
};

export function NativeProxySessionPanel({
    session,
    serverUrl,
    cliDownloadUrl,
    timezone,
}: Props) {
    const { submit, processing } = useHttp();
    const { get: getConnections, cancel: cancelConnectionsRequest } = useHttp<
        Record<string, never>,
        ConnectionsResponse
    >({});
    const [credential, setCredential] = useState<Credential | null>(null);
    const [createdLease, setCreatedLease] = useState<Lease | null>(null);
    const [connections, setConnections] = useState<NativeProxyConnection[]>(
        session.native_proxy.connections,
    );
    const [authorizedDevices, setAuthorizedDevices] = useState<
        AuthorizedDevice[]
    >(session.native_proxy.authorized_devices);
    const [message, setMessage] = useState<string | null>(null);
    const lease = createdLease ?? session.native_proxy.lease;
    const hasUsableCredentials =
        credential !== null || lease?.status === 'active';
    const hasTerminalLease =
        lease?.status === 'revoked' || lease?.status === 'expired';
    const activeConnections = connections.filter(
        (connection) => connection.status === 'active',
    );
    const hasConnectedClient = activeConnections.length > 0;
    const hasApprovedDevice = authorizedDevices.length > 0;
    const localPort = session.connection.driver === 'pgsql' ? '5432' : '3306';

    function clearCredential(): void {
        setCredential(null);
    }

    function applyCredential(response: Credential): void {
        setCredential(response);
        setCreatedLease({
            id: response.lease_id,
            status: 'active',
            credential_version: response.credential_version,
            credentials_created_at: new Date().toISOString(),
        });
    }

    useEffect(() => {
        let stopped = false;
        let timeout: number | undefined;

        async function refreshConnections(): Promise<void> {
            if (!document.hidden) {
                try {
                    const response = await getConnections(
                        NativeProxyConnectionController.index.url(session.id),
                    );

                    if (!stopped) {
                        setConnections(response.data);
                        setAuthorizedDevices(response.authorized_devices);
                    }
                } catch {
                    // Keep the last known state during a transient poll failure.
                }
            }

            if (!stopped) {
                timeout = window.setTimeout(refreshConnections, 3_000);
            }
        }

        timeout = window.setTimeout(refreshConnections, 3_000);

        return () => {
            stopped = true;

            if (timeout !== undefined) {
                window.clearTimeout(timeout);
            }

            cancelConnectionsRequest();
        };
    }, [cancelConnectionsRequest, getConnections, session.id]);

    const command = useMemo(() => {
        if (!session.native_proxy.can_manage) {
            return null;
        }

        const leaseId = credential?.lease_id ?? lease?.id;
        const localDevelopmentFlag = serverUrl.startsWith('http://')
            ? ' --allow-insecure-http'
            : '';

        return leaseId
            ? `crucible connect --server ${serverUrl} --lease ${leaseId}${localDevelopmentFlag}`
            : null;
    }, [
        credential?.lease_id,
        lease?.id,
        serverUrl,
        session.native_proxy.can_manage,
    ]);

    async function createCredentials(): Promise<void> {
        setMessage(null);

        try {
            const response = (await submit(store(session.id), {
                headers: { 'Idempotency-Key': crypto.randomUUID() },
            })) as Credential;
            applyCredential(response);
        } catch {
            setMessage(
                'Credentials were already created or could not be created. Rotate credentials to issue a new password.',
            );
        }
    }

    async function rotateCredentials(): Promise<void> {
        if (lease?.id == null) {
            return;
        }

        setMessage(null);
        clearCredential();

        try {
            applyCredential((await submit(rotate(lease.id))) as Credential);
        } catch {
            setMessage(
                'Credentials could not be rotated. Refresh the session state and try again.',
            );
        }
    }

    return (
        <div className="grid gap-4 p-4 sm:p-6">
            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <Card className="gap-0 overflow-hidden py-0">
                    <CardHeader className="border-b px-4 py-4 sm:px-6">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <CardTitle>Connect in 3 steps</CardTitle>
                                <CardDescription className="mt-1 max-w-2xl">
                                    Keep this page open while you create a local
                                    tunnel and connect your database tool.
                                </CardDescription>
                            </div>
                            {lease && <StatusBadge value={lease.status} />}
                        </div>
                    </CardHeader>
                    <CardContent className="p-0">
                        <ol className="divide-y">
                            <SetupStep
                                number={1}
                                title="Create temporary credentials"
                                status={
                                    hasUsableCredentials
                                        ? 'complete'
                                        : hasTerminalLease
                                          ? 'blocked'
                                          : 'current'
                                }
                            >
                                <p className="max-w-2xl text-sm text-muted-foreground">
                                    Create the username and password your
                                    database tool will use. The password is
                                    shown once, so copy both values before
                                    continuing.
                                </p>

                                {session.native_proxy.can_manage ? (
                                    <div className="flex flex-wrap gap-2">
                                        {lease === null &&
                                        credential === null ? (
                                            <Button
                                                onClick={createCredentials}
                                                disabled={processing}
                                            >
                                                <KeyRound /> Create credentials
                                            </Button>
                                        ) : lease?.status === 'active' ? (
                                            <div className="grid gap-1">
                                                <Button
                                                    variant="outline"
                                                    onClick={rotateCredentials}
                                                    disabled={processing}
                                                >
                                                    <RotateCw /> Rotate
                                                    credentials
                                                </Button>
                                                <p className="text-xs text-muted-foreground">
                                                    Rotation disconnects
                                                    database clients. Keep the
                                                    CLI running, then reconnect
                                                    with the new password.
                                                </p>
                                            </div>
                                        ) : lease !== null ? (
                                            <p className="text-sm text-muted-foreground">
                                                This native client access is{' '}
                                                {lease.status}. End this
                                                session, then request access
                                                again if you still need the
                                                database.
                                            </p>
                                        ) : null}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        Only the session owner or an
                                        administrator can manage credentials.
                                    </p>
                                )}

                                {credential && (
                                    <CredentialReveal
                                        credential={credential}
                                        onDismiss={clearCredential}
                                    />
                                )}

                                {message && (
                                    <p
                                        role="status"
                                        className="text-sm text-destructive"
                                    >
                                        {message}
                                    </p>
                                )}
                            </SetupStep>

                            <SetupStep
                                number={2}
                                title="Start and approve the CLI tunnel"
                                status={
                                    hasApprovedDevice
                                        ? 'complete'
                                        : hasUsableCredentials
                                          ? 'current'
                                          : 'upcoming'
                                }
                            >
                                <p className="max-w-2xl text-sm text-muted-foreground">
                                    Install the CLI, run the command below, then
                                    approve the device code in the browser page
                                    it opens. Leave the CLI running.
                                </p>

                                {cliDownloadUrl && (
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <a
                                                href={cliDownloadUrl}
                                                target="_blank"
                                                rel="noreferrer noopener"
                                            >
                                                <Download /> Download CLI
                                                <ExternalLink className="size-3.5" />
                                            </a>
                                        </Button>
                                        <span className="text-xs text-muted-foreground">
                                            macOS, Linux, and Windows
                                        </span>
                                    </div>
                                )}

                                {command ? (
                                    <CopyValue
                                        label="Run in your terminal"
                                        value={command}
                                    />
                                ) : (
                                    <p className="text-xs text-muted-foreground">
                                        The CLI command appears after step 1.
                                    </p>
                                )}
                            </SetupStep>

                            <SetupStep
                                number={3}
                                title={`Connect ${driverLabel(session.connection.driver)} or DBeaver`}
                                status={
                                    hasConnectedClient
                                        ? 'complete'
                                        : hasApprovedDevice
                                          ? 'current'
                                          : 'upcoming'
                                }
                            >
                                <p className="max-w-2xl text-sm text-muted-foreground">
                                    After the CLI reports that access is
                                    authorized, configure your database tool
                                    with the temporary credentials from step 1.
                                </p>

                                <dl className="grid grid-cols-2 gap-x-6 gap-y-3 border-t pt-4 text-sm sm:grid-cols-4">
                                    <div className="grid gap-1">
                                        <dt className="text-xs text-muted-foreground">
                                            Host
                                        </dt>
                                        <dd className="font-mono">127.0.0.1</dd>
                                    </div>
                                    <div className="grid gap-1">
                                        <dt className="text-xs text-muted-foreground">
                                            Port
                                        </dt>
                                        <dd className="font-mono">
                                            {localPort}
                                        </dd>
                                    </div>
                                    <div className="grid gap-1">
                                        <dt className="text-xs text-muted-foreground">
                                            TLS
                                        </dt>
                                        <dd>Disabled locally</dd>
                                    </div>
                                    <div className="grid gap-1">
                                        <dt className="text-xs text-muted-foreground">
                                            Credentials
                                        </dt>
                                        <dd>From step 1</dd>
                                    </div>
                                </dl>

                                {hasConnectedClient ? (
                                    <p
                                        role="status"
                                        className="flex items-center gap-2 text-sm font-medium text-emerald-700 dark:text-emerald-300"
                                    >
                                        <Check className="size-4" /> Database
                                        client connected
                                    </p>
                                ) : (
                                    <p className="text-xs text-muted-foreground">
                                        Waiting for a database client. Keep the
                                        CLI process running while you connect.
                                    </p>
                                )}
                            </SetupStep>
                        </ol>
                    </CardContent>
                </Card>

                <aside className="grid content-start gap-4">
                    <Card className="gap-0 overflow-hidden py-0">
                        <CardHeader className="border-b px-4 py-4">
                            <CardTitle className="text-base">
                                Session scope
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="divide-y p-0">
                            <dl className="grid gap-4 px-4 py-4 text-sm">
                                <div className="grid gap-1">
                                    <dt className="text-xs text-muted-foreground">
                                        Target
                                    </dt>
                                    <dd className="font-medium">
                                        {session.connection.name}
                                    </dd>
                                    <dd className="text-xs text-muted-foreground">
                                        {driverLabel(session.connection.driver)}
                                    </dd>
                                </div>
                                <div className="grid gap-1">
                                    <dt className="text-xs text-muted-foreground">
                                        Access window
                                    </dt>
                                    <dd className="font-mono text-xs">
                                        Ends{' '}
                                        {formatDate(
                                            session.expires_at,
                                            timezone,
                                        )}
                                    </dd>
                                </div>
                                <div className="grid gap-1">
                                    <dt className="text-xs text-muted-foreground">
                                        Transport
                                    </dt>
                                    <dd>Encrypted CLI tunnel</dd>
                                    <dd className="text-xs text-muted-foreground">
                                        TLS is disabled only on the localhost
                                        hop.
                                    </dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>

                    <Card className="gap-0 overflow-hidden py-0">
                        <CardHeader className="border-b px-4 py-4">
                            <div className="flex items-center justify-between gap-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Monitor className="size-4 text-muted-foreground" />
                                    Client activity
                                </CardTitle>
                                <span className="text-xs text-muted-foreground">
                                    {authorizedDevices.length} CLI approved ·{' '}
                                    {hasConnectedClient
                                        ? `${activeConnections.length} DB connected`
                                        : 'DB client waiting'}
                                </span>
                            </div>
                        </CardHeader>
                        <CardContent className="p-0">
                            {authorizedDevices.map((device) => (
                                <div
                                    key={device.id}
                                    className="grid gap-1 border-b px-4 py-3 text-sm"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="font-medium">
                                            {device.device_label ??
                                                'Crucible CLI device'}
                                        </span>
                                        <StatusBadge
                                            value="approved"
                                            label="CLI approved"
                                        />
                                    </div>
                                    <span className="text-xs text-muted-foreground">
                                        {device.operating_system}/
                                        {device.architecture} · approved{' '}
                                        {formatDate(
                                            device.authorized_at,
                                            timezone,
                                        )}
                                    </span>
                                </div>
                            ))}
                            {connections.map((connection) => (
                                <div
                                    key={connection.id}
                                    className="grid gap-1 border-b px-4 py-3 text-sm last:border-0"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="font-medium">
                                            {connection.client_application ??
                                                driverLabel(
                                                    connection.protocol,
                                                )}
                                        </span>
                                        <StatusBadge
                                            value={connection.status}
                                        />
                                    </div>
                                    <span className="text-xs text-muted-foreground">
                                        {connection.statement_count} statements
                                        · last activity{' '}
                                        {formatDate(
                                            connection.last_activity_at,
                                            timezone,
                                        )}
                                    </span>
                                </div>
                            ))}
                            {connections.length === 0 && (
                                <div className="grid gap-1 px-4 py-4">
                                    <p className="text-sm font-medium">
                                        {hasApprovedDevice
                                            ? 'CLI approved, waiting for a database client'
                                            : 'Waiting for CLI approval'}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {hasApprovedDevice
                                            ? 'Now connect psql, MySQL, or DBeaver using step 3.'
                                            : 'Complete step 2 to authorize this CLI device.'}
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </aside>
            </div>

            <Card className="gap-0 overflow-hidden py-0">
                <CardHeader className="border-b px-4 py-4 sm:px-6">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <CardTitle className="text-base">
                                Statement history
                            </CardTitle>
                            <CardDescription className="mt-1">
                                Result values and parameter bindings are not
                                retained.
                            </CardDescription>
                        </div>
                        <span className="text-xs text-muted-foreground">
                            {session.native_proxy.statements.total}{' '}
                            {session.native_proxy.statements.total === 1
                                ? 'statement'
                                : 'statements'}
                        </span>
                    </div>
                </CardHeader>
                <CardContent className="p-0">
                    <div>
                        {session.native_proxy.statements.data.map(
                            (statement) => (
                                <div
                                    key={statement.id}
                                    className="grid gap-2 border-b px-4 py-3 text-sm last:border-0 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:items-center sm:px-6"
                                >
                                    <div className="flex gap-2">
                                        <StatusBadge value={statement.status} />
                                        <StatusBadge
                                            value={statement.query_type}
                                        />
                                    </div>
                                    <code className="truncate text-xs text-muted-foreground">
                                        {statement.protocol_command ??
                                            'statement'}{' '}
                                        ·{' '}
                                        {statement.sql_fingerprint ??
                                            'no fingerprint'}{' '}
                                        · {statement.parameter_count} parameter
                                        {statement.parameter_count === 1
                                            ? ''
                                            : 's'}
                                    </code>
                                    <span className="text-xs text-muted-foreground">
                                        {statement.row_count ?? 0} rows ·{' '}
                                        {statement.duration_ms ?? 0} ms
                                    </span>
                                </div>
                            ),
                        )}
                        {session.native_proxy.statements.data.length === 0 && (
                            <p className="p-4 text-sm text-muted-foreground sm:px-6">
                                Statements appear here after the database client
                                starts using this connection.
                            </p>
                        )}
                    </div>
                    <Pagination pagination={session.native_proxy.statements} />
                </CardContent>
            </Card>
        </div>
    );
}

type SetupStepStatus = 'complete' | 'current' | 'upcoming' | 'blocked';

function SetupStep({
    number,
    title,
    status,
    children,
}: {
    number: number;
    title: string;
    status: SetupStepStatus;
    children: ReactNode;
}) {
    const statusLabel =
        status === 'complete'
            ? 'Complete'
            : status === 'current'
              ? 'Next'
              : status === 'blocked'
                ? 'Unavailable'
                : 'Then';

    return (
        <li
            aria-current={status === 'current' ? 'step' : undefined}
            className="grid grid-cols-[2rem_minmax(0,1fr)] gap-3 px-4 py-5 sm:gap-4 sm:px-6"
        >
            <div
                className={`flex size-8 items-center justify-center rounded-full border text-xs font-semibold ${
                    status === 'complete'
                        ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/70 dark:bg-emerald-950/40 dark:text-emerald-300'
                        : status === 'current'
                          ? 'border-primary bg-primary text-primary-foreground'
                          : status === 'blocked'
                            ? 'border-destructive/30 bg-destructive/10 text-destructive'
                            : 'border-border bg-muted/50 text-muted-foreground'
                }`}
                aria-hidden="true"
            >
                {status === 'complete' ? <Check className="size-4" /> : number}
            </div>
            <div className="grid min-w-0 gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="font-semibold">{title}</h2>
                    <span
                        className={`text-xs font-medium ${
                            status === 'complete'
                                ? 'text-emerald-700 dark:text-emerald-300'
                                : status === 'current'
                                  ? 'text-primary'
                                  : status === 'blocked'
                                    ? 'text-destructive'
                                    : 'text-muted-foreground'
                        }`}
                    >
                        {statusLabel}
                    </span>
                </div>
                {children}
            </div>
        </li>
    );
}

function CredentialReveal({
    credential,
    onDismiss,
}: {
    credential: Credential;
    onDismiss: () => void;
}) {
    return (
        <div className="grid gap-3 border-t pt-4">
            <div className="grid gap-1">
                <h3 className="text-sm font-semibold">
                    Temporary credentials: shown once
                </h3>
                <p className="text-xs text-muted-foreground">
                    Copy these now. Crucible cannot show the password again;
                    rotate it if you need a replacement.
                </p>
            </div>
            <div className="grid gap-3">
                <CopyValue label="Username" value={credential.username} />
                <CopyValue label="Password" value={credential.password} />
                <div className="flex justify-start sm:justify-end">
                    <Button variant="outline" onClick={onDismiss}>
                        Dismiss and clear
                    </Button>
                </div>
            </div>
        </div>
    );
}

function CopyValue({ label, value }: { label: string; value: string }) {
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (!copied) {
            return;
        }

        const resetCopiedState = window.setTimeout(() => {
            setCopied(false);
        }, 2000);

        return () => window.clearTimeout(resetCopiedState);
    }, [copied]);

    async function copy(): Promise<void> {
        await navigator.clipboard.writeText(value);
        setCopied(true);
    }

    return (
        <div className="grid gap-1">
            <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </span>
            <div className="flex min-w-0 items-center gap-2 rounded-md border bg-muted/30 p-2">
                <code className="min-w-0 flex-1 truncate text-xs">{value}</code>
                <Button
                    size="icon"
                    variant="ghost"
                    onClick={copy}
                    aria-label={`Copy ${label}`}
                >
                    {copied ? <Check /> : <Copy />}
                </Button>
            </div>
        </div>
    );
}
