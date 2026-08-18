import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/lib/crucible';

type Props = {
    pagination: Pick<Paginated<unknown>, 'from' | 'to' | 'total' | 'links'>;
};

export function Pagination({ pagination }: Props) {
    if (pagination.links.length <= 3) {
        return null;
    }

    return (
        <div className="flex flex-col gap-3 border-t bg-card px-4 py-2.5 text-sm sm:flex-row sm:items-center sm:justify-between">
            <div className="text-xs text-muted-foreground tabular-nums">
                Showing {pagination.from ?? 0}–{pagination.to ?? 0} of{' '}
                {pagination.total} results
            </div>
            <nav
                aria-label="Pagination"
                className="flex flex-wrap items-center gap-1"
            >
                {pagination.links.map((link) => (
                    <Button
                        key={`${link.label}-${link.url ?? 'disabled'}`}
                        variant={link.active ? 'secondary' : 'ghost'}
                        size="sm"
                        className={
                            link.active
                                ? 'border border-border bg-secondary text-foreground hover:bg-secondary'
                                : 'text-muted-foreground'
                        }
                        disabled={link.url === null}
                        asChild={link.url !== null}
                    >
                        {link.url ? (
                            <Link
                                href={link.url}
                                preserveScroll
                                aria-label={link.label.replace(
                                    /&laquo;|&raquo;/g,
                                    '',
                                )}
                                dangerouslySetInnerHTML={{
                                    __html: link.label,
                                }}
                            />
                        ) : (
                            <span
                                dangerouslySetInnerHTML={{
                                    __html: link.label,
                                }}
                            />
                        )}
                    </Button>
                ))}
            </nav>
        </div>
    );
}
