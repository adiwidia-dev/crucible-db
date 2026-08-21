import { Form, Head } from '@inertiajs/react';
import { Save, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import SqlStatementPolicyController from '@/actions/App/Http/Controllers/Settings/SqlStatementPolicyController';
import { PageHeader } from '@/components/crucible/page-header';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { edit } from '@/routes/sql-statement-policy';

type SqlSetting =
    | 'sql_all_statement_families_enabled'
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
    'sql_all_statement_families_enabled' | 'sql_emergency_fallback_enabled'
>;

export default function SqlPolicy({
    settings,
}: {
    settings: Record<SqlSetting, boolean>;
}) {
    const [allowsAllStatementFamilies, setAllowsAllStatementFamilies] =
        useState(settings.sql_all_statement_families_enabled);
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

    return (
        <>
            <Head title="SQL Policy" />
            <div className="crucible-page">
                <PageHeader
                    title="SQL policy"
                    description="Choose the statement families that may be submitted for governed deployment batches."
                />
                <Form
                    {...SqlStatementPolicyController.update.form()}
                    disableWhileProcessing
                    className="max-w-3xl space-y-4"
                >
                    {({ processing }) => (
                        <>
                            <section className="border-y bg-card px-4 py-4 sm:rounded-lg sm:border sm:px-5">
                                <div className="flex items-start justify-between gap-5">
                                    <div className="min-w-0">
                                        <h2 className="text-sm font-semibold">
                                            Allow all governed statement
                                            families
                                        </h2>
                                        <p className="mt-1 text-sm leading-5 text-muted-foreground">
                                            Enable every supported deployment
                                            statement family. The individual
                                            controls remain available as a saved
                                            fallback.
                                        </p>
                                    </div>
                                    <label className="shrink-0 cursor-pointer">
                                        <span className="sr-only">
                                            Allow all governed statement
                                            families
                                        </span>
                                        <input
                                            type="hidden"
                                            name="sql_all_statement_families_enabled"
                                            value="0"
                                        />
                                        <input
                                            type="checkbox"
                                            name="sql_all_statement_families_enabled"
                                            value="1"
                                            checked={allowsAllStatementFamilies}
                                            onChange={(event) =>
                                                setAllowsAllStatementFamilies(
                                                    event.target.checked,
                                                )
                                            }
                                            className="peer sr-only"
                                        />
                                        <span
                                            aria-hidden="true"
                                            className="relative block h-6 w-11 rounded-full bg-muted transition-colors duration-200 peer-checked:bg-primary peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-ring"
                                        >
                                            <span
                                                className={
                                                    allowsAllStatementFamilies
                                                        ? 'absolute top-1 left-1 size-4 translate-x-5 rounded-full bg-primary-foreground transition-transform duration-200'
                                                        : 'absolute top-1 left-1 size-4 rounded-full bg-card transition-transform duration-200'
                                                }
                                            />
                                        </span>
                                    </label>
                                </div>
                                <p className="mt-3 border-t pt-3 text-xs leading-5 text-muted-foreground">
                                    Administrative commands, file access,
                                    security-management SQL, transaction
                                    control, and multiple statements remain
                                    blocked. Connection roles and approval rules
                                    still apply.
                                </p>
                            </section>
                            <section className="overflow-hidden border-y bg-card sm:rounded-lg sm:border">
                                <div className="border-b px-4 py-3 sm:px-5">
                                    <h2 className="text-sm font-semibold">
                                        Statement families
                                    </h2>
                                    <p className="mt-1 text-sm leading-5 text-muted-foreground">
                                        These controls apply when allow all is
                                        off.
                                    </p>
                                </div>
                                <div
                                    aria-disabled={allowsAllStatementFamilies}
                                    className={
                                        allowsAllStatementFamilies
                                            ? 'divide-y opacity-50'
                                            : 'divide-y'
                                    }
                                >
                                    <PolicyField
                                        name="sql_read_queries_enabled"
                                        checked={
                                            statementFamilySettings.sql_read_queries_enabled
                                        }
                                        disabled={allowsAllStatementFamilies}
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
                                        disabled={allowsAllStatementFamilies}
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
                                        disabled={allowsAllStatementFamilies}
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
                                        disabled={allowsAllStatementFamilies}
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
                                        disabled={allowsAllStatementFamilies}
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
                                        disabled={allowsAllStatementFamilies}
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
                                        disabled={allowsAllStatementFamilies}
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
                                        disabled={allowsAllStatementFamilies}
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
                                            className="relative block h-6 w-11 rounded-full bg-amber-200 transition-colors duration-200 peer-checked:bg-amber-600 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-amber-700"
                                        >
                                            <span
                                                className={
                                                    allowsEmergencySqlFallback
                                                        ? 'absolute top-1 left-1 size-4 translate-x-5 rounded-full bg-white transition-transform duration-200'
                                                        : 'absolute top-1 left-1 size-4 rounded-full bg-white transition-transform duration-200'
                                                }
                                            />
                                        </span>
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
                <Card className="mt-6 max-w-3xl gap-0 overflow-hidden border-y py-0 sm:rounded-lg sm:border">
                    <CardHeader className="border-b px-4 py-3 sm:px-5">
                        <CardTitle className="flex items-center gap-2">
                            <ShieldAlert className="size-4 text-muted-foreground" />{' '}
                            How policy is enforced
                        </CardTitle>
                        <CardDescription>
                            Statement policy is one gate in addition to a user’s
                            role, connection access, approval, and preflight
                            checks.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="px-4 py-4 text-sm text-muted-foreground sm:px-5">
                        Changes apply to newly submitted requests and are
                        checked again immediately before a deployment runs.
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

function PolicyField({
    name,
    checked,
    disabled,
    onCheckedChange,
    title,
    description,
}: {
    name: StatementFamilySetting;
    checked: boolean;
    disabled: boolean;
    onCheckedChange: (name: StatementFamilySetting, checked: boolean) => void;
    title: string;
    description: string;
}) {
    return (
        <label
            className={
                disabled
                    ? 'flex cursor-not-allowed items-start gap-3 px-4 py-3.5 sm:px-5'
                    : 'flex cursor-pointer items-start gap-3 px-4 py-3.5 sm:px-5'
            }
        >
            <input type="hidden" name={name} value={checked ? '1' : '0'} />
            <input
                type="checkbox"
                checked={checked}
                disabled={disabled}
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

SqlPolicy.layout = { breadcrumbs: [{ title: 'SQL Policy', href: edit() }] };
