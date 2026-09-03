import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

export type SemanticTone =
    'neutral' | 'info' | 'pending' | 'danger' | 'success' | 'write' | 'native';

const iconToneStyles: Record<SemanticTone, string> = {
    neutral: 'bg-muted text-muted-foreground',
    info: 'bg-sky-500/10 text-sky-700 dark:text-sky-300',
    pending: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
    danger: 'bg-red-500/10 text-red-700 dark:text-red-300',
    success: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    write: 'bg-orange-500/10 text-orange-700 dark:text-orange-300',
    native: 'bg-indigo-500/10 text-indigo-700 dark:text-indigo-300',
};

export const badgeToneStyles: Record<SemanticTone, string> = {
    neutral:
        'border-zinc-200 bg-zinc-50 text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-300',
    info: 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-900/70 dark:bg-sky-950/40 dark:text-sky-300',
    pending:
        'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/70 dark:bg-amber-950/40 dark:text-amber-300',
    danger: 'border-red-200 bg-red-50 text-red-800 dark:border-red-900/70 dark:bg-red-950/40 dark:text-red-300',
    success:
        'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/70 dark:bg-emerald-950/40 dark:text-emerald-300',
    write: 'border-orange-200 bg-orange-50 text-orange-900 dark:border-orange-900/70 dark:bg-orange-950/40 dark:text-orange-300',
    native: 'border-indigo-200 bg-indigo-50 text-indigo-800 dark:border-indigo-900/70 dark:bg-indigo-950/40 dark:text-indigo-300',
};

const statusTones: Record<string, SemanticTone> = {
    active: 'success',
    approved: 'info',
    cancelled: 'neutral',
    completed: 'success',
    disabled: 'neutral',
    draft: 'neutral',
    failed: 'danger',
    healthy: 'success',
    mysql: 'write',
    native_proxy: 'native',
    none: 'neutral',
    passed: 'success',
    pending: 'pending',
    pending_review: 'pending',
    pgsql: 'info',
    query_access: 'info',
    read: 'info',
    read_only: 'info',
    read_write: 'write',
    rejected: 'danger',
    running: 'info',
    scheduled: 'info',
    schedule_missed: 'pending',
    single_execution: 'info',
    succeeded: 'success',
    unhealthy: 'danger',
    version_mismatch: 'pending',
    write: 'write',
};

const iconSizeStyles = {
    sm: 'size-7 [&>svg]:size-4',
    md: 'size-9 [&>svg]:size-4',
    lg: 'size-10 [&>svg]:size-5',
};

export function semanticToneForStatus(value: string): SemanticTone {
    return statusTones[value] ?? 'neutral';
}

export function SemanticIcon({
    icon: Icon,
    tone = 'neutral',
    size = 'md',
    className,
}: {
    icon: LucideIcon;
    tone?: SemanticTone;
    size?: keyof typeof iconSizeStyles;
    className?: string;
}) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'inline-flex shrink-0 items-center justify-center rounded-md',
                iconSizeStyles[size],
                iconToneStyles[tone],
                className,
            )}
        >
            <Icon />
        </span>
    );
}
