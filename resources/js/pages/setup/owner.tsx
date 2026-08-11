import { Form, Head } from '@inertiajs/react';
import { ArrowRight, ShieldCheck } from 'lucide-react';
import SetupController from '@/actions/App/Http/Controllers/SetupController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    app_name: string;
    passwordRules: string;
};

export default function SetupOwner({ app_name, passwordRules }: Props) {
    return (
        <>
            <Head title="Set up Crucible DB" />

            <Form
                {...SetupController.store.form()}
                disableWhileProcessing
                className="grid gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="rounded-md border bg-muted/30 p-3 text-sm text-muted-foreground">
                            <div className="flex items-center gap-2 font-medium text-foreground">
                                <ShieldCheck className="size-4 text-orange-600" />
                                Initial administrator
                            </div>
                            <p className="mt-1">
                                This one-time account controls all workspace
                                administration. Public registration remains
                                disabled after setup.
                            </p>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="app_name">Application name</Label>
                            <Input
                                id="app_name"
                                name="app_name"
                                defaultValue={app_name}
                                required
                                autoFocus
                            />
                            <InputError message={errors.app_name} />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="first_name">First name</Label>
                                <Input
                                    id="first_name"
                                    name="first_name"
                                    required
                                    autoComplete="given-name"
                                />
                                <InputError message={errors.first_name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="last_name">Last name</Label>
                                <Input
                                    id="last_name"
                                    name="last_name"
                                    autoComplete="family-name"
                                />
                                <InputError message={errors.last_name} />
                            </div>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                name="email"
                                type="email"
                                required
                                autoComplete="email"
                                placeholder="admin@example.com"
                            />
                            <InputError message={errors.email} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="password">Password</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                            />
                            <InputError message={errors.password} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">
                                Confirm password
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                required
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                            />
                        </div>
                        <Button className="mt-1 w-full" disabled={processing}>
                            {processing ? <Spinner /> : <ArrowRight />}
                            Continue to database connection
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

SetupOwner.layout = {
    title: 'Set up Crucible DB',
    description: 'Create the first administrator to initialize this workspace.',
};
