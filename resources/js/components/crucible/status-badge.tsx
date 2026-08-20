import { Badge } from '@/components/ui/badge';
import { statusLabel } from '@/lib/crucible';
import { cn } from '@/lib/utils';

type StatusBadgeProps = {
    value: string;
    label?: string;
    className?: string;
};

const statusStyles: Record<string, string> = {
    active: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/70 dark:bg-emerald-950/40 dark:text-emerald-300',
    approved:
        'border-cyan-200 bg-cyan-50 text-cyan-800 dark:border-cyan-900/70 dark:bg-cyan-950/40 dark:text-cyan-300',
    cancelled:
        'border-zinc-200 bg-zinc-50 text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-300',
    completed:
        'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/70 dark:bg-emerald-950/40 dark:text-emerald-300',
    disabled:
        'border-zinc-200 bg-zinc-50 text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-300',
    failed: 'border-red-200 bg-red-50 text-red-800 dark:border-red-900/70 dark:bg-red-950/40 dark:text-red-300',
    none: 'border-zinc-200 bg-zinc-50 text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-300',
    pending_review:
        'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/70 dark:bg-amber-950/40 dark:text-amber-300',
    mysql: 'border-orange-200 bg-orange-50 text-orange-900 dark:border-orange-900/70 dark:bg-orange-950/40 dark:text-orange-300',
    pgsql: 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-900/70 dark:bg-sky-950/40 dark:text-sky-300',
    read: 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-900/70 dark:bg-sky-950/40 dark:text-sky-300',
    read_only:
        'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-900/70 dark:bg-sky-950/40 dark:text-sky-300',
    read_write:
        'border-orange-200 bg-orange-50 text-orange-900 dark:border-orange-900/70 dark:bg-orange-950/40 dark:text-orange-300',
    rejected:
        'border-red-200 bg-red-50 text-red-800 dark:border-red-900/70 dark:bg-red-950/40 dark:text-red-300',
    running:
        'border-cyan-200 bg-cyan-50 text-cyan-800 dark:border-cyan-900/70 dark:bg-cyan-950/40 dark:text-cyan-300',
    scheduled:
        'border-indigo-200 bg-indigo-50 text-indigo-800 dark:border-indigo-900/70 dark:bg-indigo-950/40 dark:text-indigo-300',
    schedule_missed:
        'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/70 dark:bg-amber-950/40 dark:text-amber-300',
    succeeded:
        'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/70 dark:bg-emerald-950/40 dark:text-emerald-300',
    write: 'border-orange-200 bg-orange-50 text-orange-900 dark:border-orange-900/70 dark:bg-orange-950/40 dark:text-orange-300',
};

export function StatusBadge({ value, label, className }: StatusBadgeProps) {
    return (
        <Badge
            variant="outline"
            className={cn(
                'h-5 rounded-md px-1.5 text-[11px] font-medium shadow-none',
                statusStyles[value] ?? statusStyles.none,
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
    const canWrite = mode === 'write';

    return (
        <StatusBadge
            value={canWrite ? 'read_write' : 'read_only'}
            label={canWrite ? 'Read + write' : 'Read-only'}
            className={className}
        />
    );
}
