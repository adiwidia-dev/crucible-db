import { Form, Head } from '@inertiajs/react';
import { AuthProviderButtons } from '@/components/auth-provider-buttons';
import type { AuthProviderButton } from '@/components/auth-provider-buttons';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    accept_url: string;
    authProviders: AuthProviderButton[];
    email: string;
    name: string;
    passwordRules: string;
};

export default function AcceptInvitation({
    accept_url,
    authProviders,
    email,
    name,
    passwordRules,
}: Props) {
    return (
        <>
            <Head title="Accept invitation" />

            <Form
                action={accept_url}
                method="post"
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="grid gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input id="name" value={name} readOnly />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">Email Address</Label>
                            <Input
                                id="email"
                                type="email"
                                value={email}
                                readOnly
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">Password</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                placeholder="Password"
                                passwordrules={passwordRules}
                                required
                                autoFocus
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">
                                Confirm Password
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                autoComplete="new-password"
                                placeholder="Confirm password"
                                passwordrules={passwordRules}
                                required
                            />
                            <InputError
                                message={errors.password_confirmation}
                            />
                        </div>

                        <Button disabled={processing}>
                            {processing && <Spinner />}
                            Accept Invitation
                        </Button>

                        <AuthProviderButtons
                            providers={authProviders}
                            label="Accept with"
                        />
                    </>
                )}
            </Form>
        </>
    );
}

AcceptInvitation.layout = {
    title: 'Accept your invitation',
    description:
        'Set your password to finish creating your Crucible DB account.',
};
