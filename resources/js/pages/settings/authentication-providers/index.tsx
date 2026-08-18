import { Form, Head, Link } from '@inertiajs/react';
import { BadgeCheck, Edit3, FlaskConical, Plus, Trash2 } from 'lucide-react';
import AuthProviderController from '@/actions/App/Http/Controllers/AuthProviderController';
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
import { create, edit, index, test } from '@/routes/auth-providers';

type AuthProviderRecord = {
    id: number;
    provider: string;
    provider_label: string;
    name: string;
    client_id: string;
    scopes: string;
    allowed_domains: string;
    tenant: string | null;
    is_enabled: boolean;
    callback_url: string;
    user_identities_count: number;
};

type Props = {
    providers: AuthProviderRecord[];
};

export default function AuthProvidersIndex({ providers }: Props) {
    return (
        <>
            <Head title="Authentication providers" />

            <div className="crucible-page">
                <PageHeader
                    title="Authentication providers"
                    description="Manage invitation-gated SSO and verify OAuth configuration."
                    actions={
                        <Button asChild>
                            <Link href={create()}>
                                <Plus />
                                New provider
                            </Link>
                        </Button>
                    }
                />

                <DataRegistry
                    title="Provider registry"
                    description="OAuth credentials are encrypted. Disable a provider to stop new SSO logins."
                >
                    {providers.length === 0 ? (
                        <div className="p-6">
                            <EmptyState
                                icon={BadgeCheck}
                                title="No SSO providers configured"
                                detail="Add an OAuth provider when invited users should be able to sign in with SSO."
                                action={
                                    <Button size="sm" asChild>
                                        <Link href={create()}>
                                            <Plus />
                                            New provider
                                        </Link>
                                    </Button>
                                }
                            />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[980px] text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/45 text-left text-xs text-muted-foreground">
                                        <th className="py-2.5 pr-4 pl-3 font-medium sm:pl-4">
                                            Provider
                                        </th>
                                        <th className="py-2.5 pr-4 font-medium">
                                            Status
                                        </th>
                                        <th className="py-2.5 pr-4 font-medium">
                                            Domains
                                        </th>
                                        <th className="py-2.5 pr-4 font-medium">
                                            Callback URL
                                        </th>
                                        <th className="py-2.5 pr-4 font-medium">
                                            Linked Users
                                        </th>
                                        <th className="py-2.5 pr-3 text-right font-medium sm:pr-4">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {providers.map((provider) => {
                                        const canDelete =
                                            provider.user_identities_count ===
                                            0;

                                        return (
                                            <tr
                                                key={provider.id}
                                                className="border-b transition-colors last:border-0 hover:bg-accent/40"
                                            >
                                                <td className="py-3 pr-4 pl-3 sm:pl-4">
                                                    <div className="font-medium">
                                                        {provider.name}
                                                    </div>
                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                        {
                                                            provider.provider_label
                                                        }{' '}
                                                        / {provider.client_id}
                                                    </div>
                                                </td>
                                                <td className="py-3 pr-4">
                                                    <StatusBadge
                                                        value={
                                                            provider.is_enabled
                                                                ? 'active'
                                                                : 'disabled'
                                                        }
                                                        label={
                                                            provider.is_enabled
                                                                ? 'Enabled'
                                                                : 'Disabled'
                                                        }
                                                    />
                                                </td>
                                                <td className="py-3 pr-4 text-muted-foreground">
                                                    {provider.allowed_domains ||
                                                        'Any invited email'}
                                                </td>
                                                <td className="py-3 pr-4">
                                                    <code className="block max-w-xs truncate rounded bg-muted px-2 py-1 text-xs">
                                                        {provider.callback_url}
                                                    </code>
                                                </td>
                                                <td className="py-3 pr-4 tabular-nums">
                                                    {
                                                        provider.user_identities_count
                                                    }
                                                </td>
                                                <td className="py-3 pr-3 sm:pr-4">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            asChild
                                                        >
                                                            <a
                                                                href={test.url(
                                                                    provider.id,
                                                                )}
                                                            >
                                                                <FlaskConical />
                                                                Test
                                                            </a>
                                                        </Button>
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={edit(
                                                                    provider.id,
                                                                )}
                                                            >
                                                                <Edit3 />
                                                                Edit
                                                            </Link>
                                                        </Button>
                                                        <Dialog>
                                                            <DialogTrigger
                                                                asChild
                                                            >
                                                                <Button
                                                                    variant="destructive"
                                                                    size="sm"
                                                                    disabled={
                                                                        !canDelete
                                                                    }
                                                                >
                                                                    <Trash2 />
                                                                    Delete
                                                                </Button>
                                                            </DialogTrigger>
                                                            <DialogContent>
                                                                <DialogHeader>
                                                                    <DialogTitle>
                                                                        Delete
                                                                        provider?
                                                                    </DialogTitle>
                                                                    <DialogDescription>
                                                                        Linked
                                                                        identities
                                                                        must be
                                                                        removed
                                                                        before
                                                                        deleting
                                                                        a
                                                                        provider.
                                                                        Disable
                                                                        it to
                                                                        stop
                                                                        login
                                                                        immediately.
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
                                                                        {...AuthProviderController.destroy.form(
                                                                            provider.id,
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
                                                                                Delete
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

AuthProvidersIndex.layout = {
    breadcrumbs: [
        {
            title: 'Authentication providers',
            href: index(),
        },
    ],
};
