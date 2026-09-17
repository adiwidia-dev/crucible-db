import { useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { policyPreview } from '@/routes/query-requests';

export type PolicyPreview = {
    sql: string;
    database_connection_id: number;
    message: string | null;
    reviewable: boolean;
    source: string | null;
};

type StatementInput = { sql: string; database_connection_id: number };

export function useDeploymentPolicyPreview(
    statements: StatementInput[],
    enabled: boolean,
    initialPreviews: PolicyPreview[],
): PolicyPreview[] {
    const signature = JSON.stringify(statements);
    const { submit, transform } = useHttp<
        { statements: StatementInput[] },
        { statements: PolicyPreview[] }
    >({ statements: [] });
    const [snapshot, setSnapshot] = useState(() => ({
        signature,
        previews: initialPreviews,
    }));

    useEffect(() => {
        if (!enabled) {
            return;
        }

        const inputs = (JSON.parse(signature) as StatementInput[]).filter(
            (statement) =>
                statement.sql.trim() !== '' &&
                statement.database_connection_id > 0,
        );

        if (inputs.length === 0) {
            return;
        }

        let active = true;
        const timer = window.setTimeout(() => {
            transform(() => ({ statements: inputs }));
            void submit(policyPreview())
                .then((response) => {
                    if (active) {
                        setSnapshot({
                            signature,
                            previews: response.statements,
                        });
                    }
                })
                .catch(() => {
                    // Keep the conservative local preview if the check fails.
                });
        }, 400);

        return () => {
            active = false;
            window.clearTimeout(timer);
        };
    }, [enabled, signature, submit, transform]);

    return snapshot.signature === signature ? snapshot.previews : [];
}
