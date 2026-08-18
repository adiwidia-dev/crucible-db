import type { ReactNode } from 'react';

type DataRegistryProps = {
    title: string;
    description?: string;
    actions?: ReactNode;
    children: ReactNode;
};

export function DataRegistry({
    title,
    description,
    actions,
    children,
}: DataRegistryProps) {
    return (
        <section className="min-w-0 overflow-hidden border-y bg-card sm:rounded-lg sm:border">
            <div className="flex flex-wrap items-start justify-between gap-3 border-b px-4 py-3 sm:px-5">
                <div className="min-w-0">
                    <h2 className="text-sm font-semibold">{title}</h2>
                    {description && (
                        <p className="mt-1 text-xs text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
                {actions && (
                    <div className="flex shrink-0 items-center gap-2">
                        {actions}
                    </div>
                )}
            </div>
            {children}
        </section>
    );
}
