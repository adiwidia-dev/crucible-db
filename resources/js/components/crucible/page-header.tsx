import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

type PageHeaderProps = {
    icon: LucideIcon;
    eyebrow?: string;
    title: string;
    description?: string;
    actions?: ReactNode;
    className?: string;
};

export function PageHeader({
    icon: Icon,
    eyebrow,
    title,
    description,
    actions,
    className,
}: PageHeaderProps) {
    return (
        <div
            className={cn(
                'flex flex-col gap-4 py-3 sm:flex-row sm:items-start sm:justify-between',
                className,
            )}
        >
            <div className="flex min-w-0 items-start gap-3">
                <div className="mt-1 flex size-8 shrink-0 items-center justify-center rounded-lg border bg-card text-orange-500 shadow-xs">
                    <Icon className="size-4.5" />
                </div>
                <div className="min-w-0">
                    {eyebrow && (
                        <p className="mb-1 text-sm font-semibold tracking-normal text-muted-foreground">
                            {eyebrow}
                        </p>
                    )}
                    <h1 className="truncate text-3xl font-semibold text-foreground">
                        {title}
                    </h1>
                    {description && (
                        <p className="mt-2 max-w-3xl text-base text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
            </div>
            {actions && (
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </div>
    );
}
