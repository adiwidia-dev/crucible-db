import { Wifi } from 'lucide-react';
import {
    badgeToneStyles,
    semanticToneForStatus,
} from '@/components/crucible/semantic-icon';
import { StatusBadge } from '@/components/crucible/status-badge';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatDate, statusLabel } from '@/lib/crucible';
import { cn } from '@/lib/utils';

export type NativeProxyHealthSnapshot = {
    status: 'disabled' | 'healthy' | 'unhealthy' | 'version_mismatch';
    checked_at: string | null;
    proxy_id: string | null;
    version: string | null;
    message: string | null;
};

export type NativeProxyStatusSnapshot = {
    health: NativeProxyHealthSnapshot;
    connections: number;
    instances: number;
};

export function NativeProxyHealthStatus({
    health,
    connections,
    instances,
    timezone,
}: {
    health: NativeProxyHealthSnapshot;
    connections: number;
    instances: number;
    timezone: string;
}) {
    const tone = semanticToneForStatus(health.status);
    const label = statusLabel(health.status);

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    id="native-proxy-status"
                    variant="outline"
                    size="icon"
                    className={cn('size-8', badgeToneStyles[tone])}
                    aria-label={`Native proxy status: ${label}`}
                >
                    <Wifi />
                </Button>
            </TooltipTrigger>
            <TooltipContent
                side="bottom"
                align="end"
                sideOffset={8}
                className="w-80 max-w-[calc(100vw-2rem)] p-0"
            >
                <div className="flex items-center justify-between gap-3 px-3.5 pt-3 pb-2">
                    <span className="font-semibold">Native proxy</span>
                    <StatusBadge value={health.status} />
                </div>
                <p className="px-3.5 pb-3 text-primary-foreground/75">
                    {health.message ?? 'Ready for native client connections.'}
                </p>
                <dl className="grid grid-cols-2 border-t border-primary-foreground/15">
                    <div className="border-r border-b border-primary-foreground/15 px-3.5 py-2.5">
                        <dt className="text-primary-foreground/65">
                            Connections
                        </dt>
                        <dd className="mt-0.5 font-semibold">{connections}</dd>
                    </div>
                    <div className="border-b border-primary-foreground/15 px-3.5 py-2.5">
                        <dt className="text-primary-foreground/65">
                            Instances
                        </dt>
                        <dd className="mt-0.5 font-semibold">{instances}</dd>
                    </div>
                    <div className="border-r border-primary-foreground/15 px-3.5 py-2.5">
                        <dt className="text-primary-foreground/65">Version</dt>
                        <dd className="mt-0.5 font-mono">
                            {health.version ?? 'Unreported'}
                        </dd>
                    </div>
                    <div className="px-3.5 py-2.5">
                        <dt className="text-primary-foreground/65">
                            Last checked
                        </dt>
                        <dd className="mt-0.5">
                            {formatDate(health.checked_at, timezone)}
                        </dd>
                    </div>
                </dl>
            </TooltipContent>
        </Tooltip>
    );
}
