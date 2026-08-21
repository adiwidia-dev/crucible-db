import { Form, Head, Link } from '@inertiajs/react';
import { Check, FolderTree, Info, X } from 'lucide-react';
import { useState } from 'react';
import ConnectionGroupController from '@/actions/App/Http/Controllers/ConnectionGroupController';
import { ConnectionMultiCombobox } from '@/components/crucible/connection-combobox';
import { PageHeader } from '@/components/crucible/page-header';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { DatabaseDriver } from '@/lib/crucible';
import { index } from '@/routes/connection-groups';

type ConnectionGroup = {
    id: number;
    name: string;
    description: string | null;
    database_connection_ids: number[];
    database_connections_count: number;
    role_policies_count: number;
} | null;

type Connection = {
    id: number;
    name: string;
    driver: DatabaseDriver;
    host: string;
    port: number;
    database: string;
    is_active: boolean;
};

type Props = {
    connection_group: ConnectionGroup;
    connections: Connection[];
};

export default function ConnectionGroupForm({
    connection_group: connectionGroup,
    connections,
}: Props) {
    const isEditing = Boolean(connectionGroup);
    const action = connectionGroup
        ? ConnectionGroupController.update.form(connectionGroup.id)
        : ConnectionGroupController.store.form();
    const [connectionIds, setConnectionIds] = useState<string[]>(
        connectionGroup?.database_connection_ids.map(String) ?? [],
    );

    return (
        <>
            <Head
                title={
                    isEditing ? 'Edit connection group' : 'New connection group'
                }
            />

            <div className="crucible-page">
                <PageHeader
                    icon={FolderTree}
                    title={
                        isEditing
                            ? 'Edit Connection Group'
                            : 'New Connection Group'
                    }
                    description={
                        isEditing
                            ? 'Update the explicit targets covered by this group.'
                            : 'Create an explicit set of database targets for reusable role policies.'
                    }
                />

                <Form
                    {...action}
                    options={{ preserveScroll: true, preserveState: 'errors' }}
                    className="grid gap-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <Card className="gap-0 overflow-hidden border-y py-0 sm:rounded-lg sm:border">
                                <CardHeader className="border-b px-4 py-3 sm:px-5">
                                    <CardTitle>Group details</CardTitle>
                                    <CardDescription>
                                        Give this access scope a meaningful,
                                        recognizable name.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid max-w-3xl gap-5 px-4 py-5 sm:grid-cols-2 sm:px-5">
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">Group name</Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            defaultValue={
                                                connectionGroup?.name ?? ''
                                            }
                                            placeholder="Staging databases"
                                            required
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="description">
                                            Description
                                        </Label>
                                        <Input
                                            id="description"
                                            name="description"
                                            defaultValue={
                                                connectionGroup?.description ??
                                                ''
                                            }
                                            placeholder="Shared staging targets"
                                        />
                                        <InputError
                                            message={errors.description}
                                        />
                                    </div>
                                </CardContent>
                            </Card>

                            <Card className="gap-0 overflow-hidden border-y py-0 sm:rounded-lg sm:border">
                                <CardHeader className="border-b px-4 py-3 sm:px-5">
                                    <CardTitle>Member connections</CardTitle>
                                    <CardDescription>
                                        Add explicit targets to this group. A
                                        group never includes connections by
                                        name, pattern, or tag.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid gap-4 px-4 py-5 sm:px-5">
                                    {connectionGroup &&
                                        isEditing &&
                                        connectionGroup.role_policies_count >
                                            0 && (
                                            <div className="flex gap-3 rounded-md border border-amber-300/80 bg-amber-50/50 p-3 text-sm dark:bg-amber-950/20">
                                                <Info className="mt-0.5 size-4 shrink-0 text-amber-700 dark:text-amber-300" />
                                                <div>
                                                    <p className="font-medium">
                                                        Membership affects
                                                        existing role policies
                                                    </p>
                                                    <p className="mt-1 text-muted-foreground">
                                                        {
                                                            connectionGroup.role_policies_count
                                                        }{' '}
                                                        role{' '}
                                                        {connectionGroup.role_policies_count ===
                                                        1
                                                            ? 'policy uses'
                                                            : 'policies use'}{' '}
                                                        this group. Saving a
                                                        membership change
                                                        immediately changes
                                                        their effective access.
                                                    </p>
                                                </div>
                                            </div>
                                        )}

                                    {connections.length === 0 ? (
                                        <div className="rounded-md border border-dashed px-4 py-5 text-sm text-muted-foreground">
                                            No database connections are
                                            available yet.
                                        </div>
                                    ) : (
                                        <ConnectionMultiCombobox
                                            connections={connections}
                                            values={connectionIds}
                                            onValueChange={setConnectionIds}
                                            name="database_connection_ids[]"
                                            label="Connections"
                                            description="Search and select every connection that belongs to this group."
                                            error={
                                                errors.database_connection_ids
                                            }
                                        />
                                    )}
                                </CardContent>
                            </Card>

                            <div className="flex flex-col gap-3 border-t pt-5 sm:flex-row sm:items-center sm:justify-between">
                                <p className="max-w-2xl text-xs leading-5 text-muted-foreground">
                                    Access controls are configured on each role.
                                    This group only defines the connections that
                                    policy applies to.
                                </p>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button variant="outline" asChild>
                                        <Link href={index()}>
                                            <X />
                                            Cancel
                                        </Link>
                                    </Button>
                                    <Button disabled={processing}>
                                        <Check />
                                        {processing
                                            ? 'Saving...'
                                            : isEditing
                                              ? 'Save group'
                                              : 'Create group'}
                                    </Button>
                                </div>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

ConnectionGroupForm.layout = {
    breadcrumbs: [
        {
            title: 'Connection Groups',
            href: index(),
        },
    ],
};
