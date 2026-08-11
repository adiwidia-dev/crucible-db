import { Form, Head, Link } from '@inertiajs/react';
import { Check, Database, KeyRound, Server, X } from 'lucide-react';
import DatabaseConnectionController from '@/actions/App/Http/Controllers/DatabaseConnectionController';
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
};

export default function ConnectionForm({ connection, drivers }: Props) {
    const isEditing = Boolean(connection);
    const action = connection
        ? DatabaseConnectionController.update.form(connection.id)
        : DatabaseConnectionController.store.form();

    return (
        <>
            <Head title={isEditing ? 'Edit connection' : 'New connection'} />

            <div className="crucible-page">
                <PageHeader
                    icon={Database}
                    eyebrow="Connection"
                    title={isEditing ? 'Edit Connection' : 'New Connection'}
                    description={
                        isEditing
                            ? `${connection?.host}:${connection?.port} / ${connection?.database}`
                            : 'Register a database target for governed query execution.'
                    }
                />

                <Card>
                    <CardHeader className="border-b px-4 pb-4 sm:px-6">
                        <CardTitle>Connection Profile</CardTitle>
                        <CardDescription>
                            Endpoint, credentials, and availability.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="pt-6">
                        <Form
                            {...action}
                            options={{ preserveScroll: true }}
                            className="grid gap-6"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <section className="grid gap-4 rounded-lg border bg-muted/25 p-4 lg:grid-cols-2">
                                        <div className="flex items-center gap-2 lg:col-span-2">
                                            <Server className="size-4 text-muted-foreground" />
                                            <h2 className="text-sm font-medium">
                                                Target
                                            </h2>
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="name">Name</Label>
                                            <Input
                                                id="name"
                                                name="name"
                                                defaultValue={
                                                    connection?.name ?? ''
                                                }
                                                required
                                            />
                                            <InputError message={errors.name} />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="driver">
                                                Driver
                                            </Label>
                                            <select
                                                id="driver"
                                                name="driver"
                                                defaultValue={
                                                    connection?.driver ??
                                                    drivers[0]?.value
                                                }
                                                className="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                                required
                                            >
                                                {drivers.map((driver) => (
                                                    <option
                                                        key={driver.value}
                                                        value={driver.value}
                                                    >
                                                        {driver.label}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError
                                                message={errors.driver}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="host">Host</Label>
                                            <Input
                                                id="host"
                                                name="host"
                                                defaultValue={
                                                    connection?.host ?? ''
                                                }
                                                required
                                            />
                                            <InputError message={errors.host} />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="port">Port</Label>
                                            <Input
                                                id="port"
                                                name="port"
                                                type="number"
                                                defaultValue={
                                                    connection?.port ??
                                                    drivers[0]?.default_port ??
                                                    5432
                                                }
                                                required
                                            />
                                            <InputError message={errors.port} />
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
                                                required
                                            />
                                            <InputError
                                                message={errors.database}
                                            />
                                        </div>
                                    </section>

                                    <section className="grid gap-4 rounded-lg border bg-muted/25 p-4 lg:grid-cols-2">
                                        <div className="flex items-center gap-2 lg:col-span-2">
                                            <KeyRound className="size-4 text-muted-foreground" />
                                            <h2 className="text-sm font-medium">
                                                Credentials and Transport
                                            </h2>
                                        </div>

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

                                        <div className="grid gap-2">
                                            <Label htmlFor="ssl_mode">
                                                SSL Mode
                                            </Label>
                                            <Input
                                                id="ssl_mode"
                                                name="ssl_mode"
                                                defaultValue={
                                                    connection?.ssl_mode ?? ''
                                                }
                                                placeholder="prefer"
                                            />
                                            <InputError
                                                message={errors.ssl_mode}
                                            />
                                        </div>

                                        <label className="flex h-9 items-center gap-3 self-end rounded-md border bg-background px-3 text-sm shadow-xs">
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
                                            <span>Active</span>
                                        </label>
                                    </section>

                                    <div className="flex items-center gap-3">
                                        <Button disabled={processing}>
                                            <Check />
                                            Save
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
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
                    </CardContent>
                </Card>
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
