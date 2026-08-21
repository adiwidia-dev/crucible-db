import { Form, Head, Link } from '@inertiajs/react';
import { BellOff, BellRing, Mail, Save, SlidersHorizontal } from 'lucide-react';
import DatabaseConnectionController from '@/actions/App/Http/Controllers/DatabaseConnectionController';
import NotificationSubscriptionController from '@/actions/App/Http/Controllers/NotificationSubscriptionController';
import QueryRequestController from '@/actions/App/Http/Controllers/QueryRequestController';
import NotificationPreferencesController from '@/actions/App/Http/Controllers/Settings/NotificationPreferencesController';
import AppearanceTabs from '@/components/appearance-tabs';
import { PageHeader } from '@/components/crucible/page-header';
import { Button } from '@/components/ui/button';
import { edit } from '@/routes/preferences';

type Preferences = {
    email_approvals: boolean;
    email_execution_completed: boolean;
    email_execution_failed: boolean;
    email_sessions: boolean;
    email_connection_failed: boolean;
};

type Subscription = {
    id: number;
    type: 'query_request' | 'database_connection';
    subscribable_id: number;
    title: string;
    detail: string;
};

export default function Preferences({
    preferences,
    subscriptions,
}: {
    preferences: Preferences;
    subscriptions: Subscription[];
}) {
    return (
        <>
            <Head title="Preferences" />

            <div className="crucible-page">
                <PageHeader
                    title="Preferences"
                    description="Set your interface and personal operational update preferences."
                />

                <section className="max-w-3xl overflow-hidden border-y bg-card sm:rounded-lg sm:border">
                    <div className="border-b px-4 py-3 sm:px-5">
                        <div className="flex items-center gap-2 text-sm font-semibold">
                            <SlidersHorizontal className="size-4 text-muted-foreground" />
                            Appearance
                        </div>
                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                            Choose the colour mode applied across your
                            workspace.
                        </p>
                    </div>
                    <div className="px-4 py-5 sm:px-5">
                        <AppearanceTabs />
                    </div>
                </section>

                <Form
                    {...NotificationPreferencesController.update.form()}
                    disableWhileProcessing
                    className="mt-6 max-w-3xl overflow-hidden border-y bg-card sm:rounded-lg sm:border"
                >
                    {({ processing }) => (
                        <>
                            <div className="border-b px-4 py-3 sm:px-5">
                                <div className="flex items-center gap-2 text-sm font-semibold">
                                    <Mail className="size-4 text-muted-foreground" />
                                    Email updates
                                </div>
                                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                    Choose which operational updates should also
                                    be sent to your email address.
                                </p>
                            </div>
                            <div className="divide-y">
                                <PreferenceField
                                    name="email_approvals"
                                    defaultChecked={preferences.email_approvals}
                                    title="Approval decisions"
                                    description="Approved, rejected, and reapproval-required requests."
                                />
                                <PreferenceField
                                    name="email_execution_completed"
                                    defaultChecked={
                                        preferences.email_execution_completed
                                    }
                                    title="Completed deployment batches"
                                    description="Successful ordered SQL batch outcomes."
                                />
                                <PreferenceField
                                    name="email_execution_failed"
                                    defaultChecked={
                                        preferences.email_execution_failed
                                    }
                                    title="Failed deployment batches"
                                    description="Immediate alert when a batch stops at a failed statement."
                                />
                                <PreferenceField
                                    name="email_sessions"
                                    defaultChecked={preferences.email_sessions}
                                    title="Query access sessions"
                                    description="Session start and expiry updates."
                                />
                                <PreferenceField
                                    name="email_connection_failed"
                                    defaultChecked={
                                        preferences.email_connection_failed
                                    }
                                    title="Failed connection tests"
                                    description="Connection health issues for targets you created or administer."
                                />
                            </div>
                            <div className="flex justify-end border-t px-4 py-3 sm:px-5">
                                <Button disabled={processing}>
                                    <Save />
                                    Save preferences
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <section className="mt-6 max-w-3xl overflow-hidden border-y bg-card sm:rounded-lg sm:border">
                    <div className="border-b px-4 py-3 sm:px-5">
                        <div className="flex items-center gap-2 text-sm font-semibold">
                            <BellRing className="size-4 text-muted-foreground" />
                            Watched resources
                        </div>
                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                            Updates for requests and connections you choose to
                            watch.
                        </p>
                    </div>
                    {subscriptions.length === 0 ? (
                        <div className="px-4 py-5 text-sm text-muted-foreground sm:px-5">
                            You are not watching any requests or connections
                            yet.
                        </div>
                    ) : (
                        <div className="divide-y">
                            {subscriptions.map((subscription) => (
                                <div
                                    key={subscription.id}
                                    className="flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-5"
                                >
                                    <div className="min-w-0">
                                        <Link
                                            href={
                                                subscription.type ===
                                                'query_request'
                                                    ? QueryRequestController.show(
                                                          subscription.subscribable_id,
                                                      )
                                                    : DatabaseConnectionController.show(
                                                          subscription.subscribable_id,
                                                      )
                                            }
                                            className="block truncate text-sm font-medium hover:underline"
                                        >
                                            {subscription.title}
                                        </Link>
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            {subscription.detail}
                                        </p>
                                    </div>
                                    <Form
                                        {...(subscription.type ===
                                        'query_request'
                                            ? NotificationSubscriptionController.destroyQueryRequest.form(
                                                  subscription.subscribable_id,
                                              )
                                            : NotificationSubscriptionController.destroyDatabaseConnection.form(
                                                  subscription.subscribable_id,
                                              ))}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                <BellOff />
                                                Stop watching
                                            </Button>
                                        )}
                                    </Form>
                                </div>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

function PreferenceField({
    name,
    defaultChecked,
    title,
    description,
}: {
    name: keyof Preferences;
    defaultChecked: boolean;
    title: string;
    description: string;
}) {
    return (
        <label className="flex cursor-pointer items-start gap-3 px-4 py-4 sm:px-5">
            <input type="hidden" name={name} value="0" />
            <input
                type="checkbox"
                name={name}
                value="1"
                defaultChecked={defaultChecked}
                className="mt-0.5 size-4 rounded border-input text-primary focus:ring-ring"
            />
            <span>
                <span className="block text-sm font-medium">{title}</span>
                <span className="mt-0.5 block text-xs leading-5 text-muted-foreground">
                    {description}
                </span>
            </span>
        </label>
    );
}

Preferences.layout = {
    breadcrumbs: [{ title: 'Preferences', href: edit() }],
};
