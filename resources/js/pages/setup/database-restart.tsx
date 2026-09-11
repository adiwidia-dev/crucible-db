import { Head, Link } from '@inertiajs/react';
import { RefreshCw, RotateCw } from 'lucide-react';
import SetupController from '@/actions/App/Http/Controllers/SetupController';
import { Button } from '@/components/ui/button';

export default function SetupDatabaseRestart() {
    return (
        <>
            <Head title="Restart required" />

            <div className="grid gap-5">
                <div className="rounded-md border border-amber-200 bg-amber-50/70 p-4 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                    <div className="flex items-center gap-2 font-medium">
                        <RotateCw className="size-4 text-amber-600 dark:text-amber-400" />
                        Configuration saved safely
                    </div>
                    <p className="mt-1.5 leading-6 text-amber-900/75 dark:text-amber-100/70">
                        The encrypted database configuration is ready. Crucible
                        has not switched this running process to it.
                    </p>
                </div>

                <div className="grid gap-3 text-sm leading-6 text-muted-foreground">
                    <p>
                        Restart the application server, queue worker, and
                        scheduler so every long-running process activates the
                        same database configuration.
                    </p>
                    <div className="rounded-md border bg-muted/30 p-3">
                        <p className="font-medium text-foreground">
                            Docker development
                        </p>
                        <code className="mt-1 block overflow-x-auto text-xs">
                            docker compose restart app worker scheduler
                        </code>
                    </div>
                    <p>
                        The native proxy does not connect to the application
                        database directly and does not need to be reconfigured.
                    </p>
                </div>

                <Button asChild className="w-full">
                    <Link href={SetupController.show()}>
                        <RefreshCw /> Check activation
                    </Link>
                </Button>
            </div>
        </>
    );
}

SetupDatabaseRestart.layout = {
    title: 'Restart required',
    description:
        'Activate the prepared application database before creating the first administrator.',
};
