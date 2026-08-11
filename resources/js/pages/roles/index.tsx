import { Form, Head, Link } from '@inertiajs/react';
import { Edit3, Plus, Trash2 } from 'lucide-react';
import RoleController from '@/actions/App/Http/Controllers/RoleController';
import { StatusBadge } from '@/components/crucible/status-badge';
import Heading from '@/components/heading';
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
};

type Props = {
    roles: RoleRecord[];
};

export default function RolesIndex({ roles }: Props) {
    return (
        <>
            <Head title="Roles" />

            <div className="space-y-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <Heading
                        variant="small"
                        title="Roles"
                        description={`${roles.length} roles available for user assignment`}
                    />
                    <Button asChild>
                        <Link href={create()}>
                            <Plus />
                            New role
                        </Link>
                    </Button>
                </div>

                <Card>
                    <CardHeader className="border-b px-4 pb-4 sm:px-6">
                        <CardTitle>Role Registry</CardTitle>
                        <CardDescription>
                            Manage non-admin roles used by the access matrix.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="p-0">
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
                                    <tr className="border-b bg-muted/40 text-left text-xs text-muted-foreground uppercase">
                                        <th className="py-3 pr-4 pl-4 font-medium sm:pl-6">
                                            Role
                                        </th>
                                        <th className="py-3 pr-4 font-medium">
                                            Type
                                        </th>
                                        <th className="py-3 pr-4 font-medium">
                                            Users
                                        </th>
                                        <th className="py-3 pr-4 font-medium">
                                            Policies
                                        </th>
                                        <th className="py-3 pr-4 font-medium">
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
                                                0;

                                        return (
                                            <tr
                                                key={role.id}
                                                className="border-b transition-colors last:border-0 hover:bg-accent/40"
                                            >
                                                <td className="py-3.5 pr-4 pl-4 sm:pl-6">
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
                                                <td className="py-3.5 pr-4">
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
                                                <td className="py-3.5 pr-4">
                                                    <span className="tabular-nums">
                                                        {role.users_count}
                                                    </span>
                                                </td>
                                                <td className="py-3.5 pr-4">
                                                    <span className="tabular-nums">
                                                        {
                                                            role.database_permissions_count
                                                        }
                                                    </span>
                                                </td>
                                                <td className="py-3.5 pr-4">
                                                    <div className="flex items-center gap-2">
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
                    </CardContent>
                </Card>
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
