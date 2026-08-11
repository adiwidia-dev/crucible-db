import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

type EmptyStateProps = {
    icon: LucideIcon;
    title: string;
    detail?: string;
    action?: ReactNode;
};

export function EmptyState({
    icon: Icon,
    title,
    detail,
    action,
}: EmptyStateProps) {
    return (
        <div className="flex min-h-36 w-full flex-col items-center justify-center gap-3 rounded-lg border border-dashed bg-muted/20 px-6 py-8 text-center">
            <div className="flex size-9 items-center justify-center rounded-lg border bg-background text-muted-foreground shadow-xs">
                <Icon className="size-5" />
            </div>
            <div className="grid gap-1">
                <p className="text-sm font-medium">{title}</p>
                {detail && (
                    <p className="max-w-sm text-sm text-muted-foreground">
                        {detail}
                    </p>
                )}
            </div>
            {action}
        </div>
    );
}
