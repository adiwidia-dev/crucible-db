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
        <div className="flex flex-col gap-3 border-t px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div className="text-muted-foreground">
                Showing {pagination.from ?? 0}-{pagination.to ?? 0} of{' '}
                {pagination.total}
            </div>
            <div className="flex flex-wrap gap-1.5">
                {pagination.links.map((link) => (
                    <Button
                        key={`${link.label}-${link.url ?? 'disabled'}`}
                        variant={link.active ? 'default' : 'outline'}
                        size="sm"
                        disabled={link.url === null}
                        asChild={link.url !== null}
                    >
                        {link.url ? (
                            <Link
                                href={link.url}
                                preserveScroll
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
            </div>
        </div>
    );
}
