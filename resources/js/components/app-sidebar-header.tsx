import { Form, Link, usePage } from '@inertiajs/react';
import {
    Bell,
    BellOff,
    CheckCheck,
    CircleAlert,
    Info,
    MailCheck,
} from 'lucide-react';
import NotificationController from '@/actions/App/Http/Controllers/NotificationController';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { index as notificationsIndex } from '@/routes/notifications';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

type NotificationPreview = {
    id: string;
    severity: 'info' | 'success' | 'warning' | 'critical';
    title: string;
    message: string;
    url: string;
    read_at: string | null;
};

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const notificationSummary = usePage<{
        notification_summary?: {
            unread_count?: number;
            recent?: NotificationPreview[];
        };
    }>().props.notification_summary;
    const unreadCount = notificationSummary?.unread_count ?? 0;
    const recentNotifications = notificationSummary?.recent ?? [];

    return (
        <header className="flex h-13 shrink-0 items-center gap-3 border-b border-border bg-card px-4 sm:px-6 lg:px-8">
            <div className="flex min-w-0 items-center gap-3">
                <SidebarTrigger className="-ml-1 text-muted-foreground hover:text-foreground" />
                <div className="h-4 w-px bg-border" />
                {breadcrumbs.length > 0 ? (
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                ) : (
                    <span className="truncate text-sm font-medium text-muted-foreground">
                        Crucible DB
                    </span>
                )}
            </div>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="relative ml-auto size-8 text-muted-foreground hover:text-foreground"
                        aria-label={
                            unreadCount > 0
                                ? `${unreadCount} unread notifications`
                                : 'Notifications'
                        }
                    >
                        <Bell className="size-4" />
                        {unreadCount > 0 && (
                            <span className="absolute -top-0.5 -right-0.5 flex size-4 items-center justify-center rounded-full bg-destructive text-[9px] font-semibold text-destructive-foreground">
                                {unreadCount > 9 ? '9+' : unreadCount}
                            </span>
                        )}
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    sideOffset={8}
                    className="w-[min(19rem,calc(100vw-2rem))] p-0"
                >
                    <div className="flex items-center justify-between gap-3 border-b px-3.5 py-2.5">
                        <p className="text-sm font-semibold">Notifications</p>
                        <Form
                            {...NotificationController.markAllRead.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-7 px-1.5 text-xs font-medium text-primary hover:text-primary"
                                    disabled={processing || unreadCount === 0}
                                >
                                    <CheckCheck className="size-3.5" />
                                    Mark all read
                                </Button>
                            )}
                        </Form>
                    </div>

                    {recentNotifications.length === 0 ? (
                        <div className="flex items-center gap-2.5 px-3.5 py-4">
                            <BellOff className="size-4 shrink-0 text-muted-foreground" />
                            <div>
                                <p className="text-sm font-medium">
                                    No unread notifications
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    New operational updates appear here.
                                </p>
                            </div>
                        </div>
                    ) : (
                        <div className="max-h-[min(24rem,calc(100vh-10rem))] overflow-y-auto py-1">
                            {recentNotifications.map((notification) => (
                                <DropdownMenuItem
                                    key={notification.id}
                                    asChild
                                    className="items-start gap-3 rounded-none px-4 py-3 whitespace-normal"
                                >
                                    <Link href={notification.url}>
                                        <NotificationSeverityIcon
                                            severity={notification.severity}
                                        />
                                        <span className="min-w-0 flex-1">
                                            <span className="flex items-center gap-2">
                                                <span className="truncate text-sm font-medium">
                                                    {notification.title}
                                                </span>
                                                {notification.read_at ===
                                                    null && (
                                                    <span className="size-1.5 shrink-0 rounded-full bg-primary" />
                                                )}
                                            </span>
                                            <span className="mt-0.5 line-clamp-2 block text-xs leading-5 text-muted-foreground">
                                                {notification.message}
                                            </span>
                                        </span>
                                    </Link>
                                </DropdownMenuItem>
                            ))}
                        </div>
                    )}

                    <div className="border-t p-1.5">
                        <DropdownMenuItem
                            asChild
                            className="justify-center font-medium text-primary"
                        >
                            <Link href={notificationsIndex()}>
                                View history
                            </Link>
                        </DropdownMenuItem>
                    </div>
                </DropdownMenuContent>
            </DropdownMenu>
        </header>
    );
}

function NotificationSeverityIcon({
    severity,
}: {
    severity: NotificationPreview['severity'];
}) {
    const className = 'mt-0.5 size-4 shrink-0';

    if (severity === 'critical') {
        return <CircleAlert className={`${className} text-destructive`} />;
    }

    if (severity === 'warning') {
        return <CircleAlert className={`${className} text-amber-600`} />;
    }

    if (severity === 'success') {
        return <MailCheck className={`${className} text-emerald-600`} />;
    }

    return <Info className={`${className} text-primary`} />;
}
