import { Form, Head, Link } from '@inertiajs/react';
import { KeyRound, Save, ShieldCheck } from 'lucide-react';
import AuthenticationMethodController from '@/actions/App/Http/Controllers/Settings/AuthenticationMethodController';
import { PageHeader } from '@/components/crucible/page-header';
import { StatusBadge } from '@/components/crucible/status-badge';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { index as authProvidersIndex } from '@/routes/auth-providers';
import { edit } from '@/routes/authentication-methods';

type Props = {
    methods: {
        password_login_enabled: boolean;
        passkey_login_enabled: boolean;
    };
    enabled_provider_count: number;
    configured_sso_providers: Array<{
        id: number;
        name: string;
        provider_label: string;
        is_enabled: boolean;
    }>;
};

export default function AuthenticationMethods({
    methods,
    enabled_provider_count,
    configured_sso_providers,
}: Props) {
    return (
        <>
            <Head title="Sign-in methods" />

            <div className="crucible-page">
                <PageHeader
                    title="Sign-in methods"
                    description="Control which approved sign-in options are visible and accepted on the login page."
                />

                <Form
                    {...AuthenticationMethodController.update.form()}
                    disableWhileProcessing
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <Card className="max-w-3xl gap-0 overflow-hidden border-y py-0 sm:rounded-lg sm:border">
                                <CardHeader className="border-b px-4 py-3 sm:px-5">
                                    <CardTitle>Login options</CardTitle>
                                    <CardDescription>
                                        At least one sign-in option must remain
                                        enabled. There are currently{' '}
                                        {enabled_provider_count} enabled SSO
                                        provider
                                        {enabled_provider_count === 1
                                            ? ''
                                            : 's'}
                                        .
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid divide-y px-4 py-2 sm:px-5">
                                    <label className="flex cursor-pointer items-start gap-3 py-4">
                                        <input
                                            type="hidden"
                                            name="password_login_enabled"
                                            value="0"
                                        />
                                        <input
                                            type="checkbox"
                                            name="password_login_enabled"
                                            value="1"
                                            defaultChecked={
                                                methods.password_login_enabled
                                            }
                                            className="mt-0.5 size-4 rounded border-input"
                                        />
                                        <span className="grid gap-1">
                                            <span className="flex items-center gap-2 font-medium">
                                                <ShieldCheck className="size-4" />{' '}
                                                Email and password
                                            </span>
                                            <span className="text-sm text-muted-foreground">
                                                Allows invited users to sign in
                                                and reset their own password.
                                            </span>
                                        </span>
                                    </label>
                                    <label className="flex cursor-pointer items-start gap-3 py-4">
                                        <input
                                            type="hidden"
                                            name="passkey_login_enabled"
                                            value="0"
                                        />
                                        <input
                                            type="checkbox"
                                            name="passkey_login_enabled"
                                            value="1"
                                            defaultChecked={
                                                methods.passkey_login_enabled
                                            }
                                            className="mt-0.5 size-4 rounded border-input"
                                        />
                                        <span className="grid gap-1">
                                            <span className="flex items-center gap-2 font-medium">
                                                <KeyRound className="size-4" />{' '}
                                                Passkeys
                                            </span>
                                            <span className="text-sm text-muted-foreground">
                                                Allows enrolled users to
                                                authenticate with a device-bound
                                                passkey.
                                            </span>
                                        </span>
                                    </label>
                                </CardContent>
                            </Card>
                            <InputError
                                message={
                                    errors.password_login_enabled ??
                                    errors.passkey_login_enabled
                                }
                            />
                            <Button disabled={processing}>
                                {processing ? <Spinner /> : <Save />}
                                Save sign-in methods
                            </Button>
                        </>
                    )}
                </Form>

                <Card className="max-w-3xl gap-0 overflow-hidden border-y py-0 sm:rounded-lg sm:border">
                    <CardHeader className="flex flex-row items-start justify-between gap-4 border-b px-4 py-3 sm:px-5">
                        <div className="space-y-1">
                            <CardTitle>Configured SSO providers</CardTitle>
                            <CardDescription>
                                Provider availability is managed separately from
                                the core sign-in methods.
                            </CardDescription>
                        </div>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={authProvidersIndex()}>
                                Manage providers
                            </Link>
                        </Button>
                    </CardHeader>
                    <CardContent className="divide-y p-0">
                        {configured_sso_providers.map((provider) => (
                            <div
                                key={provider.id}
                                className="flex items-center justify-between gap-4 px-4 py-3 sm:px-5"
                            >
                                <div className="min-w-0">
                                    <div className="font-medium">
                                        {provider.name}
                                    </div>
                                    <div className="mt-1 text-sm text-muted-foreground">
                                        {provider.provider_label}
                                    </div>
                                </div>
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
                            </div>
                        ))}
                        {configured_sso_providers.length === 0 && (
                            <div className="px-4 py-10 text-center text-sm text-muted-foreground sm:px-5">
                                No SSO providers are configured.
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

AuthenticationMethods.layout = {
    breadcrumbs: [{ title: 'Sign-in methods', href: edit() }],
};
