import { Form, Head, Link } from '@inertiajs/react';
import { Edit3, KeyRound, Plus, Trash2 } from 'lucide-react';
import RoleController from '@/actions/App/Http/Controllers/RoleController';
import { DataRegistry } from '@/components/crucible/data-registry';
import { EmptyState } from '@/components/crucible/empty-state';
import { PageHeader } from '@/components/crucible/page-header';
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
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { create, edit, index } from '@/routes/roles';

type RoleRecord = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    is_admin: boolean;
    users_count: number;
    database_permissions_count: number;
    connection_group_policies_count: number;
};

type Props = {
    roles: RoleRecord[];
};

export default function RolesIndex({ roles }: Props) {
    return (
        <>
            <Head title="Roles" />

            <div className="crucible-page">
                <PageHeader
                    title="Roles"
                    description={`${roles.length} roles available for user assignment`}
                    actions={
                        <Button asChild>
                            <Link href={create()}>
                                <Plus />
                                New role
                            </Link>
                        </Button>
                    }
                />

                <DataRegistry
                    title="Role registry"
                    description="Manage custom roles used by the access matrix."
                >
                    {roles.length === 0 ? (
                        <div className="p-6">
                            <EmptyState
                                icon={KeyRound}
                                title="No custom roles yet"
                                detail="Create a role before assigning database policies to people."
                                action={
                                    <Button size="sm" asChild>
                                        <Link href={create()}>
                                            <Plus />
                                            New role
                                        </Link>
                                    </Button>
                                }
                            />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[720px] table-fixed text-sm">
                                <colgroup>
                                    <col className="w-[46%]" />
                                    <col className="w-[16%]" />
                                    <col className="w-[10%]" />
                                    <col className="w-[12%]" />
                                    <col className="w-[16%]" />
                                </colgroup>
                                <thead>
                                    <tr className="border-b bg-muted/45 text-left text-xs text-muted-foreground">
                                        <th className="py-2.5 pr-4 pl-3 font-medium sm:pl-4">
                                            Role
                                        </th>
                                        <th className="py-2.5 pr-4 font-medium">
                                            Type
                                        </th>
                                        <th className="py-2.5 pr-4 font-medium">
                                            Users
                                        </th>
                                        <th className="py-2.5 pr-4 font-medium">
                                            Policies
                                        </th>
                                        <th className="py-2.5 pr-3 text-right font-medium sm:pr-4">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {roles.map((role) => {
                                        const canDelete =
                                            !role.is_admin &&
                                            role.users_count === 0 &&
                                            role.database_permissions_count ===
                                                0 &&
                                            role.connection_group_policies_count ===
                                                0;

                                        return (
                                            <tr
                                                key={role.id}
                                                className="border-b transition-colors last:border-0 hover:bg-accent/40"
                                            >
                                                <td className="py-3 pr-4 pl-3 sm:pl-4">
                                                    <div className="font-medium">
                                                        {role.name}
                                                    </div>
                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                        {role.slug}
                                                    </div>
                                                    {role.description && (
                                                        <div className="mt-2 max-w-xl text-xs text-muted-foreground">
                                                            {role.description}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="py-3 pr-4">
                                                    <StatusBadge
                                                        value={
                                                            role.is_admin
                                                                ? 'active'
                                                                : 'none'
                                                        }
                                                        label={
                                                            role.is_admin
                                                                ? 'Admin'
                                                                : 'Custom'
                                                        }
                                                    />
                                                </td>
                                                <td className="py-3 pr-4">
                                                    <span className="tabular-nums">
                                                        {role.users_count}
                                                    </span>
                                                </td>
                                                <td className="py-3 pr-4">
                                                    <span className="tabular-nums">
                                                        {role.database_permissions_count +
                                                            role.connection_group_policies_count}
                                                    </span>
                                                    {role.connection_group_policies_count >
                                                        0 && (
                                                        <span className="ml-1.5 text-xs text-muted-foreground">
                                                            (
                                                            {
                                                                role.connection_group_policies_count
                                                            }{' '}
                                                            groups)
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="py-3 pr-3 sm:pr-4">
                                                    <div className="flex items-center justify-end gap-2">
                                                        {role.is_admin ? (
                                                            <Tooltip>
                                                                <TooltipTrigger
                                                                    asChild
                                                                >
                                                                    <span>
                                                                        <Button
                                                                            variant="outline"
                                                                            size="icon"
                                                                            disabled
                                                                            aria-label="System role cannot be edited"
                                                                        >
                                                                            <Edit3 />
                                                                        </Button>
                                                                    </span>
                                                                </TooltipTrigger>
                                                                <TooltipContent>
                                                                    System role
                                                                    cannot be
                                                                    edited
                                                                </TooltipContent>
                                                            </Tooltip>
                                                        ) : (
                                                            <Tooltip>
                                                                <TooltipTrigger
                                                                    asChild
                                                                >
                                                                    <span>
                                                                        <Button
                                                                            variant="outline"
                                                                            size="icon"
                                                                            asChild
                                                                        >
                                                                            <Link
                                                                                href={edit(
                                                                                    role.id,
                                                                                )}
                                                                                aria-label={`Edit ${role.name}`}
                                                                            >
                                                                                <Edit3 />
                                                                            </Link>
                                                                        </Button>
                                                                    </span>
                                                                </TooltipTrigger>
                                                                <TooltipContent>
                                                                    Edit role
                                                                </TooltipContent>
                                                            </Tooltip>
                                                        )}
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
                                                                                aria-label={`Delete ${role.name}`}
                                                                            >
                                                                                <Trash2 />
                                                                            </Button>
                                                                        </DialogTrigger>
                                                                    </span>
                                                                </TooltipTrigger>
                                                                <TooltipContent>
                                                                    {canDelete
                                                                        ? 'Delete role'
                                                                        : 'Detach users and policies before deleting'}
                                                                </TooltipContent>
                                                            </Tooltip>
                                                            <DialogContent>
                                                                <DialogHeader>
                                                                    <DialogTitle>
                                                                        Delete
                                                                        role?
                                                                    </DialogTitle>
                                                                    <DialogDescription>
                                                                        This
                                                                        removes{' '}
                                                                        {
                                                                            role.name
                                                                        }{' '}
                                                                        from
                                                                        role
                                                                        management.
                                                                        Roles
                                                                        with
                                                                        users or
                                                                        database
                                                                        policies
                                                                        must be
                                                                        detached
                                                                        first.
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
                                                                        {...RoleController.destroy.form(
                                                                            role.id,
                                                                        )}
                                                                        options={{
                                                                            preserveScroll: true,
                                                                        }}
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
                                                                                Yes,
                                                                                delete
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
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </DataRegistry>
            </div>
        </>
    );
}

RolesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Roles',
            href: index(),
        },
    ],
};
