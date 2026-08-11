import { Form, Head } from '@inertiajs/react';
import { AlertTriangle, Mail, Save, Settings2 } from 'lucide-react';
import { useState } from 'react';
import ApplicationSettingsController from '@/actions/App/Http/Controllers/Settings/ApplicationSettingsController';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit, factoryReset } from '@/routes/application-settings';

type Props = {
    settings: {
        app_name: string;
        mail_host: string | null;
        mail_port: number | null;
        mail_username: string | null;
        mail_scheme: string | null;
        mail_from_address: string | null;
        mail_from_name: string | null;
        has_mail_password: boolean;
    };
    factory_reset_confirmation_phrase: string;
};

export default function ApplicationSettings({
    settings,
    factory_reset_confirmation_phrase: factoryResetConfirmationPhrase,
}: Props) {
    return (
        <>
            <Head title="Application settings" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Application settings"
                    description="Set the workspace identity and outbound email transport without changing deployment configuration."
                />

                <Form
                    {...ApplicationSettingsController.update.form()}
                    disableWhileProcessing
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <Card>
                                <CardHeader className="border-b px-4 pb-4 sm:px-6">
                                    <CardTitle className="flex items-center gap-2">
                                        <Settings2 className="size-4 text-muted-foreground" />
                                        Workspace identity
                                    </CardTitle>
                                    <CardDescription>
                                        This name is used in the interface and
                                        transactional email sender name by
                                        default.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid max-w-xl gap-2 pt-6">
                                    <Label htmlFor="app_name">
                                        Application name
                                    </Label>
                                    <Input
                                        id="app_name"
                                        name="app_name"
                                        defaultValue={settings.app_name}
                                        required
                                    />
                                    <InputError message={errors.app_name} />
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader className="border-b px-4 pb-4 sm:px-6">
                                    <CardTitle className="flex items-center gap-2">
                                        <Mail className="size-4 text-muted-foreground" />
                                        SMTP delivery
                                    </CardTitle>
                                    <CardDescription>
                                        Leave this section unchanged to continue
                                        using deployment-provided mail settings.
                                        Saved credentials are encrypted and
                                        never shown again.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid gap-5 pt-6 sm:grid-cols-2">
                                    <div className="grid gap-2 sm:col-span-2">
                                        <Label htmlFor="mail_host">
                                            SMTP host
                                        </Label>
                                        <Input
                                            id="mail_host"
                                            name="mail_host"
                                            defaultValue={
                                                settings.mail_host ?? ''
                                            }
                                            placeholder="smtp.example.com"
                                        />
                                        <InputError
                                            message={errors.mail_host}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="mail_port">Port</Label>
                                        <Input
                                            id="mail_port"
                                            name="mail_port"
                                            type="number"
                                            min="1"
                                            max="65535"
                                            defaultValue={
                                                settings.mail_port ?? 587
                                            }
                                        />
                                        <InputError
                                            message={errors.mail_port}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="mail_scheme">
                                            Transport security
                                        </Label>
                                        <select
                                            id="mail_scheme"
                                            name="mail_scheme"
                                            defaultValue={
                                                settings.mail_scheme ?? 'smtp'
                                            }
                                            className="h-10 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                        >
                                            <option value="smtp">
                                                STARTTLS / SMTP
                                            </option>
                                            <option value="smtps">
                                                Implicit TLS / SMTPS
                                            </option>
                                        </select>
                                        <InputError
                                            message={errors.mail_scheme}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="mail_username">
                                            Username
                                        </Label>
                                        <Input
                                            id="mail_username"
                                            name="mail_username"
                                            autoComplete="username"
                                            defaultValue={
                                                settings.mail_username ?? ''
                                            }
                                        />
                                        <InputError
                                            message={errors.mail_username}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="mail_password">
                                            Password
                                        </Label>
                                        <Input
                                            id="mail_password"
                                            name="mail_password"
                                            type="password"
                                            autoComplete="new-password"
                                            placeholder={
                                                settings.has_mail_password
                                                    ? 'Saved. Enter a new value to rotate it.'
                                                    : 'SMTP password'
                                            }
                                        />
                                        <InputError
                                            message={errors.mail_password}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="mail_from_address">
                                            From email
                                        </Label>
                                        <Input
                                            id="mail_from_address"
                                            name="mail_from_address"
                                            type="email"
                                            defaultValue={
                                                settings.mail_from_address ?? ''
                                            }
                                            placeholder="access@example.com"
                                        />
                                        <InputError
                                            message={errors.mail_from_address}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="mail_from_name">
                                            From name
                                        </Label>
                                        <Input
                                            id="mail_from_name"
                                            name="mail_from_name"
                                            defaultValue={
                                                settings.mail_from_name ??
                                                settings.app_name
                                            }
                                        />
                                        <InputError
                                            message={errors.mail_from_name}
                                        />
                                    </div>
                                </CardContent>
                            </Card>

                            <Button disabled={processing}>
                                {processing ? <Spinner /> : <Save />}
                                Save application settings
                            </Button>
                        </>
                    )}
                </Form>

                <FactoryResetCard
                    confirmationPhrase={factoryResetConfirmationPhrase}
                />
            </div>
        </>
    );
}

ApplicationSettings.layout = {
    breadcrumbs: [{ title: 'Admin settings', href: edit() }],
};

function FactoryResetCard({
    confirmationPhrase,
}: {
    confirmationPhrase: string;
}) {
    const [confirmation, setConfirmation] = useState('');

    return (
        <Card className="border-destructive/35">
            <CardHeader className="border-b px-4 pb-4 sm:px-6">
                <CardTitle className="flex items-center gap-2 text-destructive">
                    <AlertTriangle className="size-4" />
                    Factory reset
                </CardTitle>
                <CardDescription>
                    Permanently erase Crucible users, roles, providers,
                    connections, requests, sessions, settings, and audit
                    records. Target databases are never changed.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-4 pt-6 sm:flex-row sm:items-center sm:justify-between">
                <p className="max-w-2xl text-sm text-muted-foreground">
                    This returns the control plane to the initial web setup.
                    This action cannot be undone.
                </p>

                <Dialog>
                    <DialogTrigger asChild>
                        <Button variant="destructive" className="shrink-0">
                            Factory reset
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                Factory reset Crucible DB?
                            </DialogTitle>
                            <DialogDescription>
                                All control-plane data, including audit records,
                                will be permanently deleted. The configured
                                target databases and their data will not be
                                touched.
                            </DialogDescription>
                        </DialogHeader>

                        <Form
                            {...factoryReset.form()}
                            disableWhileProcessing
                            onSuccess={() => setConfirmation('')}
                            className="grid gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="factory_reset_confirmation">
                                            Type {confirmationPhrase} to confirm
                                        </Label>
                                        <Input
                                            id="factory_reset_confirmation"
                                            name="confirmation"
                                            value={confirmation}
                                            onChange={(event) =>
                                                setConfirmation(
                                                    event.target.value,
                                                )
                                            }
                                            autoComplete="off"
                                        />
                                        <InputError
                                            message={errors.confirmation}
                                        />
                                    </div>

                                    <DialogFooter>
                                        <DialogClose asChild>
                                            <Button
                                                type="button"
                                                variant="outline"
                                            >
                                                Cancel
                                            </Button>
                                        </DialogClose>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={
                                                processing ||
                                                confirmation !==
                                                    confirmationPhrase
                                            }
                                        >
                                            {processing && <Spinner />}
                                            Erase and restart setup
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </CardContent>
        </Card>
    );
}
