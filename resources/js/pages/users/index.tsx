import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    Ban,
    Plus,
    Save,
    Search,
    Trash2,
    Undo2,
    UserCog,
    UsersRound,
} from 'lucide-react';
import { useState } from 'react';
import UserRoleController from '@/actions/App/Http/Controllers/UserRoleController';
import { DataRegistry } from '@/components/crucible/data-registry';
import { EmptyState } from '@/components/crucible/empty-state';
import { PageHeader } from '@/components/crucible/page-header';
import { StatusBadge } from '@/components/crucible/status-badge';
import InputError from '@/components/input-error';
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
import { formatDate } from '@/lib/crucible';
import { create, index } from '@/routes/users';
import type { Auth } from '@/types';

type RoleOption = {
    id: number;
    name: string;
    slug: string;
    is_admin: boolean;
};

type AssignedRole = RoleOption;

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
    const [search, setSearch] = useState('');
    const [orderedRoleIds, setOrderedRoleIds] = useState<number[]>(() =>
        user.roles.map((role) => role.id),
    );

    const resetDialog = () => {
        setSearch('');
        setOrderedRoleIds(user.roles.map((role) => role.id));
    };

    const handleOpenChange = (open: boolean) => {
        setIsOpen(open);

        if (!open) {
            resetDialog();
        }
    };

    const normalizedSearch = search.trim().toLocaleLowerCase();
    const selectedRoles = orderedRoleIds
        .map((roleId) => roles.find((role) => role.id === roleId))
        .filter((role): role is RoleOption => role !== undefined);
    const availableRoles = roles.filter((role) => {
        const matchesSearch =
            normalizedSearch === '' ||
            `${role.name} ${role.slug}`
                .toLocaleLowerCase()
                .includes(normalizedSearch);

        return matchesSearch && !orderedRoleIds.includes(role.id);
    });

    const addRole = (roleId: number) => {
        setOrderedRoleIds((current) => [...current, roleId]);
    };

    const removeRole = (roleId: number) => {
        setOrderedRoleIds((current) =>
            current.filter((currentRoleId) => currentRoleId !== roleId),
        );
    };

    const moveRole = (roleIndex: number, direction: 'up' | 'down') => {
        setOrderedRoleIds((current) => {
            const nextIndex =
                direction === 'up' ? roleIndex - 1 : roleIndex + 1;

            if (nextIndex < 0 || nextIndex >= current.length) {
                return current;
            }

            const next = [...current];
            [next[roleIndex], next[nextIndex]] = [
                next[nextIndex],
                next[roleIndex],
            ];

            return next;
        });
    };

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
        <Dialog open={isOpen} onOpenChange={handleOpenChange}>
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
            <DialogContent className="max-h-[calc(100dvh-2rem)] gap-0 overflow-hidden p-0 sm:max-w-2xl">
                <DialogHeader className="border-b px-5 pt-5 pr-12 pb-4">
                    <DialogTitle className="text-xl tracking-[-0.02em]">
                        Assign roles
                    </DialogTitle>
                    <DialogDescription>
                        Define policy precedence for {user.name}. The first role
                        wins when policies overlap.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...UserRoleController.update.form(user.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => handleOpenChange(false)}
                    className="grid min-h-0 grid-rows-[auto_minmax(0,1fr)_auto]"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="min-h-0 overflow-y-auto px-5 py-4">
                                <section
                                    aria-labelledby="policy-precedence-heading"
                                    className="overflow-hidden rounded-lg border bg-card"
                                >
                                    <div className="border-b bg-muted/25 px-4 py-3">
                                        <h3
                                            id="policy-precedence-heading"
                                            className="font-semibold"
                                        >
                                            Policy precedence
                                        </h3>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            The first role wins when policies
                                            overlap. Move roles to change the
                                            evaluation order.
                                        </p>
                                    </div>
                                    {selectedRoles.length > 0 ? (
                                        <div className="divide-y">
                                            {selectedRoles.map(
                                                (role, index) => (
                                                    <div
                                                        key={role.id}
                                                        className="flex items-center gap-3 px-4 py-3"
                                                    >
                                                        <input
                                                            type="hidden"
                                                            name={`role_assignments[${index}][role_id]`}
                                                            value={role.id}
                                                        />
                                                        <input
                                                            type="hidden"
                                                            name={`role_assignments[${index}][selected]`}
                                                            value="1"
                                                        />
                                                        <input
                                                            type="hidden"
                                                            name={`role_assignments[${index}][priority]`}
                                                            value={
                                                                (index + 1) * 10
                                                            }
                                                        />
                                                        <span className="flex size-8 shrink-0 items-center justify-center rounded-md border bg-muted font-mono text-xs font-semibold text-muted-foreground">
                                                            {index + 1}
                                                        </span>
                                                        <div className="min-w-0 flex-1">
                                                            <div className="truncate font-medium">
                                                                {role.name}
                                                            </div>
                                                            <div className="mt-0.5 truncate font-mono text-xs text-muted-foreground">
                                                                {role.slug}
                                                                {role.is_admin &&
                                                                    ' · administrator'}
                                                            </div>
                                                        </div>
                                                        <div className="flex shrink-0 items-center gap-1">
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="icon"
                                                                disabled={
                                                                    index === 0
                                                                }
                                                                onClick={() =>
                                                                    moveRole(
                                                                        index,
                                                                        'up',
                                                                    )
                                                                }
                                                                aria-label={`Move ${role.name} up`}
                                                            >
                                                                <ArrowUp />
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="icon"
                                                                disabled={
                                                                    index ===
                                                                    selectedRoles.length -
                                                                        1
                                                                }
                                                                onClick={() =>
                                                                    moveRole(
                                                                        index,
                                                                        'down',
                                                                    )
                                                                }
                                                                aria-label={`Move ${role.name} down`}
                                                            >
                                                                <ArrowDown />
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="icon"
                                                                onClick={() =>
                                                                    removeRole(
                                                                        role.id,
                                                                    )
                                                                }
                                                                aria-label={`Remove ${role.name}`}
                                                            >
                                                                <Trash2 />
                                                            </Button>
                                                        </div>
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                    ) : (
                                        <p className="px-4 py-8 text-center text-sm text-muted-foreground">
                                            No roles assigned. Add a role below
                                            to define this user’s policy.
                                        </p>
                                    )}
                                </section>

                                <section className="mt-4 overflow-hidden rounded-lg border bg-card">
                                    <div className="border-b bg-muted/25 px-4 py-3">
                                        <h3 className="font-semibold">
                                            Add roles
                                        </h3>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            New roles are added at the bottom of
                                            the precedence list.
                                        </p>
                                        <div className="relative mt-3">
                                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                            <input
                                                value={search}
                                                onChange={(event) =>
                                                    setSearch(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="Search roles by name or slug"
                                                className="h-9 w-full rounded-md border border-input bg-background py-1 pr-3 pl-9 text-sm shadow-xs transition-[color,border-color,box-shadow] duration-150 ease-out outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30 motion-reduce:transition-none"
                                                aria-label="Search roles to add"
                                            />
                                        </div>
                                    </div>
                                    {availableRoles.length > 0 ? (
                                        <div className="max-h-52 divide-y overflow-y-auto">
                                            {availableRoles.map((role) => (
                                                <div
                                                    key={role.id}
                                                    className="flex items-center gap-3 px-4 py-3"
                                                >
                                                    <div className="min-w-0 flex-1">
                                                        <div className="truncate font-medium">
                                                            {role.name}
                                                        </div>
                                                        <div className="mt-0.5 truncate font-mono text-xs text-muted-foreground">
                                                            {role.slug}
                                                            {role.is_admin &&
                                                                ' · administrator'}
                                                        </div>
                                                    </div>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            addRole(role.id)
                                                        }
                                                    >
                                                        <Plus />
                                                        Add
                                                    </Button>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="px-4 py-8 text-center text-sm text-muted-foreground">
                                            {normalizedSearch === ''
                                                ? 'All available roles are already assigned.'
                                                : `No unassigned roles match “${search}”.`}
                                        </p>
                                    )}
                                </section>
                            </div>
                            <div className="border-t px-5 py-4">
                                <InputError
                                    message={errors.role_assignments}
                                    className="mb-3"
                                />
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
                            </div>
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
        <DataRegistry
            title={title}
            description={description}
            actions={
                <StatusBadge value="none" label={`${users.length} users`} />
            }
        >
            {users.length === 0 ? (
                <div className="p-6">
                    <EmptyState
                        icon={UsersRound}
                        title={`No ${title.toLowerCase()} found`}
                        detail="People will appear here when they are invited or their account status changes."
                    />
                </div>
            ) : (
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
                            <tr className="border-b bg-muted/45 text-left text-xs text-muted-foreground">
                                <th className="py-2.5 pr-4 pl-3 font-medium sm:pl-4">
                                    User
                                </th>
                                <th className="py-2.5 pr-4 font-medium">
                                    Roles
                                </th>
                                <th className="py-2.5 pr-4 font-medium">
                                    Status
                                </th>
                                <th className="py-2.5 pr-4 font-medium">
                                    Joined
                                </th>
                                <th className="py-2.5 pr-3 text-right font-medium sm:pr-4">
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
                                    <td className="py-3 pr-4 pl-3 sm:pl-4">
                                        <div className="truncate font-medium">
                                            {user.name}
                                        </div>
                                        <div className="mt-1 truncate text-xs text-muted-foreground">
                                            {user.email}
                                        </div>
                                    </td>
                                    <td className="py-3 pr-4">
                                        {user.roles.length > 0 ? (
                                            <div className="grid max-w-80 gap-2">
                                                {user.roles.map(
                                                    (role, index) => (
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
                                                                label={
                                                                    role.name
                                                                }
                                                            />
                                                            <span className="font-mono text-xs text-muted-foreground">
                                                                Policy{' '}
                                                                {index + 1}
                                                            </span>
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        ) : (
                                            <StatusBadge
                                                value="disabled"
                                                label="Unassigned"
                                            />
                                        )}
                                    </td>
                                    <td className="py-3 pr-4">
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
                                    <td className="py-3 pr-4 text-muted-foreground">
                                        {formatDate(
                                            user.created_at,
                                            userTimezone,
                                        )}
                                    </td>
                                    <td className="py-3 pr-3 sm:pr-4">
                                        <div className="flex items-center justify-end gap-2 whitespace-nowrap">
                                            <RoleAssignmentForm
                                                user={user}
                                                roles={roles}
                                            />
                                            <UserStateAction user={user} />
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </DataRegistry>
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

            <div className="crucible-page">
                <PageHeader
                    title="Users"
                    description={`${users.length} users registered in Crucible DB`}
                    actions={
                        <Button asChild>
                            <Link href={create()}>
                                <Plus />
                                New user
                            </Link>
                        </Button>
                    }
                />

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
