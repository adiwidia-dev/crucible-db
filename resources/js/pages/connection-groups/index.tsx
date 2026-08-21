import { Form, Head, Link } from '@inertiajs/react';
import {
    Database,
    Edit3,
    FolderTree,
    Plus,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import ConnectionGroupController from '@/actions/App/Http/Controllers/ConnectionGroupController';
import { DataRegistry } from '@/components/crucible/data-registry';
import { EmptyState } from '@/components/crucible/empty-state';
import { PageHeader } from '@/components/crucible/page-header';
import { Button } from '@/components/ui/button';
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
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { create, edit, index } from '@/routes/connection-groups';

type ConnectionGroup = {
    id: number;
    name: string;
    description: string | null;
    database_connections_count: number;
    role_policies_count: number;
};

type Props = {
    connection_groups: ConnectionGroup[];
};

export default function ConnectionGroupsIndex({ connection_groups }: Props) {
    return (
        <>
            <Head title="Connection Groups" />

            <div className="crucible-page">
                <PageHeader
                    icon={FolderTree}
                    title="Connection Groups"
                    description={`${connection_groups.length} groups organize explicit access scopes`}
                    actions={
                        <Button asChild>
                            <Link href={create()}>
                                <Plus />
                                New group
                            </Link>
                        </Button>
                    }
                />

                <DataRegistry
                    title="Group registry"
                    description="Group explicit database targets before applying an access policy to a role."
                >
                    {connection_groups.length === 0 ? (
                        <div className="p-6">
                            <EmptyState
                                icon={FolderTree}
                                title="No connection groups yet"
                                detail="Create a group to apply one role policy across a selected set of database connections."
                                action={
                                    <Button size="sm" asChild>
                                        <Link href={create()}>
                                            <Plus />
                                            New group
                                        </Link>
                                    </Button>
                                }
                            />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[700px] text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/45 text-left text-xs text-muted-foreground">
                                        <th className="py-2.5 pr-4 pl-3 font-medium sm:pl-4">
                                            Connection group
                                        </th>
                                        <th className="py-2.5 pr-4 font-medium">
                                            Connections
                                        </th>
                                        <th className="py-2.5 pr-4 font-medium">
                                            Roles using group
                                        </th>
                                        <th className="py-2.5 pr-3 text-right font-medium sm:pr-4">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {connection_groups.map(
                                        (connectionGroup) => {
                                            const canDelete =
                                                connectionGroup.role_policies_count ===
                                                0;

                                            return (
                                                <tr
                                                    key={connectionGroup.id}
                                                    className="border-b transition-colors last:border-0 hover:bg-accent/40"
                                                >
                                                    <td className="py-3 pr-4 pl-3 sm:pl-4">
                                                        <div className="flex items-center gap-3">
                                                            <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                                                                <FolderTree className="size-4" />
                                                            </span>
                                                            <div className="min-w-0">
                                                                <div className="font-medium">
                                                                    {
                                                                        connectionGroup.name
                                                                    }
                                                                </div>
                                                                {connectionGroup.description && (
                                                                    <p className="mt-1 max-w-xl text-xs text-muted-foreground">
                                                                        {
                                                                            connectionGroup.description
                                                                        }
                                                                    </p>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td className="py-3 pr-4">
                                                        <span className="inline-flex items-center gap-1.5 tabular-nums">
                                                            <Database className="size-3.5 text-muted-foreground" />
                                                            {
                                                                connectionGroup.database_connections_count
                                                            }
                                                        </span>
                                                    </td>
                                                    <td className="py-3 pr-4">
                                                        <span className="inline-flex items-center gap-1.5 tabular-nums">
                                                            <ShieldCheck className="size-3.5 text-muted-foreground" />
                                                            {
                                                                connectionGroup.role_policies_count
                                                            }
                                                        </span>
                                                    </td>
                                                    <td className="py-3 pr-3 sm:pr-4">
                                                        <div className="flex justify-end gap-2">
                                                            <Tooltip>
                                                                <TooltipTrigger
                                                                    asChild
                                                                >
                                                                    <Button
                                                                        variant="outline"
                                                                        size="icon"
                                                                        asChild
                                                                    >
                                                                        <Link
                                                                            href={edit(
                                                                                connectionGroup.id,
                                                                            )}
                                                                            aria-label={`Edit ${connectionGroup.name}`}
                                                                        >
                                                                            <Edit3 />
                                                                        </Link>
                                                                    </Button>
                                                                </TooltipTrigger>
                                                                <TooltipContent>
                                                                    Edit group
                                                                </TooltipContent>
                                                            </Tooltip>
                                                            <Dialog>
                                                                <Tooltip>
                                                                    <TooltipTrigger
                                                                        asChild
                                                                    >
                                                                        <span>
                                                                            <DialogTrigger
                                                                                asChild
                                                                            >
                                                                                <Button
                                                                                    variant="destructive"
                                                                                    size="icon"
                                                                                    disabled={
                                                                                        !canDelete
                                                                                    }
                                                                                    aria-label={`Delete ${connectionGroup.name}`}
                                                                                >
                                                                                    <Trash2 />
                                                                                </Button>
                                                                            </DialogTrigger>
                                                                        </span>
                                                                    </TooltipTrigger>
                                                                    <TooltipContent>
                                                                        {canDelete
                                                                            ? 'Delete group'
                                                                            : 'Remove the group from role policies before deleting'}
                                                                    </TooltipContent>
                                                                </Tooltip>
                                                                <DialogContent>
                                                                    <DialogHeader>
                                                                        <DialogTitle>
                                                                            Delete
                                                                            connection
                                                                            group?
                                                                        </DialogTitle>
                                                                        <DialogDescription>
                                                                            This
                                                                            removes{' '}
                                                                            {
                                                                                connectionGroup.name
                                                                            }{' '}
                                                                            and
                                                                            its
                                                                            explicit
                                                                            member
                                                                            list.
                                                                        </DialogDescription>
                                                                    </DialogHeader>
                                                                    <DialogFooter>
                                                                        <DialogClose
                                                                            asChild
                                                                        >
                                                                            <Button variant="outline">
                                                                                Cancel
                                                                            </Button>
                                                                        </DialogClose>
                                                                        <Form
                                                                            {...ConnectionGroupController.destroy.form(
                                                                                connectionGroup.id,
                                                                            )}
                                                                        >
                                                                            {({
                                                                                processing,
                                                                            }) => (
                                                                                <Button
                                                                                    variant="destructive"
                                                                                    disabled={
                                                                                        processing
                                                                                    }
                                                                                >
                                                                                    <Trash2 />
                                                                                    Delete
                                                                                    group
                                                                                </Button>
                                                                            )}
                                                                        </Form>
                                                                    </DialogFooter>
                                                                </DialogContent>
                                                            </Dialog>
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        },
                                    )}
                                </tbody>
                            </table>
                        </div>
                    )}
                </DataRegistry>
            </div>
        </>
    );
}

ConnectionGroupsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Connection Groups',
            href: index(),
        },
    ],
};
