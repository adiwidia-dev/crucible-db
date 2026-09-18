import { Head, usePage } from '@inertiajs/react';
import {
    Bot,
    Database,
    HeartPulse,
    Layers3,
    Network,
    ServerCog,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { PageHeader } from '@/components/crucible/page-header';
import {
    SemanticIcon,
    semanticToneForStatus,
} from '@/components/crucible/semantic-icon';
import { StatusBadge } from '@/components/crucible/status-badge';
import { formatDate } from '@/lib/crucible';

type Status = 'degraded' | 'disabled' | 'healthy' | 'unknown' | 'unhealthy';

type ComponentStatus = {
    status: Status;
    detail: string;
    metadata: Record<string, number | string | null>;
};

type SystemStatusSnapshot = {
    checked_at: string | null;
    components: Record<string, ComponentStatus>;
};

type RuntimeStatus = ComponentStatus & {
    metadata: {
        driver: string;
        php_version: string;
        version: string;
    };
};

const componentPresentation: Record<
    string,
    { title: string; icon: LucideIcon }
> = {
    database: { title: 'Control database', icon: Database },
    redis: { title: 'Redis', icon: Layers3 },
    horizon: { title: 'Horizon', icon: Bot },
    scheduler: { title: 'Scheduler', icon: HeartPulse },
    native_proxy: { title: 'Native proxy', icon: Network },
};

export default function SystemStatus({
    system_status: systemStatus,
    application_runtime: applicationRuntime,
}: {
    system_status: SystemStatusSnapshot;
    application_runtime: RuntimeStatus;
}) {
    const { auth } = usePage<{ auth: { user: { timezone?: string | null } } }>()
        .props;
    const timezone = auth.user.timezone ?? undefined;
    const statuses = [
        applicationRuntime,
        ...Object.values(systemStatus.components),
    ];
    const hasIssue = statuses.some((component) =>
        ['degraded', 'unhealthy', 'unknown'].includes(component.status),
    );

    return (
        <>
            <Head title="System status" />

            <div className="crucible-page space-y-6">
                <PageHeader
                    title="System status"
                    description="Read-only operational snapshots. Checks are refreshed by the scheduler; this page never initiates infrastructure probes."
                    actions={
                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                            <StatusBadge
                                value={hasIssue ? 'degraded' : 'healthy'}
                                label={
                                    hasIssue ? 'Needs attention' : 'Operational'
                                }
                            />
                            <span>
                                Checked{' '}
                                {formatDate(systemStatus.checked_at, timezone)}
                            </span>
                        </div>
                    }
                />

                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <StatusCard
                        icon={ServerCog}
                        title="Application runtime"
                        status={applicationRuntime}
                        details={[
                            ['Version', applicationRuntime.metadata.version],
                            ['Runtime', applicationRuntime.metadata.driver],
                            ['PHP', applicationRuntime.metadata.php_version],
                        ]}
                    />
                    {Object.entries(systemStatus.components).map(
                        ([key, status]) => {
                            const presentation = componentPresentation[key];

                            if (!presentation) {
                                return null;
                            }

                            return (
                                <StatusCard
                                    key={key}
                                    icon={presentation.icon}
                                    title={presentation.title}
                                    status={status}
                                    details={metadataDetails(
                                        key,
                                        status.metadata,
                                        timezone,
                                    )}
                                />
                            );
                        },
                    )}
                </section>

                <p className="text-sm text-muted-foreground">
                    A status of{' '}
                    <span className="font-medium text-foreground">Unknown</span>{' '}
                    means no recent snapshot is available. It is not treated as
                    healthy.
                </p>
            </div>
        </>
    );
}

function StatusCard({
    icon,
    title,
    status,
    details,
}: {
    icon: LucideIcon;
    title: string;
    status: ComponentStatus;
    details: Array<[string, number | string | null]>;
}) {
    return (
        <article className="flex min-h-52 flex-col gap-5 rounded-xl border bg-card p-5 shadow-sm">
            <div className="flex items-start justify-between gap-3">
                <div className="flex items-center gap-3">
                    <SemanticIcon
                        icon={icon}
                        tone={semanticToneForStatus(status.status)}
                    />
                    <h2 className="font-semibold text-foreground">{title}</h2>
                </div>
                <StatusBadge value={status.status} />
            </div>
            <p className="text-sm leading-6 text-muted-foreground">
                {status.detail}
            </p>
            {details.length > 0 && (
                <dl className="mt-auto grid gap-2 border-t pt-4 text-sm">
                    {details.map(([label, value]) => (
                        <div
                            key={label}
                            className="flex items-center justify-between gap-4"
                        >
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd className="truncate text-right font-medium text-foreground">
                                {value ?? 'Not reported'}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}
        </article>
    );
}

function metadataDetails(
    component: string,
    metadata: ComponentStatus['metadata'],
    timezone?: string,
): Array<[string, number | string | null]> {
    if (component === 'horizon') {
        return [
            ['Masters', metadata.masters],
            ['Supervisors', metadata.supervisors],
        ];
    }

    if (component === 'scheduler') {
        return [
            [
                'Last heartbeat',
                typeof metadata.last_heartbeat_at === 'string'
                    ? formatDate(metadata.last_heartbeat_at, timezone)
                    : null,
            ],
        ];
    }

    if (component === 'native_proxy') {
        return [
            ['Proxy', metadata.proxy_id],
            ['Version', metadata.version],
        ];
    }

    return [];
}
