import { Form, Head, Link } from '@inertiajs/react';
import { Check, Database, KeyRound, Plus, Server, X } from 'lucide-react';
import { useState } from 'react';
import DatabaseConnectionController from '@/actions/App/Http/Controllers/DatabaseConnectionController';
import { PageHeader } from '@/components/crucible/page-header';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, show } from '@/routes/connections';

type Driver = {
    value: string;
    label: string;
    default_port: number;
};

type ConnectionFormData = {
    id: number;
    name: string;
    driver: string;
    host: string;
    port: number;
    database: string;
    username: string;
    ssl_mode: string | null;
    is_active: boolean;
} | null;

type Props = {
    connection: ConnectionFormData;
    drivers: Driver[];
    defaults?: {
        driver: string;
        host: string;
        port: number;
        ssl_mode: string | null;
    };
};

export default function ConnectionForm({
    connection,
    drivers,
    defaults,
}: Props) {
    const isEditing = Boolean(connection);
    const action = connection
        ? DatabaseConnectionController.update.form(connection.id)
        : DatabaseConnectionController.store.form();
    const initialDriver =
        connection?.driver ?? defaults?.driver ?? drivers[0]?.value ?? '';
    const initialPort =
        connection?.port ??
        defaults?.port ??
        drivers.find((driver) => driver.value === initialDriver)
            ?.default_port ??
        5432;
    const [driver, setDriver] = useState(initialDriver);
    const [port, setPort] = useState(initialPort);

    function changeDriver(value: string) {
        setDriver(value);

        const selectedDriver = drivers.find(
            (driverOption) => driverOption.value === value,
        );

        if (selectedDriver) {
            setPort(selectedDriver.default_port);
        }
    }

    return (
        <>
            <Head title={isEditing ? 'Edit connection' : 'New connection'} />

            <div className="crucible-page">
                <PageHeader
                    icon={Database}
                    title={isEditing ? 'Edit Connection' : 'New Connection'}
                    description={
                        isEditing
                            ? `${connection?.host}:${connection?.port} / ${connection?.database}`
                            : 'Register a database target for governed query execution.'
                    }
                />

                <section className="overflow-hidden border-y bg-card sm:rounded-lg sm:border">
                    <div className="border-b px-4 py-3 sm:px-5">
                        <h2 className="text-sm font-semibold">
                            Connection setup
                        </h2>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Define the target, server, and credentials in three
                            short steps.
                        </p>
                    </div>
                    <Form
                        {...action}
                        options={{
                            preserveScroll: true,
                            preserveState: 'errors',
                        }}
                    >
                        {({ processing, errors }) => (
                            <>
                                {!isEditing && defaults?.host && (
                                    <div className="mx-4 mt-4 flex gap-3 rounded-md border bg-accent/40 p-3 text-sm sm:mx-6">
                                        <Server className="mt-0.5 size-4 shrink-0 text-primary" />
                                        <div>
                                            <p className="font-medium">
                                                Shared server settings are ready
                                            </p>
                                            <p className="mt-1 text-muted-foreground">
                                                {defaults.host}:{defaults.port}{' '}
                                                is carried over. Add the next
                                                database identity and
                                                credentials.
                                            </p>
                                        </div>
                                    </div>
                                )}

                                <section className="grid gap-5 px-4 py-6 sm:px-6 lg:grid-cols-[minmax(11rem,0.55fr)_minmax(0,2fr)] lg:gap-8">
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <Database className="size-4 text-muted-foreground" />
                                            <h2 className="text-sm font-semibold">
                                                Identity
                                            </h2>
                                        </div>
                                        <p className="mt-2 max-w-xs text-sm text-muted-foreground">
                                            Give this connection a clear name
                                            and choose the database it opens.
                                        </p>
                                    </div>

                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="name">
                                                Connection name
                                            </Label>
                                            <Input
                                                id="name"
                                                name="name"
                                                defaultValue={
                                                    connection?.name ?? ''
                                                }
                                                placeholder="Production reporting"
                                                autoFocus={!isEditing}
                                                required
                                            />
                                            <InputError message={errors.name} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="database">
                                                Database
                                            </Label>
                                            <Input
                                                id="database"
                                                name="database"
                                                defaultValue={
                                                    connection?.database ?? ''
                                                }
                                                placeholder="app_production"
                                                required
                                            />
                                            <InputError
                                                message={errors.database}
                                            />
                                        </div>
                                    </div>
                                </section>

                                <section className="grid gap-5 border-t px-4 py-6 sm:px-6 lg:grid-cols-[minmax(11rem,0.55fr)_minmax(0,2fr)] lg:gap-8">
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <Server className="size-4 text-muted-foreground" />
                                            <h2 className="text-sm font-semibold">
                                                Server
                                            </h2>
                                        </div>
                                        <p className="mt-2 max-w-xs text-sm text-muted-foreground">
                                            The port follows the selected driver
                                            automatically. You can still
                                            override it.
                                        </p>
                                    </div>

                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="driver">
                                                Driver
                                            </Label>
                                            <select
                                                id="driver"
                                                name="driver"
                                                value={driver}
                                                onChange={(event) =>
                                                    changeDriver(
                                                        event.target.value,
                                                    )
                                                }
                                                className="h-9 rounded-md border border-input bg-card px-3 text-sm transition-[color,border-color,box-shadow] duration-150 ease-out outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/30 motion-reduce:transition-none"
                                                required
                                            >
                                                {drivers.map((driverOption) => (
                                                    <option
                                                        key={driverOption.value}
                                                        value={
                                                            driverOption.value
                                                        }
                                                    >
                                                        {driverOption.label}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError
                                                message={errors.driver}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="port">Port</Label>
                                            <Input
                                                id="port"
                                                name="port"
                                                type="number"
                                                min={1}
                                                max={65535}
                                                value={port}
                                                onChange={(event) =>
                                                    setPort(
                                                        Number(
                                                            event.target.value,
                                                        ),
                                                    )
                                                }
                                                required
                                            />
                                            <InputError message={errors.port} />
                                        </div>

                                        <div className="grid gap-2 md:col-span-2">
                                            <Label htmlFor="host">Host</Label>
                                            <Input
                                                id="host"
                                                name="host"
                                                defaultValue={
                                                    connection?.host ??
                                                    defaults?.host ??
                                                    ''
                                                }
                                                placeholder="db.internal.example.com"
                                                required
                                            />
                                            <InputError message={errors.host} />
                                        </div>

                                        <div className="grid gap-2 md:col-span-2">
                                            <Label htmlFor="ssl_mode">
                                                SSL mode
                                            </Label>
                                            <Input
                                                id="ssl_mode"
                                                name="ssl_mode"
                                                defaultValue={
                                                    connection?.ssl_mode ??
                                                    defaults?.ssl_mode ??
                                                    ''
                                                }
                                                placeholder="prefer"
                                            />
                                            <InputError
                                                message={errors.ssl_mode}
                                            />
                                        </div>
                                    </div>
                                </section>

                                <section className="grid gap-5 border-t px-4 py-6 sm:px-6 lg:grid-cols-[minmax(11rem,0.55fr)_minmax(0,2fr)] lg:gap-8">
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <KeyRound className="size-4 text-muted-foreground" />
                                            <h2 className="text-sm font-semibold">
                                                Credentials
                                            </h2>
                                        </div>
                                        <p className="mt-2 max-w-xs text-sm text-muted-foreground">
                                            Credentials are stored securely and
                                            are never carried into the next
                                            connection.
                                        </p>
                                    </div>

                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="username">
                                                Username
                                            </Label>
                                            <Input
                                                id="username"
                                                name="username"
                                                defaultValue={
                                                    connection?.username ?? ''
                                                }
                                                autoComplete="off"
                                                required
                                            />
                                            <InputError
                                                message={errors.username}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="password">
                                                Password
                                            </Label>
                                            <Input
                                                id="password"
                                                name="password"
                                                type="password"
                                                required={!isEditing}
                                                autoComplete="new-password"
                                            />
                                            <InputError
                                                message={errors.password}
                                            />
                                        </div>

                                        <label className="flex min-h-12 items-center gap-3 rounded-md border bg-card px-3 text-sm md:col-span-2">
                                            <input
                                                type="hidden"
                                                name="is_active"
                                                value="0"
                                            />
                                            <input
                                                id="is_active"
                                                name="is_active"
                                                type="checkbox"
                                                value="1"
                                                defaultChecked={
                                                    connection?.is_active ??
                                                    true
                                                }
                                                className="size-4 rounded border-input"
                                            />
                                            <span>
                                                <span className="font-medium">
                                                    Active
                                                </span>
                                                <span className="mt-0.5 block text-xs text-muted-foreground">
                                                    Allow this connection to
                                                    receive query requests
                                                    immediately.
                                                </span>
                                            </span>
                                        </label>
                                    </div>
                                </section>

                                <div className="flex flex-wrap gap-2 border-t bg-muted/10 px-4 py-4 sm:px-5">
                                    <Button disabled={processing}>
                                        <Check />
                                        {processing
                                            ? 'Saving...'
                                            : 'Save connection'}
                                    </Button>
                                    {!isEditing && (
                                        <Button
                                            name="create_another"
                                            value="1"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            <Plus />
                                            Save & add another
                                        </Button>
                                    )}
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="sm:ml-auto"
                                        asChild
                                    >
                                        <Link
                                            href={
                                                connection
                                                    ? show(connection.id)
                                                    : index()
                                            }
                                        >
                                            <X />
                                            Cancel
                                        </Link>
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </section>
            </div>
        </>
    );
}

ConnectionForm.layout = {
    breadcrumbs: [
        {
            title: 'Connections',
            href: index(),
        },
    ],
};
