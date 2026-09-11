import { Form, Head } from '@inertiajs/react';
import { ArrowRight, KeyRound } from 'lucide-react';
import SetupController from '@/actions/App/Http/Controllers/SetupController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

export default function SetupAccess() {
    return (
        <>
            <Head title="Unlock initial setup" />

            <Form
                {...SetupController.authorizeAccess.form()}
                disableWhileProcessing
                resetOnError={['setup_token']}
                className="grid gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="rounded-md border bg-muted/30 p-3 text-sm text-muted-foreground">
                            <div className="flex items-center gap-2 font-medium text-foreground">
                                <KeyRound className="size-4 text-orange-600" />
                                Deployment authorization
                            </div>
                            <p className="mt-1 leading-6">
                                Enter the one-time setup token configured by the
                                administrator who deployed this instance.
                            </p>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="setup_token">Setup token</Label>
                            <PasswordInput
                                id="setup_token"
                                name="setup_token"
                                required
                                autoFocus
                                autoComplete="off"
                            />
                            <InputError message={errors.setup_token} />
                        </div>

                        <Button className="w-full" disabled={processing}>
                            {processing ? <Spinner /> : <ArrowRight />}
                            Unlock setup
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

SetupAccess.layout = {
    title: 'Unlock initial setup',
    description:
        'Setup can create the first administrator and connect to a control database, so it requires the deployment token.',
};
