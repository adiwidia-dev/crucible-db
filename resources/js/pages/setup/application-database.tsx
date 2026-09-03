import { Form, Head } from '@inertiajs/react';
import { ArrowRight, Database, HardDrive, Server } from 'lucide-react';
import { useState } from 'react';
import SetupController from '@/actions/App/Http/Controllers/SetupController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Driver = {
    value: 'sqlite' | 'mysql' | 'pgsql';
    label: string;
    default_port: number | null;
};

type Props = {
    drivers: Driver[];
    sqlite_path: string;
};

export default function SetupApplicationDatabase({
    drivers,
    sqlite_path,
}: Props) {
    const [driver, setDriver] = useState<Driver>(
        drivers.find((item) => item.value === 'sqlite') ?? drivers[0],
    );
    const isNetworkDatabase = driver.value !== 'sqlite';

    return (
        <>
            <Head title="Choose the application database" />

            <div className="grid gap-5">
                <div className="rounded-md border border-blue-200 bg-blue-50/70 p-4 text-sm text-blue-950 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-100">
                    <div className="flex items-center gap-2 font-medium">
                        <Database className="size-4 text-blue-600 dark:text-blue-400" />
                        Crucible control-plane storage
                    </div>
                    <p className="mt-1.5 leading-6 text-blue-900/75 dark:text-blue-100/70">
                        This database stores users, requests, policies, audit
                        records, and native-client control state. It is not a
                        database target managed by Crucible.
                    </p>
                </div>

                <Form
                    {...SetupController.storeApplicationDatabase.form()}
                    disableWhileProcessing
                    resetOnError={['password']}
                    className="grid gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="driver">Database type</Label>
                                <select
                                    id="driver"
                                    name="driver"
                                    value={driver.value}
                                    onChange={(event) => {
                                        const selected = drivers.find(
                                            (item) =>
                                                item.value ===
                                                event.currentTarget.value,
                                        );

                                        if (selected) {
                                            setDriver(selected);
                                        }
                                    }}
                                    className="h-11 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
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

                            {!isNetworkDatabase ? (
                                <div className="flex gap-3 rounded-md border bg-muted/30 p-4">
                                    <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-300">
                                        <HardDrive className="size-5" />
                                    </span>
                                    <div className="min-w-0">
                                        <p className="font-medium">
                                            Embedded SQLite
                                        </p>
                                        <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                            Best for a single Crucible instance.
                                            The existing persistent file will be
                                            used.
                                        </p>
                                        <code className="mt-2 block truncate rounded bg-muted px-2 py-1 text-xs text-muted-foreground">
                                            {sqlite_path}
                                        </code>
                                    </div>
                                </div>
                            ) : (
                                <div className="grid gap-4 rounded-md border bg-muted/20 p-4">
                                    <div className="flex items-start gap-3">
                                        <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                                            <Server className="size-5" />
                                        </span>
                                        <div>
                                            <p className="font-medium">
                                                Dedicated empty database
                                            </p>
                                            <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                                Crucible will test this
                                                connection and run its complete
                                                schema migration. Existing
                                                tables are never overwritten.
                                            </p>
                                        </div>
                                    </div>

                                    <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_9rem]">
                                        <div className="grid gap-2">
                                            <Label htmlFor="host">Host</Label>
                                            <Input
                                                id="host"
                                                name="host"
                                                required
                                                placeholder="database.internal"
                                            />
                                            <InputError message={errors.host} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="port">Port</Label>
                                            <Input
                                                key={driver.value}
                                                id="port"
                                                name="port"
                                                type="number"
                                                required
                                                defaultValue={
                                                    driver.default_port ?? ''
                                                }
                                            />
                                            <InputError message={errors.port} />
                                        </div>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="database">
                                            Database name
                                        </Label>
                                        <Input
                                            id="database"
                                            name="database"
                                            required
                                            placeholder="crucible"
                                        />
                                        <InputError message={errors.database} />
                                    </div>

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="username">
                                                Username
                                            </Label>
                                            <Input
                                                id="username"
                                                name="username"
                                                required
                                                autoComplete="username"
                                            />
                                            <InputError
                                                message={errors.username}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="password">
                                                Password
                                            </Label>
                                            <PasswordInput
                                                id="password"
                                                name="password"
                                                required
                                                autoComplete="new-password"
                                            />
                                            <InputError
                                                message={errors.password}
                                            />
                                        </div>
                                    </div>

                                    {driver.value === 'pgsql' && (
                                        <div className="grid gap-2">
                                            <Label htmlFor="pgsql_sslmode">
                                                PostgreSQL SSL mode
                                            </Label>
                                            <select
                                                id="pgsql_sslmode"
                                                name="pgsql_sslmode"
                                                defaultValue="prefer"
                                                className="h-10 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                            >
                                                <option value="disable">
                                                    Disable
                                                </option>
                                                <option value="prefer">
                                                    Prefer
                                                </option>
                                                <option value="require">
                                                    Require
                                                </option>
                                                <option value="verify-ca">
                                                    Verify CA
                                                </option>
                                                <option value="verify-full">
                                                    Verify identity
                                                </option>
                                            </select>
                                            <InputError
                                                message={errors.pgsql_sslmode}
                                            />
                                        </div>
                                    )}

                                    {driver.value === 'mysql' && (
                                        <div className="grid gap-2">
                                            <Label htmlFor="mysql_ssl_ca">
                                                MySQL CA file path
                                                <span className="font-normal text-muted-foreground">
                                                    {' '}
                                                    (optional)
                                                </span>
                                            </Label>
                                            <Input
                                                id="mysql_ssl_ca"
                                                name="mysql_ssl_ca"
                                                placeholder="/run/secrets/mysql-ca.pem"
                                            />
                                            <p className="text-xs leading-5 text-muted-foreground">
                                                Path on the Crucible application
                                                server, not your browser.
                                            </p>
                                            <InputError
                                                message={errors.mysql_ssl_ca}
                                            />
                                        </div>
                                    )}
                                </div>
                            )}

                            <p className="text-xs leading-5 text-muted-foreground">
                                Credentials are encrypted with the application
                                key. A process restart is required before the
                                selected database becomes active.
                            </p>

                            <Button className="w-full" disabled={processing}>
                                {processing ? <Spinner /> : <ArrowRight />}
                                {isNetworkDatabase
                                    ? 'Test and prepare database'
                                    : 'Use SQLite'}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

SetupApplicationDatabase.layout = {
    title: 'Choose the application database',
    description:
        'Select where Crucible stores its own control-plane and audit data.',
    wide: true,
};
