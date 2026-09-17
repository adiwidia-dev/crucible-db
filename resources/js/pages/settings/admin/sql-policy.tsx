import { Form, Head, Link } from '@inertiajs/react';
import { Ban, Database, FileSearch, Save, ShieldCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import QueryRequestController from '@/actions/App/Http/Controllers/QueryRequestController';
import SqlPolicyCandidateResolutionController from '@/actions/App/Http/Controllers/Settings/SqlPolicyCandidateResolutionController';
import SqlPolicyRuleController from '@/actions/App/Http/Controllers/Settings/SqlPolicyRuleController';
import SqlStatementPolicyController from '@/actions/App/Http/Controllers/Settings/SqlStatementPolicyController';
import { PageHeader } from '@/components/crucible/page-header';
import { Pagination } from '@/components/crucible/pagination';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { driverLabel } from '@/lib/crucible';
import type { Paginated } from '@/lib/crucible';
import { edit } from '@/routes/sql-statement-policy';

type SqlSetting =
    | 'sql_emergency_fallback_enabled'
    | 'sql_read_queries_enabled'
    | 'sql_insert_enabled'
    | 'sql_update_enabled'
    | 'sql_delete_enabled'
    | 'sql_create_table_enabled'
    | 'sql_alter_table_enabled'
    | 'sql_drop_table_enabled'
    | 'sql_truncate_table_enabled';

type StatementFamilySetting = Exclude<
    SqlSetting,
    'sql_emergency_fallback_enabled'
>;

type PolicyScopeOption = {
    type: 'workspace' | 'connection_group' | 'database_connection';
    id: number | null;
    label: string;
};

type PolicyCandidate = {
    id: number;
    driver: 'pgsql' | 'mysql';
    canonical_sql: string;
    shape_label: string | null;
    shape_available: boolean;
    occurrences_count: number;
    requested_occurrences_count: number;
    first_seen_at: string;
    last_seen_at: string;
    occurrences: Array<{
        request_id: number;
        request_title: string | null;
        connection_name: string | null;
        review_requested_at: string | null;
    }>;
    scope_options: PolicyScopeOption[];
};

type CustomPolicyRule = {
    id: number;
    driver: 'pgsql' | 'mysql';
    effect: 'allow' | 'deny';
    match_type: 'exact' | 'shape';
    statement: string;
    scope: string;
    created_by: string;
    is_enabled: boolean;
    created_at: string | null;
};

type Props = {
    settings: Record<SqlSetting, boolean>;
    candidates: Paginated<PolicyCandidate>;
    custom_rules: CustomPolicyRule[];
    selected_candidate_id: number | null;
};

export default function SqlPolicy({
    settings,
    candidates,
    custom_rules,
    selected_candidate_id,
}: Props) {
    const [allowsEmergencySqlFallback, setAllowsEmergencySqlFallback] =
        useState(settings.sql_emergency_fallback_enabled);
    const [statementFamilySettings, setStatementFamilySettings] = useState<
        Record<StatementFamilySetting, boolean>
    >({
        sql_read_queries_enabled: settings.sql_read_queries_enabled,
        sql_insert_enabled: settings.sql_insert_enabled,
        sql_update_enabled: settings.sql_update_enabled,
        sql_delete_enabled: settings.sql_delete_enabled,
        sql_create_table_enabled: settings.sql_create_table_enabled,
        sql_alter_table_enabled: settings.sql_alter_table_enabled,
        sql_drop_table_enabled: settings.sql_drop_table_enabled,
        sql_truncate_table_enabled: settings.sql_truncate_table_enabled,
    });

    const updateStatementFamilySetting = (
        name: StatementFamilySetting,
        checked: boolean,
    ) => {
        setStatementFamilySettings((current) => ({
            ...current,
            [name]: checked,
        }));
    };

    useEffect(() => {
        if (selected_candidate_id === null) {
            return;
        }

        document
            .getElementById(`sql-policy-candidate-${selected_candidate_id}`)
            ?.scrollIntoView({ block: 'center' });
    }, [selected_candidate_id]);

    return (
        <>
            <Head title="SQL Policy" />
            <div className="crucible-page">
                <PageHeader
                    title="SQL policy"
                    description="Control governed deployment statements and review unsupported SQL discovered during preflight."
                />

                <section className="mb-6 max-w-3xl overflow-hidden border-y bg-card sm:rounded-lg sm:border">
                    <div className="flex items-start justify-between gap-4 border-b px-4 py-4 sm:px-5">
                        <div>
                            <h2 className="flex items-center gap-2 text-sm font-semibold">
                                <FileSearch className="size-4 text-amber-600" />
                                SQL policy candidates
                            </h2>
                            <p className="mt-1 text-sm leading-5 text-muted-foreground">
                                Explicit developer requests appear first. Other
                                structurally safe unsupported statements remain
                                available as observations without creating
                                review work.
                            </p>
                        </div>
                        <Badge className="border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/70 dark:bg-amber-950/40 dark:text-amber-200">
                            {candidates.total} unresolved
                        </Badge>
                    </div>

                    {candidates.data.length === 0 ? (
                        <div className="flex items-center gap-3 px-4 py-5 text-sm text-muted-foreground sm:px-5">
                            <ShieldCheck className="size-5 text-emerald-600" />
                            No unsupported deployment statements need review.
                        </div>
                    ) : (
                        <div className="divide-y">
                            {candidates.data.map((candidate) => (
                                <CandidateReview
                                    key={candidate.id}
                                    candidate={candidate}
                                    selected={
                                        candidate.id === selected_candidate_id
                                    }
                                />
                            ))}
                        </div>
                    )}
                    <Pagination pagination={candidates} />
                </section>

                <Form
                    {...SqlStatementPolicyController.update.form()}
                    disableWhileProcessing
                    className="max-w-3xl space-y-4"
                >
                    {({ processing }) => (
                        <>
                            <section className="overflow-hidden border-y bg-card sm:rounded-lg sm:border">
                                <div className="border-b px-4 py-3 sm:px-5">
                                    <h2 className="text-sm font-semibold">
                                        Statement families
                                    </h2>
                                    <p className="mt-1 text-sm leading-5 text-muted-foreground">
                                        Choose which recognized SQL families may
                                        run through governed workflows.
                                    </p>
                                </div>
                                <div className="divide-y">
                                    <PolicyField
                                        name="sql_read_queries_enabled"
                                        checked={
                                            statementFamilySettings.sql_read_queries_enabled
                                        }
                                        onCheckedChange={
                                            updateStatementFamilySetting
                                        }
                                        title="Read queries"
                                        description="SELECT, SHOW, DESCRIBE, and EXPLAIN without ANALYZE."
                                    />
                                    <PolicyField
                                        name="sql_insert_enabled"
                                        checked={
                                            statementFamilySettings.sql_insert_enabled
                                        }
                                        onCheckedChange={
                                            updateStatementFamilySetting
                                        }
                                        title="INSERT"
                                        description="Create rows through governed deployment batches."
                                    />
                                    <PolicyField
                                        name="sql_update_enabled"
                                        checked={
                                            statementFamilySettings.sql_update_enabled
                                        }
                                        onCheckedChange={
                                            updateStatementFamilySetting
                                        }
                                        title="UPDATE"
                                        description="Modify rows through governed deployment batches."
                                    />
                                    <PolicyField
                                        name="sql_delete_enabled"
                                        checked={
                                            statementFamilySettings.sql_delete_enabled
                                        }
                                        onCheckedChange={
                                            updateStatementFamilySetting
                                        }
                                        title="DELETE"
                                        description="Delete rows through governed deployment batches."
                                    />
                                    <PolicyField
                                        name="sql_create_table_enabled"
                                        checked={
                                            statementFamilySettings.sql_create_table_enabled
                                        }
                                        onCheckedChange={
                                            updateStatementFamilySetting
                                        }
                                        title="CREATE TABLE"
                                        description="Create permanent or temporary tables."
                                    />
                                    <PolicyField
                                        name="sql_alter_table_enabled"
                                        checked={
                                            statementFamilySettings.sql_alter_table_enabled
                                        }
                                        onCheckedChange={
                                            updateStatementFamilySetting
                                        }
                                        title="ALTER TABLE"
                                        description="Change an existing table schema."
                                    />
                                    <PolicyField
                                        name="sql_drop_table_enabled"
                                        checked={
                                            statementFamilySettings.sql_drop_table_enabled
                                        }
                                        onCheckedChange={
                                            updateStatementFamilySetting
                                        }
                                        title="DROP TABLE"
                                        description="Permanently remove a table. This is a high-risk operation."
                                    />
                                    <PolicyField
                                        name="sql_truncate_table_enabled"
                                        checked={
                                            statementFamilySettings.sql_truncate_table_enabled
                                        }
                                        onCheckedChange={
                                            updateStatementFamilySetting
                                        }
                                        title="TRUNCATE TABLE"
                                        description="Remove every row from a table. This is a high-risk operation."
                                    />
                                </div>
                            </section>
                            <section className="border border-amber-200 bg-amber-50/50 px-4 py-4 sm:rounded-lg sm:px-5">
                                <div className="flex items-start justify-between gap-5">
                                    <div className="min-w-0">
                                        <h2 className="text-sm font-semibold text-amber-950">
                                            Emergency SQL fallback
                                        </h2>
                                        <p className="mt-1 text-sm leading-5 text-amber-900/80">
                                            Allow one otherwise unsupported
                                            deployment statement for urgent,
                                            approved work.
                                        </p>
                                    </div>
                                    <label className="shrink-0 cursor-pointer">
                                        <span className="sr-only">
                                            Enable emergency SQL fallback
                                        </span>
                                        <input
                                            type="hidden"
                                            name="sql_emergency_fallback_enabled"
                                            value="0"
                                        />
                                        <input
                                            type="checkbox"
                                            name="sql_emergency_fallback_enabled"
                                            value="1"
                                            checked={allowsEmergencySqlFallback}
                                            onChange={(event) =>
                                                setAllowsEmergencySqlFallback(
                                                    event.target.checked,
                                                )
                                            }
                                            className="peer sr-only"
                                        />
                                        <span
                                            aria-hidden="true"
                                            className="relative block h-6 w-11 rounded-full border border-input bg-muted transition-colors peer-checked:border-primary peer-checked:bg-primary peer-focus-visible:ring-3 peer-focus-visible:ring-ring/40 after:absolute after:top-0.5 after:left-0.5 after:size-5 after:rounded-full after:bg-background after:shadow-sm after:transition-transform peer-checked:after:translate-x-5 motion-reduce:after:transition-none"
                                        />
                                    </label>
                                </div>
                                <p className="mt-3 border-t border-amber-200 pt-3 text-xs leading-5 text-amber-900/80">
                                    Fallback SQL is treated as write access,
                                    remains subject to role permissions and
                                    approval, and is recorded in the audit log.
                                    It applies only to deployment batches; Query
                                    Access sessions cannot use it.
                                </p>
                            </section>
                            <div className="flex justify-end">
                                <Button disabled={processing}>
                                    {processing ? <Spinner /> : <Save />} Save
                                    SQL policy
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
                <section className="mt-6 max-w-3xl overflow-hidden border-y bg-card sm:rounded-lg sm:border">
                    <div className="border-b px-4 py-4 sm:px-5">
                        <h2 className="text-sm font-semibold">
                            Custom deployment rules
                        </h2>
                        <p className="mt-1 text-sm leading-5 text-muted-foreground">
                            Decisions promoted from the candidate queue. These
                            rules never apply to browser Query Access or native
                            client sessions.
                        </p>
                    </div>
                    {custom_rules.length === 0 ? (
                        <div className="px-4 py-5 text-sm text-muted-foreground sm:px-5">
                            No custom deployment rules have been created.
                        </div>
                    ) : (
                        <div className="divide-y">
                            {custom_rules.map((rule) => (
                                <CustomRuleRow key={rule.id} rule={rule} />
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

function PolicyField({
    name,
    checked,
    onCheckedChange,
    title,
    description,
}: {
    name: StatementFamilySetting;
    checked: boolean;
    onCheckedChange: (name: StatementFamilySetting, checked: boolean) => void;
    title: string;
    description: string;
}) {
    return (
        <label className="flex cursor-pointer items-start gap-3 px-4 py-3.5 sm:px-5">
            <input type="hidden" name={name} value={checked ? '1' : '0'} />
            <input
                type="checkbox"
                checked={checked}
                onChange={(event) =>
                    onCheckedChange(name, event.target.checked)
                }
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

function CandidateReview({
    candidate,
    selected,
}: {
    candidate: PolicyCandidate;
    selected: boolean;
}) {
    const initialScope = candidate.scope_options[0];
    const [matchType, setMatchType] = useState<'exact' | 'shape'>('exact');
    const [scopeValue, setScopeValue] = useState(
        `${initialScope.type}:${initialScope.id ?? ''}`,
    );
    const [scopeType, scopeId = ''] = scopeValue.split(':');

    return (
        <article
            id={`sql-policy-candidate-${candidate.id}`}
            className={
                selected
                    ? 'scroll-mt-6 bg-amber-50/60 px-4 py-5 ring-2 ring-amber-400 ring-inset sm:px-5 dark:bg-amber-950/20'
                    : 'scroll-mt-6 px-4 py-5 sm:px-5'
            }
        >
            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="outline">
                            {driverLabel(candidate.driver)}
                        </Badge>
                        <Badge
                            variant="outline"
                            className="border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/70 dark:bg-amber-950/40 dark:text-amber-200"
                        >
                            {candidate.occurrences_count}{' '}
                            {candidate.occurrences_count === 1
                                ? 'occurrence'
                                : 'occurrences'}
                        </Badge>
                        {candidate.requested_occurrences_count > 0 && (
                            <Badge className="border-amber-300 bg-amber-100 text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                                Review requested
                            </Badge>
                        )}
                        {candidate.shape_available && (
                            <Badge
                                variant="outline"
                                className="border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900/70 dark:bg-blue-950/40 dark:text-blue-200"
                            >
                                Reusable shape available
                            </Badge>
                        )}
                    </div>

                    <pre className="mt-3 max-h-52 overflow-auto rounded-md border bg-muted/30 p-3 font-mono text-xs leading-5 break-words whitespace-pre-wrap">
                        {candidate.canonical_sql}
                    </pre>

                    <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        {candidate.occurrences.map((occurrence) => (
                            <Link
                                key={`${occurrence.request_id}-${occurrence.connection_name}`}
                                href={QueryRequestController.show(
                                    occurrence.request_id,
                                )}
                                className="inline-flex items-center gap-1 font-medium text-primary hover:underline"
                            >
                                <Database className="size-3" />
                                {occurrence.request_title ??
                                    `Request #${occurrence.request_id}`}
                                {occurrence.connection_name
                                    ? ` · ${occurrence.connection_name}`
                                    : ''}
                                {occurrence.review_requested_at
                                    ? ' · requested'
                                    : ' · observed'}
                            </Link>
                        ))}
                    </div>
                </div>

                <Form
                    {...SqlPolicyCandidateResolutionController.form(
                        candidate.id,
                    )}
                    options={{ preserveScroll: true }}
                    disableWhileProcessing
                    className="rounded-md border bg-background p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div>
                                <label
                                    htmlFor={`candidate-${candidate.id}-match`}
                                    className="text-xs font-medium"
                                >
                                    Rule type
                                </label>
                                <select
                                    id={`candidate-${candidate.id}-match`}
                                    name="match_type"
                                    value={matchType}
                                    onChange={(event) =>
                                        setMatchType(
                                            event.target.value as
                                                'exact' | 'shape',
                                        )
                                    }
                                    className="mt-1 h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                >
                                    <option value="exact">
                                        This exact statement
                                    </option>
                                    {candidate.shape_available && (
                                        <option value="shape">
                                            All {candidate.shape_label}{' '}
                                            statements
                                        </option>
                                    )}
                                </select>
                                <p className="mt-1.5 text-xs leading-5 text-muted-foreground">
                                    {matchType === 'shape'
                                        ? 'Reusable matching is offered only when the built-in parser proves a supported statement structure.'
                                        : 'The submitted SQL must match exactly; surrounding whitespace and a trailing semicolon are ignored.'}
                                </p>
                                <InputError message={errors.match_type} />
                            </div>

                            <div className="mt-3">
                                <label
                                    htmlFor={`candidate-${candidate.id}-scope`}
                                    className="text-xs font-medium"
                                >
                                    Applies to
                                </label>
                                <select
                                    id={`candidate-${candidate.id}-scope`}
                                    value={scopeValue}
                                    onChange={(event) =>
                                        setScopeValue(event.target.value)
                                    }
                                    className="mt-1 h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                >
                                    {candidate.scope_options.map((scope) => (
                                        <option
                                            key={`${scope.type}-${scope.id ?? 'workspace'}`}
                                            value={`${scope.type}:${scope.id ?? ''}`}
                                        >
                                            {scope.label}
                                        </option>
                                    ))}
                                </select>
                                <input
                                    type="hidden"
                                    name="scope_type"
                                    value={scopeType}
                                />
                                <input
                                    type="hidden"
                                    name="scope_id"
                                    value={scopeId}
                                />
                                <InputError message={errors.scope_type} />
                                <InputError message={errors.scope_id} />
                            </div>

                            <div className="mt-3">
                                <label
                                    htmlFor={`candidate-${candidate.id}-comment`}
                                    className="text-xs font-medium"
                                >
                                    Decision note
                                </label>
                                <textarea
                                    id={`candidate-${candidate.id}-comment`}
                                    name="comment"
                                    rows={3}
                                    placeholder="Required when denying or dismissing; shared with the requester."
                                    className="mt-1 w-full resize-y rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                />
                                <InputError message={errors.comment} />
                            </div>

                            <div className="mt-4 grid grid-cols-2 gap-2">
                                <Button
                                    type="submit"
                                    name="action"
                                    value="allow"
                                    size="sm"
                                    disabled={processing}
                                >
                                    <ShieldCheck /> Allow
                                </Button>
                                <Button
                                    type="submit"
                                    name="action"
                                    value="deny"
                                    variant="destructive"
                                    size="sm"
                                    disabled={processing}
                                >
                                    <Ban /> Deny exact
                                </Button>
                            </div>
                            <Button
                                type="submit"
                                name="action"
                                value="dismiss"
                                variant="ghost"
                                size="sm"
                                className="mt-2 w-full text-muted-foreground"
                                disabled={processing}
                            >
                                Dismiss without a rule
                            </Button>
                            <InputError message={errors.action} />
                        </>
                    )}
                </Form>
            </div>
        </article>
    );
}

function CustomRuleRow({ rule }: { rule: CustomPolicyRule }) {
    return (
        <div className="grid gap-3 px-4 py-4 sm:px-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <Badge
                        variant="outline"
                        className={
                            rule.effect === 'allow'
                                ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/70 dark:bg-emerald-950/40 dark:text-emerald-200'
                                : 'border-red-200 bg-red-50 text-red-800 dark:border-red-900/70 dark:bg-red-950/40 dark:text-red-200'
                        }
                    >
                        {rule.effect === 'allow' ? 'Allow' : 'Deny'}
                    </Badge>
                    <Badge variant="outline">
                        {rule.match_type === 'shape'
                            ? 'Statement shape'
                            : 'Exact'}
                    </Badge>
                    <Badge variant="outline">{driverLabel(rule.driver)}</Badge>
                    {!rule.is_enabled && (
                        <Badge variant="secondary">Disabled</Badge>
                    )}
                </div>
                <p className="mt-2 truncate font-mono text-xs">
                    {rule.statement}
                </p>
                <p className="mt-1 text-xs text-muted-foreground">
                    {rule.scope} · Added by {rule.created_by}
                </p>
            </div>
            <Form
                {...SqlPolicyRuleController.update.form(rule.id)}
                options={{ preserveScroll: true }}
                disableWhileProcessing
            >
                {({ processing }) => (
                    <>
                        <input
                            type="hidden"
                            name="is_enabled"
                            value={rule.is_enabled ? '0' : '1'}
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            disabled={processing}
                        >
                            {rule.is_enabled ? 'Disable rule' : 'Enable rule'}
                        </Button>
                    </>
                )}
            </Form>
        </div>
    );
}

SqlPolicy.layout = { breadcrumbs: [{ title: 'SQL Policy', href: edit() }] };
