import { Form, Head } from '@inertiajs/react';
import { Bell, Save, ShieldAlert, UsersRound } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import NotificationSettingsController from '@/actions/App/Http/Controllers/Settings/NotificationSettingsController';
import { PageHeader } from '@/components/crucible/page-header';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { edit } from '@/routes/notification-settings';

type Settings = {
    notifications_in_app_enabled: boolean;
    notifications_email_enabled: boolean;
    notifications_review_enabled: boolean;
    notifications_execution_completed_enabled: boolean;
    notifications_execution_failed_enabled: boolean;
    notifications_query_access_enabled: boolean;
    notifications_connection_failed_enabled: boolean;
};

type Administrator = {
    id: number;
    name: string;
    email: string;
    is_operational_alert_recipient: boolean;
};

export default function NotificationSettings({
    settings,
    administrators,
}: {
    settings: Settings;
    administrators: Administrator[];
}) {
    return (
        <>
            <Head title="Notification policy" />

            <div className="crucible-page">
                <PageHeader
                    title="Notification policy"
                    description="Control operational delivery and define who receives critical database alerts. Notification content never includes SQL, credentials, or result data."
                />

                <Form
                    {...NotificationSettingsController.update.form()}
                    disableWhileProcessing
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <section className="max-w-3xl overflow-hidden border-y bg-card sm:rounded-lg sm:border">
                                <SectionHeader
                                    icon={Bell}
                                    title="Delivery channels"
                                    description="In-app delivery powers each user’s notification inbox. Email follows the individual preference for each event."
                                />
                                <div className="divide-y">
                                    <SettingField
                                        name="notifications_in_app_enabled"
                                        defaultChecked={
                                            settings.notifications_in_app_enabled
                                        }
                                        title="In-app notifications"
                                        description="Show enabled operational events in the application notification inbox."
                                    />
                                    <SettingField
                                        name="notifications_email_enabled"
                                        defaultChecked={
                                            settings.notifications_email_enabled
                                        }
                                        title="Email notifications"
                                        description="Allow eligible events to be delivered through the configured SMTP transport."
                                    />
                                </div>
                            </section>

                            <section className="max-w-3xl overflow-hidden border-y bg-card sm:rounded-lg sm:border">
                                <SectionHeader
                                    icon={ShieldAlert}
                                    title="Event policy"
                                    description="Disable only routine events when needed. Failed batch and connection-test alerts remain visible in audit logs regardless of this delivery policy."
                                />
                                <div className="divide-y">
                                    <SettingField
                                        name="notifications_review_enabled"
                                        defaultChecked={
                                            settings.notifications_review_enabled
                                        }
                                        title="Reviews and decisions"
                                        description="Review requests, approvals, rejections, and reapproval requirements."
                                    />
                                    <SettingField
                                        name="notifications_execution_completed_enabled"
                                        defaultChecked={
                                            settings.notifications_execution_completed_enabled
                                        }
                                        title="Completed deployment batches"
                                        description="Successful ordered SQL batch outcomes for requesters and executors."
                                    />
                                    <SettingField
                                        name="notifications_execution_failed_enabled"
                                        defaultChecked={
                                            settings.notifications_execution_failed_enabled
                                        }
                                        title="Failed deployment batches"
                                        description="Immediate critical alerts for requesters, reviewers, and operational administrators."
                                    />
                                    <SettingField
                                        name="notifications_query_access_enabled"
                                        defaultChecked={
                                            settings.notifications_query_access_enabled
                                        }
                                        title="Query access sessions"
                                        description="Session started and expired events."
                                    />
                                    <SettingField
                                        name="notifications_connection_failed_enabled"
                                        defaultChecked={
                                            settings.notifications_connection_failed_enabled
                                        }
                                        title="Failed connection tests"
                                        description="Critical alerts for connection creators and operational administrators."
                                    />
                                </div>
                            </section>

                            <section className="max-w-3xl overflow-hidden border-y bg-card sm:rounded-lg sm:border">
                                <SectionHeader
                                    icon={UsersRound}
                                    title="Operational alert recipients"
                                    description="These administrators receive failed-batch, session-expiry, and connection-failure alerts. If no one is selected, all active administrators receive those alerts."
                                />
                                <div className="divide-y">
                                    {administrators.length === 0 ? (
                                        <p className="px-4 py-5 text-sm text-muted-foreground sm:px-5">
                                            No active administrators are
                                            available to receive operational
                                            alerts.
                                        </p>
                                    ) : (
                                        administrators.map((administrator) => (
                                            <label
                                                key={administrator.id}
                                                className="flex cursor-pointer items-center gap-3 px-4 py-3.5 sm:px-5"
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="operational_recipient_ids[]"
                                                    value={administrator.id}
                                                    defaultChecked={
                                                        administrator.is_operational_alert_recipient
                                                    }
                                                    className="size-4 rounded border-input text-primary focus:ring-ring"
                                                />
                                                <span className="min-w-0">
                                                    <span className="block text-sm font-medium">
                                                        {administrator.name}
                                                    </span>
                                                    <span className="block truncate text-xs text-muted-foreground">
                                                        {administrator.email}
                                                    </span>
                                                </span>
                                            </label>
                                        ))
                                    )}
                                </div>
                                <InputError
                                    className="px-4 pb-4 sm:px-5"
                                    message={errors.operational_recipient_ids}
                                />
                            </section>

                            <Button disabled={processing}>
                                <Save />
                                Save notification policy
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

function SectionHeader({
    icon: Icon,
    title,
    description,
}: {
    icon: LucideIcon;
    title: string;
    description: string;
}) {
    return (
        <div className="border-b px-4 py-3 sm:px-5">
            <h2 className="flex items-center gap-2 text-sm font-semibold">
                <Icon className="size-4 text-muted-foreground" />
                {title}
            </h2>
            <p className="mt-1 text-xs leading-5 text-muted-foreground">
                {description}
            </p>
        </div>
    );
}

function SettingField({
    name,
    defaultChecked,
    title,
    description,
}: {
    name: keyof Settings;
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

NotificationSettings.layout = {
    breadcrumbs: [{ title: 'Notification policy', href: edit() }],
};
