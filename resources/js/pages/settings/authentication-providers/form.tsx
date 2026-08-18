import { Form, Head, Link } from '@inertiajs/react';
import { ExternalLink, FlaskConical, Save, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import AuthProviderController from '@/actions/App/Http/Controllers/AuthProviderController';
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
import { Spinner } from '@/components/ui/spinner';
import { index, test } from '@/routes/auth-providers';

type ProviderOption = {
    value: string;
    label: string;
    default_scopes: string;
    setup_guide: {
        overview: string;
        steps: string[];
        documentation_url: string;
        documentation_label: string;
    };
};

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
};

type Props = {
    provider: AuthProviderRecord | null;
    provider_options: ProviderOption[];
};

export default function AuthProviderForm({
    provider,
    provider_options,
}: Props) {
    const isEditing = provider !== null;
    const [selectedProvider, setSelectedProvider] = useState(
        provider?.provider ?? provider_options[0]?.value ?? 'google',
    );
    const selectedOption = useMemo(
        () =>
            provider_options.find(
                (option) => option.value === selectedProvider,
            ),
        [provider_options, selectedProvider],
    );
    const action = isEditing
        ? AuthProviderController.update.form(provider.id)
        : AuthProviderController.store.form();

    return (
        <>
            <Head
                title={isEditing ? 'Edit auth provider' : 'New auth provider'}
            />

            <div className="crucible-page">
                <PageHeader
                    title={
                        isEditing
                            ? 'Edit authentication provider'
                            : 'New authentication provider'
                    }
                    description="Configure an invitation-gated OAuth provider."
                />

                <Form {...action} disableWhileProcessing className="grid gap-6">
                    {({ processing, errors }) => (
                        <>
                            <Card className="gap-0 overflow-hidden border-y py-0 sm:rounded-lg sm:border">
                                <CardHeader className="border-b px-4 py-3 sm:px-5">
                                    <CardTitle>Provider</CardTitle>
                                    <CardDescription>
                                        Use the callback URL in the OAuth app
                                        registration for this provider.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid max-w-3xl gap-5 px-4 py-5 sm:px-5">
                                    <div className="grid gap-2">
                                        <Label htmlFor="provider">
                                            Provider
                                        </Label>
                                        <select
                                            id="provider"
                                            name="provider"
                                            value={selectedProvider}
                                            onChange={(event) =>
                                                setSelectedProvider(
                                                    event.currentTarget.value,
                                                )
                                            }
                                            className="h-10 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                        >
                                            {provider_options.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError message={errors.provider} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="name">
                                            Display Name
                                        </Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            defaultValue={
                                                provider?.name ??
                                                selectedOption?.label
                                            }
                                            required
                                        />
                                        <InputError message={errors.name} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="client_id">
                                            Client ID
                                        </Label>
                                        <Input
                                            id="client_id"
                                            name="client_id"
                                            defaultValue={provider?.client_id}
                                            required
                                        />
                                        <InputError
                                            message={errors.client_id}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="client_secret">
                                            Client Secret
                                        </Label>
                                        <Input
                                            id="client_secret"
                                            name="client_secret"
                                            type="password"
                                            placeholder={
                                                isEditing
                                                    ? 'Leave blank to keep current secret'
                                                    : ''
                                            }
                                            required={!isEditing}
                                        />
                                        <InputError
                                            message={errors.client_secret}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="scopes">Scopes</Label>
                                        <Input
                                            id="scopes"
                                            name="scopes"
                                            defaultValue={
                                                provider?.scopes ??
                                                selectedOption?.default_scopes
                                            }
                                            placeholder="openid profile email"
                                        />
                                        <InputError message={errors.scopes} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="allowed_domains">
                                            Allowed Email Domains
                                        </Label>
                                        <Input
                                            id="allowed_domains"
                                            name="allowed_domains"
                                            defaultValue={
                                                provider?.allowed_domains
                                            }
                                            placeholder="example.com, company.com"
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Leave blank to allow any invited
                                            email. Domain checks only restrict
                                            invited or linked users further.
                                        </p>
                                        <InputError
                                            message={errors.allowed_domains}
                                        />
                                    </div>

                                    {selectedProvider === 'microsoft' && (
                                        <div className="grid gap-2">
                                            <Label htmlFor="tenant">
                                                Microsoft Tenant
                                            </Label>
                                            <Input
                                                id="tenant"
                                                name="tenant"
                                                defaultValue={
                                                    provider?.tenant ?? 'common'
                                                }
                                                placeholder="common"
                                            />
                                            <InputError
                                                message={errors.tenant}
                                            />
                                        </div>
                                    )}

                                    {provider?.callback_url && (
                                        <div className="grid gap-2">
                                            <Label htmlFor="callback_url">
                                                Callback URL
                                            </Label>
                                            <Input
                                                id="callback_url"
                                                value={provider.callback_url}
                                                readOnly
                                            />
                                        </div>
                                    )}

                                    <div className="flex items-center gap-3">
                                        <input
                                            type="hidden"
                                            name="is_enabled"
                                            value="0"
                                        />
                                        <input
                                            id="is_enabled"
                                            name="is_enabled"
                                            value="1"
                                            type="checkbox"
                                            defaultChecked={
                                                provider?.is_enabled ?? false
                                            }
                                            className="size-4 rounded border-input"
                                        />
                                        <Label htmlFor="is_enabled">
                                            Enable provider
                                        </Label>
                                    </div>
                                </CardContent>
                            </Card>

                            {selectedOption && (
                                <Card className="max-w-3xl gap-0 overflow-hidden border-y py-0 sm:rounded-lg sm:border">
                                    <CardHeader className="border-b px-4 py-3 sm:px-5">
                                        <CardTitle>
                                            Set up {selectedOption.label}
                                        </CardTitle>
                                        <CardDescription>
                                            {
                                                selectedOption.setup_guide
                                                    .overview
                                            }
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-4 px-4 py-5 sm:px-5">
                                        <ol className="grid list-decimal gap-2 pl-5 text-sm leading-6 text-muted-foreground">
                                            {selectedOption.setup_guide.steps.map(
                                                (step) => (
                                                    <li key={step}>{step}</li>
                                                ),
                                            )}
                                        </ol>
                                        {provider?.callback_url ? (
                                            <div className="grid gap-2 rounded-md border bg-muted/25 p-3">
                                                <span className="text-sm font-medium">
                                                    Registered callback URL
                                                </span>
                                                <code className="text-xs break-all text-muted-foreground">
                                                    {provider.callback_url}
                                                </code>
                                            </div>
                                        ) : (
                                            <p className="text-sm text-muted-foreground">
                                                Save this provider first to
                                                reveal its unique callback URL.
                                                Leave it disabled until the
                                                callback and test are complete.
                                            </p>
                                        )}
                                        <a
                                            href={
                                                selectedOption.setup_guide
                                                    .documentation_url
                                            }
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex items-center gap-2 text-sm font-medium text-primary hover:underline"
                                        >
                                            {
                                                selectedOption.setup_guide
                                                    .documentation_label
                                            }
                                            <ExternalLink className="size-3.5" />
                                        </a>
                                    </CardContent>
                                </Card>
                            )}

                            <div className="flex flex-wrap items-center gap-3">
                                <Button disabled={processing}>
                                    {processing ? <Spinner /> : <Save />}
                                    {isEditing
                                        ? 'Save Provider'
                                        : 'Create Provider'}
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href={index()}>
                                        <X />
                                        Cancel
                                    </Link>
                                </Button>
                                {provider && (
                                    <Button variant="outline" asChild>
                                        <a href={test.url(provider.id)}>
                                            <FlaskConical />
                                            Test configuration
                                        </a>
                                    </Button>
                                )}
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

AuthProviderForm.layout = {
    breadcrumbs: [
        {
            title: 'Authentication providers',
            href: index(),
        },
        {
            title: 'Provider',
            href: '#',
        },
    ],
};
