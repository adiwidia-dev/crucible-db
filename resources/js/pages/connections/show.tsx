import { Form, Head, Link } from '@inertiajs/react';
import {
    Database,
    BellOff,
    BellRing,
    MoreHorizontal,
    Pencil,
    PlugZap,
    Plus,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import DatabaseConnectionController from '@/actions/App/Http/Controllers/DatabaseConnectionController';
import NotificationSubscriptionController from '@/actions/App/Http/Controllers/NotificationSubscriptionController';
import { StatusBadge } from '@/components/crucible/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { showFlashToast } from '@/hooks/use-flash-toast';
import { driverLabel, statusLabel } from '@/lib/crucible';
import { create, edit, index } from '@/routes/connections';

type Connection = {
    id: number;
    name: string;
    driver: string;
    host: string;
    port: number;
    database: string;
    username: string;
    ssl_mode: string | null;
    is_active: boolean;
    permissions: Array<{
        id: number;
        role: string;
        access_mode: string;
        can_review: boolean;
        read_requires_approval: boolean;
        write_requires_approval: boolean;
    }>;
};

type Props = {
    connection: Connection;
    can_update: boolean;
    can_create: boolean;
    is_subscribed: boolean;
};

export default function ConnectionShow({
    connection,
    can_update,
    can_create,
    is_subscribed,
}: Props) {
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const requiresApproval = connection.permissions.some(
        (permission) =>
            permission.read_requires_approval ||
            permission.write_requires_approval,
    );
    const policySummary =
        connection.permissions.length === 0
            ? 'No roles have access to this connection.'
            : `${connection.permissions.length} ${
                  connection.permissions.length === 1 ? 'role' : 'roles'
              } configured${requiresApproval ? ' · approval required' : ''}.`;

    return (
        <>
            <Head title={connection.name} />

            <div className="crucible-page">
                <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <Database className="size-5 text-muted-foreground" />
                            <h1 className="text-2xl leading-tight font-semibold tracking-[-0.025em] text-foreground">
                                {connection.name}
                            </h1>
                            <StatusBadge
                                value={connection.driver}
                                label={driverLabel(connection.driver)}
                            />
                            <StatusBadge
                                value={
                                    connection.is_active ? 'active' : 'disabled'
                                }
                            />
                        </div>
                        <p className="mt-2 font-mono text-xs text-muted-foreground">
                            {connection.host}:{connection.port}
                            <span className="px-2 text-border">·</span>
                            {connection.database}
                        </p>
                    </div>

                    <div className="flex shrink-0 flex-wrap items-center gap-2">
                        {is_subscribed ? (
                            <Form
                                {...NotificationSubscriptionController.destroyDatabaseConnection.form(
                                    connection.id,
                                )}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        variant="secondary"
                                        disabled={processing}
                                    >
                                        <BellOff />
                                        Watching
                                    </Button>
                                )}
                            </Form>
                        ) : (
                            <Form
                                {...NotificationSubscriptionController.storeDatabaseConnection.form(
                                    connection.id,
                                )}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        <BellRing />
                                        Watch health
                                    </Button>
                                )}
                            </Form>
                        )}
                        {can_update && (
                            <Form
                                {...DatabaseConnectionController.test.form(
                                    connection.id,
                                )}
                                options={{ preserveScroll: true }}
                                onSuccess={() =>
                                    showFlashToast({
                                        type: 'success',
                                        message: 'Connection test succeeded.',
                                    })
                                }
                                onError={() =>
                                    showFlashToast({
                                        type: 'error',
                                        message:
                                            'Connection test failed. Check the connection settings and audit log.',
                                    })
                                }
                            >
                                {({ processing }) => (
                                    <Button
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        <PlugZap />
                                        Test connection
                                    </Button>
                                )}
                            </Form>
                        )}
                        {can_update && (
                            <Button asChild>
                                <Link href={edit(connection.id)}>
                                    <Pencil />
                                    Edit
                                </Link>
                            </Button>
                        )}
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="outline">
                                    <MoreHorizontal />
                                    More
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                align="end"
                                sideOffset={8}
                                className="w-64"
                            >
                                {can_create && (
                                    <DropdownMenuItem
                                        asChild
                                        className="min-h-10 px-3 font-medium"
                                    >
                                        <Link
                                            href={create({
                                                query: {
                                                    driver: connection.driver,
                                                    host: connection.host,
                                                    port: connection.port,
                                                    ssl_mode:
                                                        connection.ssl_mode,
                                                },
                                            })}
                                        >
                                            <Plus />
                                            Add similar connection
                                        </Link>
                                    </DropdownMenuItem>
                                )}
                                {can_create && can_update && (
                                    <DropdownMenuSeparator />
                                )}
                                {can_update && (
                                    <DropdownMenuItem
                                        variant="destructive"
                                        className="min-h-10 px-3 font-medium"
                                        onSelect={() =>
                                            setDeleteDialogOpen(true)
                                        }
                                    >
                                        <Trash2 />
                                        Delete connection
                                    </DropdownMenuItem>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </header>

                <section className="max-w-6xl overflow-hidden border-y bg-card sm:rounded-lg sm:border">
                    <div className="border-b px-4 py-3 sm:px-5">
                        <h2 className="text-base font-semibold">
                            Connection configuration
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Target endpoint, database, and transport settings.
                        </p>
                    </div>
                    <div className="grid gap-8 px-4 py-5 sm:px-5 lg:grid-cols-2">
                        <div>
                            <h3 className="text-xs font-semibold tracking-[0.04em] text-muted-foreground uppercase">
                                Target
                            </h3>
                            <dl className="mt-3 divide-y border-y text-sm">
                                <div className="grid grid-cols-[9rem_minmax(0,1fr)] gap-4 py-3">
                                    <dt className="text-muted-foreground">
                                        Endpoint
                                    </dt>
                                    <dd className="min-w-0 font-mono text-xs font-medium break-all">
                                        {connection.host}:{connection.port}
                                    </dd>
                                </div>
                                <div className="grid grid-cols-[9rem_minmax(0,1fr)] gap-4 py-3">
                                    <dt className="text-muted-foreground">
                                        Database
                                    </dt>
                                    <dd className="min-w-0 font-mono text-xs font-medium break-all">
                                        {connection.database}
                                    </dd>
                                </div>
                                <div className="grid grid-cols-[9rem_minmax(0,1fr)] gap-4 py-3">
                                    <dt className="text-muted-foreground">
                                        Driver
                                    </dt>
                                    <dd>
                                        <StatusBadge
                                            value={connection.driver}
                                            label={driverLabel(
                                                connection.driver,
                                            )}
                                        />
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <div>
                            <h3 className="text-xs font-semibold tracking-[0.04em] text-muted-foreground uppercase">
                                Credentials and transport
                            </h3>
                            <dl className="mt-3 divide-y border-y text-sm">
                                <div className="grid grid-cols-[9rem_minmax(0,1fr)] gap-4 py-3">
                                    <dt className="text-muted-foreground">
                                        Username
                                    </dt>
                                    <dd className="min-w-0 font-mono text-xs font-medium break-all">
                                        {connection.username}
                                    </dd>
                                </div>
                                <div className="grid grid-cols-[9rem_minmax(0,1fr)] gap-4 py-3">
                                    <dt className="text-muted-foreground">
                                        SSL mode
                                    </dt>
                                    <dd className="font-medium">
                                        {connection.ssl_mode || 'Not set'}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </section>

                <section className="max-w-6xl overflow-hidden border-y bg-card sm:rounded-lg sm:border">
                    <div className="border-b px-4 py-3 sm:px-5">
                        <div className="flex items-center gap-2">
                            <ShieldCheck className="size-4 text-muted-foreground" />
                            <h2 className="text-base font-semibold">
                                Access policy
                            </h2>
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {policySummary}
                        </p>
                    </div>
                    <div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[620px] text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/45 text-left text-xs text-muted-foreground">
                                        <th className="py-2.5 pr-4 pl-4 font-medium sm:pl-5">
                                            Role
                                        </th>
                                        <th className="py-3 pr-4 font-medium">
                                            Access
                                        </th>
                                        <th className="py-3 pr-4 font-medium">
                                            Review
                                        </th>
                                        <th className="py-3 pr-4 font-medium">
                                            Approval
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {connection.permissions.map(
                                        (permission) => (
                                            <tr
                                                key={permission.id}
                                                className="border-b transition-colors last:border-0 hover:bg-accent/40"
                                            >
                                                <td className="py-3 pr-4 pl-4 font-medium sm:pl-5">
                                                    {permission.role}
                                                </td>
                                                <td className="py-3.5 pr-4">
                                                    <StatusBadge
                                                        value={
                                                            permission.access_mode
                                                        }
                                                        label={statusLabel(
                                                            permission.access_mode,
                                                        )}
                                                    />
                                                </td>
                                                <td className="py-3.5 pr-4">
                                                    <StatusBadge
                                                        value={
                                                            permission.can_review
                                                                ? 'approved'
                                                                : 'none'
                                                        }
                                                        label={
                                                            permission.can_review
                                                                ? 'Can review'
                                                                : 'No review'
                                                        }
                                                    />
                                                </td>
                                                <td className="py-3.5 pr-4">
                                                    <StatusBadge
                                                        value={
                                                            permission.read_requires_approval
                                                                ? 'pending_review'
                                                                : 'completed'
                                                        }
                                                        label={
                                                            permission.read_requires_approval
                                                                ? 'Read approval'
                                                                : 'Read direct'
                                                        }
                                                    />
                                                    <StatusBadge
                                                        value={
                                                            permission.write_requires_approval
                                                                ? 'pending_review'
                                                                : 'completed'
                                                        }
                                                        label={
                                                            permission.write_requires_approval
                                                                ? 'Write approval'
                                                                : 'Write direct'
                                                        }
                                                    />
                                                </td>
                                            </tr>
                                        ),
                                    )}
                                    {connection.permissions.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={4}
                                                className="py-10 text-center text-muted-foreground"
                                            >
                                                No role permissions assigned.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>

            <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete database connection?</DialogTitle>
                        <DialogDescription>
                            This removes {connection.name} from Crucible DB.
                            Existing audit history remains, but users will no
                            longer be able to request or run queries against
                            this connection.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>
                        <Form
                            {...DatabaseConnectionController.destroy.form(
                                connection.id,
                            )}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    <Trash2 />
                                    Yes, delete
                                </Button>
                            )}
                        </Form>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

ConnectionShow.layout = {
    breadcrumbs: [
        {
            title: 'Connections',
            href: index(),
        },
    ],
};
