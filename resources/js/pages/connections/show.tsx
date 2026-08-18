import { Form, Head, Link } from '@inertiajs/react';
import {
    Database,
    Pencil,
    PlugZap,
    Plus,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import DatabaseConnectionController from '@/actions/App/Http/Controllers/DatabaseConnectionController';
import { PageHeader } from '@/components/crucible/page-header';
import { StatusBadge } from '@/components/crucible/status-badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
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
        requires_approval: boolean;
    }>;
};

type Props = {
    connection: Connection;
    can_update: boolean;
    can_create: boolean;
};

export default function ConnectionShow({
    connection,
    can_update,
    can_create,
}: Props) {
    return (
        <>
            <Head title={connection.name} />

            <div className="crucible-page">
                <PageHeader
                    icon={Database}
                    eyebrow="Connection"
                    title={connection.name}
                    description={`${connection.host}:${connection.port} / ${connection.database}`}
                    actions={
                        (can_create || can_update) && (
                            <>
                                {can_create && (
                                    <Button variant="outline" asChild>
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
                                            Add similar
                                        </Link>
                                    </Button>
                                )}
                                {can_update && (
                                    <>
                                        <Form
                                            {...DatabaseConnectionController.test.form(
                                                connection.id,
                                            )}
                                            options={{ preserveScroll: true }}
                                            onSuccess={() =>
                                                showFlashToast({
                                                    type: 'success',
                                                    message:
                                                        'Connection test succeeded.',
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
                                                    Test
                                                </Button>
                                            )}
                                        </Form>
                                        <Button asChild>
                                            <Link href={edit(connection.id)}>
                                                <Pencil />
                                                Edit
                                            </Link>
                                        </Button>
                                        <Dialog>
                                            <DialogTrigger asChild>
                                                <Button variant="destructive">
                                                    <Trash2 />
                                                    Delete
                                                </Button>
                                            </DialogTrigger>
                                            <DialogContent>
                                                <DialogHeader>
                                                    <DialogTitle>
                                                        Delete database
                                                        connection?
                                                    </DialogTitle>
                                                    <DialogDescription>
                                                        This removes{' '}
                                                        {connection.name} from
                                                        Crucible DB. Existing
                                                        audit history remains,
                                                        but users will no longer
                                                        be able to request or
                                                        run queries against this
                                                        connection.
                                                    </DialogDescription>
                                                </DialogHeader>
                                                <DialogFooter>
                                                    <DialogClose asChild>
                                                        <Button variant="outline">
                                                            Cancel
                                                        </Button>
                                                    </DialogClose>
                                                    <Form
                                                        {...DatabaseConnectionController.destroy.form(
                                                            connection.id,
                                                        )}
                                                        options={{
                                                            preserveScroll: true,
                                                        }}
                                                    >
                                                        {({ processing }) => (
                                                            <Button
                                                                variant="destructive"
                                                                disabled={
                                                                    processing
                                                                }
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
                                )}
                            </>
                        )
                    }
                />

                <Card>
                    <CardHeader className="border-b px-4 pb-4 sm:px-6">
                        <CardTitle>Connection Details</CardTitle>
                        <CardDescription>
                            Runtime target and transport configuration.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="pt-6">
                        <dl className="grid gap-3 text-sm md:grid-cols-3">
                            <div className="rounded-lg border bg-muted/25 p-4">
                                <dt className="text-muted-foreground">
                                    Driver
                                </dt>
                                <dd className="mt-2">
                                    <StatusBadge
                                        value={connection.driver}
                                        label={driverLabel(connection.driver)}
                                    />
                                </dd>
                            </div>
                            <div className="rounded-lg border bg-muted/25 p-4">
                                <dt className="text-muted-foreground">
                                    Endpoint
                                </dt>
                                <dd className="mt-2 font-mono text-xs font-medium">
                                    {connection.host}:{connection.port}
                                </dd>
                            </div>
                            <div className="rounded-lg border bg-muted/25 p-4">
                                <dt className="text-muted-foreground">
                                    Database
                                </dt>
                                <dd className="mt-2 font-medium">
                                    {connection.database}
                                </dd>
                            </div>
                            <div className="rounded-lg border bg-muted/25 p-4">
                                <dt className="text-muted-foreground">
                                    Username
                                </dt>
                                <dd className="mt-2 font-medium">
                                    {connection.username}
                                </dd>
                            </div>
                            <div className="rounded-lg border bg-muted/25 p-4">
                                <dt className="text-muted-foreground">
                                    SSL Mode
                                </dt>
                                <dd className="mt-2 font-medium">
                                    {connection.ssl_mode || 'Not set'}
                                </dd>
                            </div>
                            <div className="rounded-lg border bg-muted/25 p-4">
                                <dt className="text-muted-foreground">
                                    Status
                                </dt>
                                <dd className="mt-2">
                                    <StatusBadge
                                        value={
                                            connection.is_active
                                                ? 'active'
                                                : 'disabled'
                                        }
                                    />
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="border-b px-4 pb-4 sm:px-6">
                        <div className="flex items-center gap-2">
                            <ShieldCheck className="size-4 text-muted-foreground" />
                            <CardTitle>Role Access</CardTitle>
                        </div>
                        <CardDescription>
                            Effective access policy by role.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/40 text-left text-xs text-muted-foreground uppercase">
                                        <th className="py-3 pr-4 pl-4 font-medium sm:pl-6">
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
                                                <td className="py-3.5 pr-4 pl-4 font-medium sm:pl-6">
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
                                                            permission.requires_approval
                                                                ? 'pending_review'
                                                                : 'completed'
                                                        }
                                                        label={
                                                            permission.requires_approval
                                                                ? 'Required'
                                                                : 'Bypassed'
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
                    </CardContent>
                </Card>
            </div>
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
