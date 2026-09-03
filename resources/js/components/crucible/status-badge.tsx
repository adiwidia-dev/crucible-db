import {
    badgeToneStyles,
    semanticToneForStatus,
} from '@/components/crucible/semantic-icon';
import { Badge } from '@/components/ui/badge';
import { statusLabel } from '@/lib/crucible';
import { cn } from '@/lib/utils';

type StatusBadgeProps = {
    value: string;
    label?: string;
    className?: string;
};

export function StatusBadge({ value, label, className }: StatusBadgeProps) {
    return (
        <Badge
            variant="outline"
            className={cn(
                'h-5 rounded-md px-1.5 text-[11px] font-medium shadow-none',
                badgeToneStyles[semanticToneForStatus(value)],
                className,
            )}
        >
            {label ?? statusLabel(value)}
        </Badge>
    );
}

export function SessionAccessBadge({
    mode,
    className,
}: {
    mode: 'read' | 'write' | null;
    className?: string;
}) {
    return <AccessModeBadge mode={mode ?? 'read'} className={className} />;
}

export function AccessModeBadge({
    mode,
    className,
}: {
    mode: 'none' | 'read' | 'write';
    className?: string;
}) {
    const presentation = {
        none: { value: 'disabled', label: 'Disabled' },
        read: { value: 'read_only', label: 'Read-only' },
        write: { value: 'read_write', label: 'Read + write' },
    }[mode];

    return (
        <StatusBadge
            value={presentation.value}
            label={presentation.label}
            className={className}
        />
    );
}
