import { Form, Head } from '@inertiajs/react';
import { ArrowRight, Database, SkipForward } from 'lucide-react';
import { useState } from 'react';
import SetupController from '@/actions/App/Http/Controllers/SetupController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Driver = {
    value: 'mysql' | 'pgsql';
    label: string;
    default_port: number;
};

type Props = {
    drivers: Driver[];
};

export default function SetupConnection({ drivers }: Props) {
    const [driver, setDriver] = useState<Driver>(
        drivers[0] ?? {
            value: 'pgsql',
            label: 'PostgreSQL',
            default_port: 5432,
        },
    );

    return (
        <>
            <Head title="Connect a database" />

            <div className="grid gap-5">
                <div className="rounded-md border bg-muted/30 p-3 text-sm text-muted-foreground">
                    <div className="flex items-center gap-2 font-medium text-foreground">
                        <Database className="size-4 text-orange-600" /> First
                        database connection
                    </div>
                    <p className="mt-1">
                        Optional. You can add PostgreSQL or MySQL connections
                        now, or continue and configure them later.
                    </p>
                </div>
                <Form
                    {...SetupController.storeConnection.form()}
                    disableWhileProcessing
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Connection name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    placeholder="Production PostgreSQL"
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="driver">Database type</Label>
                                <select
                                    id="driver"
                                    name="driver"
                                    value={driver?.value}
                                    onChange={(event) =>
                                        setDriver(
                                            drivers.find(
                                                (item) =>
                                                    item.value ===
                                                    event.currentTarget.value,
                                            ) ?? drivers[0],
                                        )
                                    }
                                    className="h-10 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                >
                                    {drivers.map((item) => (
                                        <option
                                            key={item.value}
                                            value={item.value}
                                        >
                                            {item.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.driver} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="host">Host</Label>
                                <Input
                                    id="host"
                                    name="host"
                                    required
                                    placeholder="db.example.internal"
                                />
                                <InputError message={errors.host} />
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="port">Port</Label>
                                    <Input
                                        key={driver.value}
                                        id="port"
                                        name="port"
                                        type="number"
                                        defaultValue={driver.default_port}
                                        required
                                    />
                                    <InputError message={errors.port} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="database">Database</Label>
                                    <Input
                                        id="database"
                                        name="database"
                                        required
                                    />
                                    <InputError message={errors.database} />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="username">Username</Label>
                                <Input
                                    id="username"
                                    name="username"
                                    required
                                    autoComplete="username"
                                />
                                <InputError message={errors.username} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    autoComplete="new-password"
                                />
                                <InputError message={errors.password} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="ssl_mode">SSL mode</Label>
                                <Input
                                    id="ssl_mode"
                                    name="ssl_mode"
                                    placeholder="prefer"
                                />
                                <InputError message={errors.ssl_mode} />
                            </div>
                            <input type="hidden" name="is_active" value="1" />
                            <Button
                                className="mt-1 w-full"
                                disabled={processing}
                            >
                                {processing ? <Spinner /> : <ArrowRight />} Save
                                connection
                            </Button>
                        </>
                    )}
                </Form>
                <Form {...SetupController.skipConnection.form()}>
                    {({ processing }) => (
                        <Button
                            className="w-full"
                            variant="outline"
                            disabled={processing}
                        >
                            {processing ? <Spinner /> : <SkipForward />} Skip
                            for now
                        </Button>
                    )}
                </Form>
            </div>
        </>
    );
}

SetupConnection.layout = {
    title: 'Connect a database',
    description:
        'Optional setup step. Database connections can always be managed later.',
};
