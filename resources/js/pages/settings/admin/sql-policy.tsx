import { Form, Head } from '@inertiajs/react';
import { Save, ShieldAlert } from 'lucide-react';
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
    | 'sql_read_queries_enabled'
    | 'sql_insert_enabled'
    | 'sql_update_enabled'
    | 'sql_delete_enabled'
    | 'sql_create_table_enabled'
    | 'sql_alter_table_enabled'
    | 'sql_drop_table_enabled'
    | 'sql_truncate_table_enabled';

export default function SqlPolicy({
    settings,
}: {
    settings: Record<SqlSetting, boolean>;
}) {
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
                    className="max-w-3xl overflow-hidden border-y bg-card sm:rounded-lg sm:border"
                >
                    {({ processing }) => (
                        <>
                            <div className="border-b bg-muted/20 px-4 py-3 text-xs leading-5 text-muted-foreground sm:px-5">
                                Administrative commands, file access,
                                security-management SQL, and multiple statements
                                remain blocked. Connection roles and approval
                                rules still apply.
                            </div>
                            <div className="divide-y">
                                <PolicyField
                                    name="sql_read_queries_enabled"
                                    defaultChecked={
                                        settings.sql_read_queries_enabled
                                    }
                                    title="Read queries"
                                    description="SELECT, SHOW, DESCRIBE, and EXPLAIN without ANALYZE."
                                />
                                <PolicyField
                                    name="sql_insert_enabled"
                                    defaultChecked={settings.sql_insert_enabled}
                                    title="INSERT"
                                    description="Create rows through governed deployment batches."
                                />
                                <PolicyField
                                    name="sql_update_enabled"
                                    defaultChecked={settings.sql_update_enabled}
                                    title="UPDATE"
                                    description="Modify rows through governed deployment batches."
                                />
                                <PolicyField
                                    name="sql_delete_enabled"
                                    defaultChecked={settings.sql_delete_enabled}
                                    title="DELETE"
                                    description="Delete rows through governed deployment batches."
                                />
                                <PolicyField
                                    name="sql_create_table_enabled"
                                    defaultChecked={
                                        settings.sql_create_table_enabled
                                    }
                                    title="CREATE TABLE"
                                    description="Create permanent or temporary tables."
                                />
                                <PolicyField
                                    name="sql_alter_table_enabled"
                                    defaultChecked={
                                        settings.sql_alter_table_enabled
                                    }
                                    title="ALTER TABLE"
                                    description="Change an existing table schema."
                                />
                                <PolicyField
                                    name="sql_drop_table_enabled"
                                    defaultChecked={
                                        settings.sql_drop_table_enabled
                                    }
                                    title="DROP TABLE"
                                    description="Permanently remove a table. This is a high-risk operation."
                                />
                                <PolicyField
                                    name="sql_truncate_table_enabled"
                                    defaultChecked={
                                        settings.sql_truncate_table_enabled
                                    }
                                    title="TRUNCATE TABLE"
                                    description="Remove every row from a table. This is a high-risk operation."
                                />
                            </div>
                            <div className="flex justify-end border-t px-4 py-3 sm:px-5">
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
    defaultChecked,
    title,
    description,
}: {
    name: SqlSetting;
    defaultChecked: boolean;
    title: string;
    description: string;
}) {
    return (
        <label className="flex cursor-pointer items-start gap-3 px-4 py-3.5 sm:px-5">
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

SqlPolicy.layout = { breadcrumbs: [{ title: 'SQL Policy', href: edit() }] };
