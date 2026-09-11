import { Form, Head } from '@inertiajs/react';
import { AlertTriangle, KeyRound, Save, Terminal } from 'lucide-react';
import { useState } from 'react';
import AccessWorkflowSettingsController from '@/actions/App/Http/Controllers/Settings/AccessWorkflowSettingsController';
import { PageHeader } from '@/components/crucible/page-header';
import { SemanticIcon } from '@/components/crucible/semantic-icon';
import type { SemanticTone } from '@/components/crucible/semantic-icon';
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
import { Spinner } from '@/components/ui/spinner';
import { edit } from '@/routes/access-workflows';

type Props = {
    settings: {
        query_access_enabled: boolean;
        native_client_access_enabled: boolean;
        native_proxy_username_prefix: string;
    };
    usage: {
        active_query_sessions: number;
        active_native_sessions: number;
        query_role_grants: number;
        native_role_grants: number;
    };
};

function AccessFeatureField({
    id,
    name,
    title,
    description,
    checked,
    onCheckedChange,
    activeSessions,
    roleGrants,
    icon: Icon,
    tone,
}: {
    id: string;
    name: 'query_access_enabled' | 'native_client_access_enabled';
    title: string;
    description: string;
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    activeSessions: number;
    roleGrants: number;
    icon: typeof KeyRound;
    tone: SemanticTone;
}) {
    return (
        <label
            htmlFor={id}
            className="flex cursor-pointer items-start gap-3 rounded-md border bg-background p-4 transition-colors duration-150 ease-out hover:bg-muted/25 motion-reduce:transition-none"
        >
            <SemanticIcon icon={Icon} tone={checked ? tone : 'neutral'} />
            <span className="min-w-0 flex-1">
                <span className="block text-sm font-medium">{title}</span>
                <span className="mt-1 block text-xs leading-5 text-muted-foreground">
                    {description}
                </span>
                <span className="mt-2 block text-xs text-muted-foreground tabular-nums">
                    {roleGrants} configured role{' '}
                    {roleGrants === 1 ? 'grant' : 'grants'}
                    <span className="px-1.5 text-border">·</span>
                    {activeSessions} active{' '}
                    {activeSessions === 1 ? 'session' : 'sessions'}
                </span>
            </span>
            <span className="mt-0.5 shrink-0">
                <input type="hidden" name={name} value="0" />
                <input
                    id={id}
                    type="checkbox"
                    name={name}
                    value="1"
                    checked={checked}
                    onChange={(event) => onCheckedChange(event.target.checked)}
                    className="peer sr-only"
                />
                <span className="relative block h-6 w-11 rounded-full border border-input bg-muted transition-colors peer-checked:border-primary peer-checked:bg-primary peer-focus-visible:ring-3 peer-focus-visible:ring-ring/40 after:absolute after:top-0.5 after:left-0.5 after:size-5 after:rounded-full after:bg-background after:shadow-sm after:transition-transform peer-checked:after:translate-x-5 motion-reduce:after:transition-none" />
            </span>
        </label>
    );
}

export default function AccessWorkflows({ settings, usage }: Props) {
    const [queryAccessEnabled, setQueryAccessEnabled] = useState(
        settings.query_access_enabled,
    );
    const [nativeClientAccessEnabled, setNativeClientAccessEnabled] = useState(
        settings.native_client_access_enabled,
    );
    const [nativeProxyUsernamePrefix, setNativeProxyUsernamePrefix] = useState(
        settings.native_proxy_username_prefix,
    );
    const isDisablingQueryAccess =
        settings.query_access_enabled && !queryAccessEnabled;
    const isDisablingNativeClientAccess =
        settings.native_client_access_enabled && !nativeClientAccessEnabled;

    return (
        <>
            <Head title="Access workflows" />

            <div className="crucible-page">
                <PageHeader
                    title="Access workflows"
                    description="Choose which time-boxed access mechanisms are available across the workspace."
                />

                <Form
                    {...AccessWorkflowSettingsController.update.form()}
                    disableWhileProcessing
                    className="max-w-5xl space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <Card className="gap-0 overflow-hidden border-y py-0 sm:rounded-lg sm:border">
                                <CardHeader className="border-b px-4 py-3 sm:px-5">
                                    <CardTitle>Workflow availability</CardTitle>
                                    <CardDescription>
                                        Disabled workflows disappear from new
                                        requests and role policy forms. New
                                        sessions are blocked, while sessions
                                        already in progress continue until they
                                        end or expire.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid gap-3 px-4 py-5 sm:px-5 md:grid-cols-2">
                                    <AccessFeatureField
                                        id="query_access_enabled"
                                        name="query_access_enabled"
                                        title="Query Access"
                                        description="Allow governed browser sessions without requiring SQL in advance."
                                        checked={queryAccessEnabled}
                                        onCheckedChange={setQueryAccessEnabled}
                                        activeSessions={
                                            usage.active_query_sessions
                                        }
                                        roleGrants={usage.query_role_grants}
                                        icon={KeyRound}
                                        tone="info"
                                    />
                                    <AccessFeatureField
                                        id="native_client_access_enabled"
                                        name="native_client_access_enabled"
                                        title="Native Client Access"
                                        description="Allow approved database clients to connect through the Crucible CLI."
                                        checked={nativeClientAccessEnabled}
                                        onCheckedChange={
                                            setNativeClientAccessEnabled
                                        }
                                        activeSessions={
                                            usage.active_native_sessions
                                        }
                                        roleGrants={usage.native_role_grants}
                                        icon={Terminal}
                                        tone="native"
                                    />
                                    <InputError
                                        className="md:col-span-2"
                                        message={
                                            errors.query_access_enabled ??
                                            errors.native_client_access_enabled
                                        }
                                    />
                                </CardContent>
                            </Card>

                            <Card className="gap-0 overflow-hidden border-y py-0 sm:rounded-lg sm:border">
                                <CardHeader className="border-b px-4 py-3 sm:px-5">
                                    <CardTitle className="flex items-center gap-2">
                                        <KeyRound className="size-4 text-emerald-700 dark:text-emerald-300" />
                                        Native client credentials
                                    </CardTitle>
                                    <CardDescription>
                                        Customize how temporary native-client
                                        usernames are identified.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid gap-5 px-4 py-5 sm:px-5">
                                    <div className="grid max-w-xl gap-2">
                                        <Label htmlFor="native_proxy_username_prefix">
                                            Temporary username prefix
                                        </Label>
                                        <Input
                                            id="native_proxy_username_prefix"
                                            name="native_proxy_username_prefix"
                                            value={nativeProxyUsernamePrefix}
                                            onChange={(event) =>
                                                setNativeProxyUsernamePrefix(
                                                    event.target.value,
                                                )
                                            }
                                            minLength={2}
                                            maxLength={24}
                                            required
                                            autoComplete="off"
                                            spellCheck={false}
                                            aria-describedby="native_proxy_username_prefix_help native_proxy_username_preview"
                                            aria-invalid={
                                                errors.native_proxy_username_prefix
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                        <p
                                            id="native_proxy_username_prefix_help"
                                            className="text-xs leading-5 text-muted-foreground"
                                        >
                                            Use 2–24 lowercase letters, numbers,
                                            underscores, or hyphens. The first
                                            character must be a letter.
                                        </p>
                                        <InputError
                                            message={
                                                errors.native_proxy_username_prefix
                                            }
                                        />
                                    </div>

                                    <div
                                        id="native_proxy_username_preview"
                                        className="max-w-xl rounded-md border bg-muted/25 p-3"
                                    >
                                        <p className="text-xs font-medium text-muted-foreground">
                                            Username preview
                                        </p>
                                        <code className="mt-1 block max-w-full overflow-hidden text-sm font-medium text-ellipsis whitespace-nowrap">
                                            {nativeProxyUsernamePrefix ||
                                                'prefix_'}
                                            a1b2c3d4e5f6…
                                        </code>
                                    </div>

                                    <div className="max-w-3xl text-xs leading-5 text-muted-foreground">
                                        <p>
                                            This prefix applies only when a new
                                            username is issued. Existing
                                            credentials and password rotations
                                            keep their current username.
                                        </p>
                                        {!nativeClientAccessEnabled && (
                                            <p className="mt-2 font-medium text-foreground">
                                                Native Client Access is
                                                currently disabled. You can
                                                configure the prefix now for
                                                future use.
                                            </p>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>

                            {(isDisablingQueryAccess ||
                                isDisablingNativeClientAccess) && (
                                <div className="flex gap-3 rounded-md border border-amber-300/80 bg-amber-50/50 p-3 text-sm dark:bg-amber-950/20">
                                    <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-700 dark:text-amber-300" />
                                    <div>
                                        <p className="font-medium">
                                            This change removes a workflow from
                                            new access requests
                                        </p>
                                        <p className="mt-1 max-w-3xl text-muted-foreground">
                                            Existing sessions will continue.
                                            Stored role grants are retained and
                                            become effective again if the
                                            workflow is re-enabled.
                                        </p>
                                    </div>
                                </div>
                            )}

                            <div className="flex justify-end border-t pt-5">
                                <Button disabled={processing}>
                                    {processing ? <Spinner /> : <Save />}
                                    Save access settings
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

AccessWorkflows.layout = {
    breadcrumbs: [{ title: 'Access workflows', href: edit() }],
};
