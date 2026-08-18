import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import { PageHeader } from '@/components/crucible/page-header';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title="Appearance settings" />

            <h1 className="sr-only">Appearance settings</h1>

            <div className="crucible-page">
                <PageHeader
                    title="Appearance"
                    description="Choose the visual mode that is most comfortable for your workspace."
                />
                <section className="max-w-3xl overflow-hidden border-y bg-card sm:rounded-lg sm:border">
                    <div className="border-b px-4 py-3 sm:px-5">
                        <h2 className="text-sm font-semibold">
                            Interface preference
                        </h2>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Choose the colour mode applied across your
                            workspace.
                        </p>
                    </div>
                    <div className="px-4 py-6 sm:px-5">
                        <AppearanceTabs />
                    </div>
                </section>
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Appearance settings',
            href: editAppearance(),
        },
    ],
};
