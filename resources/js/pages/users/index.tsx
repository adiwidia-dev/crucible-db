import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Ban, Plus, Save, Undo2, UserCog } from 'lucide-react';
import { useState } from 'react';
import UserRoleController from '@/actions/App/Http/Controllers/UserRoleController';
import { StatusBadge } from '@/components/crucible/status-badge';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import { formatDate } from '@/lib/crucible';
import { create, index } from '@/routes/users';
import type { Auth } from '@/types';

type RoleOption = {
    id: number;
    name: string;
    slug: string;
    is_admin: boolean;
};

type AssignedRole = RoleOption & {
    priority: number;
};

type ManagedUser = {
    id: number;
    first_name: string | null;
    last_name: string | null;
    name: string;
    email: string;
    email_verified_at: string | null;
    invited_at: string | null;
    invitation_accepted_at: string | null;
    disabled_at: string | null;
    created_at: string | null;
    role_ids: number[];
    roles: AssignedRole[];
    is_current_user: boolean;
};

type Props = {
    users: ManagedUser[];
    active_users: ManagedUser[];
    disabled_users: ManagedUser[];
    roles: RoleOption[];
};

function RoleAssignmentForm({
    user,
    roles,
}: {
    user: ManagedUser;
    roles: RoleOption[];
}) {
    const [isOpen, setIsOpen] = useState(false);

    if (user.is_current_user) {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <span>
                        <Button
                            variant="outline"
                            size="icon"
                            disabled
                            aria-label="Current user role assignment is protected"
                        >
                            <UserCog />
                        </Button>
                    </span>
                </TooltipTrigger>
                <TooltipContent>
                    Current user role assignment is protected
                </TooltipContent>
            </Tooltip>
        );
    }

    return (
        <Dialog open={isOpen} onOpenChange={setIsOpen}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <span>
                        <DialogTrigger asChild>
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label={`Assign roles to ${user.name}`}
                            >
                                <UserCog />
                            </Button>
                        </DialogTrigger>
                    </span>
                </TooltipTrigger>
                <TooltipContent>Assign roles</TooltipContent>
            </Tooltip>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Assign roles</DialogTitle>
                    <DialogDescription>
                        Select the roles for {user.name} and set their policy
                        evaluation priority.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...UserRoleController.update.form(user.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setIsOpen(false)}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                {roles.map((role, index) => {
                                    const assignedRole = user.roles.find(
                                        (item) => item.id === role.id,
                                    );

                                    return (
                                        <div
                                            key={role.id}
                                            className="grid grid-cols-[minmax(0,1fr)_6.5rem] items-center gap-3 rounded-md border px-3 py-2.5"
                                        >
                                            <label className="flex items-center gap-2 text-sm">
                                                <input
                                                    type="hidden"
                                                    name={`role_assignments[${index}][role_id]`}
                                                    value={role.id}
                                                />
                                                <input
                                                    type="hidden"
                                                    name={`role_assignments[${index}][selected]`}
                                                    value="0"
                                                />
                                                <input
                                                    type="checkbox"
                                                    name={`role_assignments[${index}][selected]`}
                                                    value="1"
                                                    defaultChecked={user.role_ids.includes(
                                                        role.id,
                                                    )}
                                                    className="size-4 rounded border-input"
                                                />
                                                <span>
                                                    {role.name}
                                                    {role.is_admin
                                                        ? ' (admin)'
                                                        : ''}
                                                </span>
                                            </label>
                                            <div className="grid gap-1">
                                                <span className="text-xs text-muted-foreground">
                                                    Priority
                                                </span>
                                                <input
                                                    type="number"
                                                    name={`role_assignments[${index}][priority]`}
                                                    min="0"
                                                    max="9999"
                                                    defaultValue={
                                                        assignedRole?.priority ??
                                                        100
                                                    }
                                                    className="h-8 rounded-md border border-input bg-background px-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                                    aria-label={`${role.name} priority`}
                                                />
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                            <InputError message={errors.role_assignments} />
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button disabled={processing}>
                                    <Save />
                                    Save roles
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function UserStateAction({ user }: { user: ManagedUser }) {
    if (user.is_current_user) {
        return null;
    }

    if (user.disabled_at) {
        return (
            <Form
                {...UserRoleController.enable.form(user.id)}
                options={{ preserveScroll: true }}
            >
                {({ processing }) => (
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <span>
                                <Button
                                    variant="outline"
                                    size="icon"
                                    disabled={processing}
                                    aria-label={`Enable ${user.name}`}
                                >
                                    <Undo2 />
                                </Button>
                            </span>
                        </TooltipTrigger>
                        <TooltipContent>Enable user</TooltipContent>
                    </Tooltip>
                )}
            </Form>
        );
    }

    return (
        <Dialog>
            <Tooltip>
                <TooltipTrigger asChild>
                    <span>
                        <DialogTrigger asChild>
                            <Button
                                variant="destructive"
                                size="icon"
                                aria-label={`Disable ${user.name}`}
                            >
                                <Ban />
                            </Button>
                        </DialogTrigger>
                    </span>
                </TooltipTrigger>
                <TooltipContent>Disable user</TooltipContent>
            </Tooltip>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Disable user?</DialogTitle>
                    <DialogDescription>
                        This user will no longer be able to sign in. Existing
                        roles and audit history will be preserved.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Form
                        {...UserRoleController.disable.form(user.id)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button variant="destructive" disabled={processing}>
                                <Ban />
                                Yes, disable
                            </Button>
                        )}
                    </Form>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function UserTable({
    title,
    description,
    users,
    roles,
    userTimezone,
}: {
    title: string;
    description: string;
    users: ManagedUser[];
    roles: RoleOption[];
    userTimezone: string;
}) {
    return (
        <Card>
            <CardHeader className="border-b px-4 pb-4 sm:px-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <CardTitle>{title}</CardTitle>
                        <CardDescription>{description}</CardDescription>
                    </div>
                    <StatusBadge value="none" label={`${users.length} users`} />
                </div>
            </CardHeader>
            <CardContent className="p-0">
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[820px] table-fixed text-sm">
                        <colgroup>
                            <col className="w-[28%]" />
                            <col className="w-[21%]" />
                            <col className="w-[20%]" />
                            <col className="w-[17%]" />
                            <col className="w-[14%]" />
                        </colgroup>
                        <thead>
                            <tr className="border-b bg-muted/40 text-left text-xs text-muted-foreground uppercase">
                                <th className="py-3 pr-4 pl-4 font-medium sm:pl-6">
                                    User
                                </th>
                                <th className="py-3 pr-4 font-medium">Roles</th>
                                <th className="py-3 pr-4 font-medium">
                                    Status
                                </th>
                                <th className="py-3 pr-4 font-medium">
                                    Joined
                                </th>
                                <th className="py-3 pr-4 font-medium">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.map((user) => (
                                <tr
                                    key={user.id}
                                    className="border-b transition-colors last:border-0 hover:bg-accent/40"
                                >
                                    <td className="py-3.5 pr-4 pl-4 sm:pl-6">
                                        <div className="truncate font-medium">
                                            {user.name}
                                        </div>
                                        <div className="mt-1 truncate text-xs text-muted-foreground">
                                            {user.email}
                                        </div>
                                    </td>
                                    <td className="py-3.5 pr-4">
                                        {user.roles.length > 0 ? (
                                            <div className="grid max-w-80 gap-2">
                                                {user.roles.map((role) => (
                                                    <div
                                                        key={role.id}
                                                        className="flex items-center gap-2"
                                                    >
                                                        <StatusBadge
                                                            value={
                                                                role.is_admin
                                                                    ? 'active'
                                                                    : 'none'
                                                            }
                                                            label={role.name}
                                                        />
                                                        <span className="font-mono text-xs text-muted-foreground">
                                                            P{role.priority}
                                                        </span>
                                                    </div>
                                                ))}
                                            </div>
                                        ) : (
                                            <StatusBadge
                                                value="disabled"
                                                label="Unassigned"
                                            />
                                        )}
                                    </td>
                                    <td className="py-3.5 pr-4">
                                        <div className="flex flex-wrap gap-2">
                                            <StatusBadge
                                                value={
                                                    user.disabled_at
                                                        ? 'failed'
                                                        : 'active'
                                                }
                                                label={
                                                    user.disabled_at
                                                        ? 'Disabled'
                                                        : 'Active'
                                                }
                                            />
                                            {!user.disabled_at && (
                                                <StatusBadge
                                                    value={
                                                        user.email_verified_at
                                                            ? 'active'
                                                            : 'pending_review'
                                                    }
                                                    label={
                                                        user.email_verified_at
                                                            ? 'Verified'
                                                            : user.invited_at
                                                              ? 'Invited'
                                                              : 'Pending'
                                                    }
                                                />
                                            )}
                                        </div>
                                    </td>
                                    <td className="py-3.5 pr-4 text-muted-foreground">
                                        {formatDate(
                                            user.created_at,
                                            userTimezone,
                                        )}
                                    </td>
                                    <td className="py-3.5 pr-4">
                                        <div className="flex items-center gap-2 whitespace-nowrap">
                                            <RoleAssignmentForm
                                                user={user}
                                                roles={roles}
                                            />
                                            <UserStateAction user={user} />
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {users.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-4 py-10 text-center text-sm text-muted-foreground sm:px-6"
                                    >
                                        No users in this section.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    );
}

export default function UsersIndex({
    users,
    active_users,
    disabled_users,
    roles,
}: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const userTimezone = auth.user.timezone ?? 'UTC';

    return (
        <>
            <Head title="Users" />

            <div className="space-y-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <Heading
                        variant="small"
                        title="Users"
                        description={`${users.length} users registered in Crucible DB`}
                    />
                    <Button asChild>
                        <Link href={create()}>
                            <Plus />
                            New user
                        </Link>
                    </Button>
                </div>

                <UserTable
                    title="Active Users"
                    description="Users who can sign in when their email is verified."
                    users={active_users}
                    roles={roles}
                    userTimezone={userTimezone}
                />

                <UserTable
                    title="Disabled Users"
                    description="Former or suspended users. Audit history and role assignments are preserved."
                    users={disabled_users}
                    roles={roles}
                    userTimezone={userTimezone}
                />
            </div>
        </>
    );
}

UsersIndex.layout = {
    breadcrumbs: [
        {
            title: 'Users',
            href: index(),
        },
    ],
};
