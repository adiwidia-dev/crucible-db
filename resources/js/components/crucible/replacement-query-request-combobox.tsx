import { Combobox } from '@cloudflare/kumo/components/combobox';
import { FileCode2, SearchX } from 'lucide-react';
import { useCallback } from 'react';
import { StatusBadge } from '@/components/crucible/status-badge';
import { statusLabel } from '@/lib/crucible';
import type { QueryRequestStatus } from '@/lib/crucible';

export type ReplacementQueryRequestOption = {
    id: number;
    title: string;
    status: QueryRequestStatus;
    connections: Array<{
        id: number;
        name: string;
    }>;
};

type Props = {
    candidates: ReplacementQueryRequestOption[];
    error?: string;
    onValueChange: (value: string) => void;
    value: string;
};

export function ReplacementQueryRequestCombobox({
    candidates,
    error,
    onValueChange,
    value,
}: Props) {
    const { contains } = Combobox.useFilter();
    const selectedCandidate = candidates.find(
        (candidate) => String(candidate.id) === value,
    );
    const filter = useCallback(
        (candidate: ReplacementQueryRequestOption, query: string): boolean =>
            contains(`#${candidate.id}`, query) ||
            contains(candidate.title, query) ||
            contains(statusLabel(candidate.status), query) ||
            candidate.connections.some((connection) =>
                contains(connection.name, query),
            ),
        [contains],
    );

    return (
        <>
            <input
                type="hidden"
                name="replacement_query_request_id"
                value={value}
            />
            <Combobox
                items={candidates}
                value={selectedCandidate ?? null}
                onValueChange={(candidate) =>
                    onValueChange(candidate ? String(candidate.id) : '')
                }
                filter={filter}
                label="Replacement deployment batch"
                description="Search every visible deployment batch created after this failure."
                error={error}
                required
            >
                <Combobox.TriggerValue
                    placeholder="Select the new deployment batch"
                    className="w-full"
                >
                    {(candidate: ReplacementQueryRequestOption | null) =>
                        candidate
                            ? `#${candidate.id} ${candidate.title}`
                            : 'Select the new deployment batch'
                    }
                </Combobox.TriggerValue>
                <Combobox.Content className="max-h-80">
                    <Combobox.Input
                        placeholder="Search by ID, title, status, or connection..."
                        aria-label="Search replacement deployment batches"
                    />
                    <Combobox.List>
                        {(candidate: ReplacementQueryRequestOption) => (
                            <Combobox.Item
                                key={candidate.id}
                                value={candidate}
                                className="py-2 text-sm"
                            >
                                <div className="flex min-w-0 items-start gap-3">
                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-kumo-tint text-kumo-default ring-1 ring-kumo-line">
                                        <FileCode2 className="size-4" />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="flex min-w-0 items-center gap-2">
                                            <span className="shrink-0 font-mono text-xs text-kumo-subtle">
                                                #{candidate.id}
                                            </span>
                                            <span className="truncate font-medium">
                                                {candidate.title}
                                            </span>
                                        </span>
                                        <span className="mt-1 block truncate text-xs text-kumo-subtle">
                                            {candidate.connections
                                                .map(
                                                    (connection) =>
                                                        connection.name,
                                                )
                                                .join(' · ')}
                                        </span>
                                    </span>
                                    <StatusBadge
                                        value={candidate.status}
                                        className="shrink-0"
                                    />
                                </div>
                            </Combobox.Item>
                        )}
                    </Combobox.List>
                    <Combobox.Empty>
                        <div className="flex items-center gap-2 py-1">
                            <SearchX className="size-4" />
                            <span>No matching deployment batches</span>
                        </div>
                    </Combobox.Empty>
                </Combobox.Content>
            </Combobox>
        </>
    );
}
