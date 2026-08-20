import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    BellOff,
    CheckCheck,
    CircleAlert,
    Info,
    MailCheck,
} from 'lucide-react';
import NotificationController from '@/actions/App/Http/Controllers/NotificationController';
import { EmptyState } from '@/components/crucible/empty-state';
import { PageHeader } from '@/components/crucible/page-header';
import { Pagination } from '@/components/crucible/pagination';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/crucible';
import type { Paginated } from '@/lib/crucible';
import { index } from '@/routes/notifications';
import type { Auth } from '@/types';

type NotificationItem = {
    id: string;
    event: string;
    severity: 'info' | 'success' | 'warning' | 'critical';
    title: string;
    message: string;
    action_label: string;
    url: string;
    created_at: string | null;
    read_at: string | null;
};

export default function NotificationsIndex({
    notifications,
}: {
    notifications: Paginated<NotificationItem>;
}) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const hasUnread = notifications.data.some(
        (notification) => notification.read_at === null,
    );

    return (
        <>
            <Head title="Notifications" />

            <div className="crucible-page">
                <PageHeader
                    title="Notifications"
                    description="Operational updates for requests, query access, and connection health."
                    actions={
                        hasUnread ? (
                            <Form
                                {...NotificationController.markAllRead.form()}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        <CheckCheck />
                                        Mark all read
                                    </Button>
                                )}
                            </Form>
                        ) : undefined
                    }
                />

                <section className="overflow-hidden border-y bg-card sm:rounded-lg sm:border">
                    {notifications.data.length === 0 ? (
                        <div className="px-4 py-10 sm:px-5">
                            <EmptyState
                                icon={BellOff}
                                title="You are all caught up"
                                detail="Approvals, execution outcomes, session changes, and connection failures will appear here."
                            />
                        </div>
                    ) : (
                        <div className="divide-y">
                            {notifications.data.map((notification) => (
                                <article
                                    key={notification.id}
                                    className={`flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-start sm:justify-between sm:px-5 ${
                                        notification.read_at === null
                                            ? 'bg-primary/[0.035]'
                                            : ''
                                    }`}
                                >
                                    <div className="flex min-w-0 gap-3">
                                        <SeverityIcon
                                            severity={notification.severity}
                                        />
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                <h2 className="text-sm font-semibold">
                                                    {notification.title}
                                                </h2>
                                                {notification.read_at ===
                                                    null && (
                                                    <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary">
                                                        New
                                                    </span>
                                                )}
                                            </div>
                                            <p className="mt-1 text-sm leading-5 text-muted-foreground">
                                                {notification.message}
                                            </p>
                                            <p className="mt-1.5 text-xs text-muted-foreground">
                                                {formatDate(
                                                    notification.created_at,
                                                    auth.user.timezone ?? 'UTC',
                                                )}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2 sm:pt-0.5">
                                        <Button
                                            asChild
                                            size="sm"
                                            variant="outline"
                                        >
                                            <Link href={notification.url}>
                                                {notification.action_label}
                                            </Link>
                                        </Button>
                                        {notification.read_at === null && (
                                            <Form
                                                {...NotificationController.markRead.form(
                                                    notification.id,
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        disabled={processing}
                                                    >
                                                        Mark read
                                                    </Button>
                                                )}
                                            </Form>
                                        )}
                                    </div>
                                </article>
                            ))}
                        </div>
                    )}
                </section>

                <Pagination pagination={notifications} />
            </div>
        </>
    );
}

function SeverityIcon({
    severity,
}: {
    severity: NotificationItem['severity'];
}) {
    const iconClass = 'mt-0.5 size-4 shrink-0';

    if (severity === 'critical') {
        return <CircleAlert className={`${iconClass} text-destructive`} />;
    }

    if (severity === 'warning') {
        return <CircleAlert className={`${iconClass} text-amber-600`} />;
    }

    if (severity === 'success') {
        return <MailCheck className={`${iconClass} text-emerald-600`} />;
    }

    return <Info className={`${iconClass} text-primary`} />;
}

NotificationsIndex.layout = {
    breadcrumbs: [{ title: 'Notifications', href: index() }],
};
