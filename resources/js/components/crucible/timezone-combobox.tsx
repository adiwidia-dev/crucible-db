import { Combobox } from '@cloudflare/kumo/components/combobox';
import { Clock3, SearchX } from 'lucide-react';
import { useCallback } from 'react';

type Props = {
    description: string;
    error?: string;
    label: string;
    name: string;
    onValueChange: (value: string) => void;
    timezones: string[];
    value: string;
};

export function TimezoneCombobox({
    description,
    error,
    label,
    name,
    onValueChange,
    timezones,
    value,
}: Props) {
    const { contains } = Combobox.useFilter();
    const filter = useCallback(
        (timezone: string, query: string): boolean => contains(timezone, query),
        [contains],
    );

    return (
        <>
            <input type="hidden" name={name} value={value} />
            <Combobox
                items={timezones}
                value={value}
                onValueChange={(timezone) => onValueChange(timezone ?? '')}
                filter={filter}
                label={label}
                description={description}
                error={error}
                required
            >
                <Combobox.TriggerValue
                    placeholder="Select a timezone"
                    className="w-full"
                >
                    {(timezone: string | null) => (
                        <span className="flex min-w-0 items-center gap-2">
                            <Clock3 className="size-4 shrink-0 text-muted-foreground" />
                            <span className="truncate font-mono text-sm">
                                {timezone ?? 'Select a timezone'}
                            </span>
                        </span>
                    )}
                </Combobox.TriggerValue>
                <Combobox.Content className="max-h-72">
                    <Combobox.Input
                        placeholder="Search timezones..."
                        aria-label={`Search ${label.toLocaleLowerCase()}`}
                    />
                    <Combobox.List>
                        {(timezone: string) => (
                            <Combobox.Item
                                key={timezone}
                                value={timezone}
                                className="py-2 text-sm"
                            >
                                <span className="flex min-w-0 items-center gap-3">
                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground ring-1 ring-border">
                                        <Clock3 className="size-4" />
                                    </span>
                                    <span className="truncate font-mono text-sm">
                                        {timezone}
                                    </span>
                                </span>
                            </Combobox.Item>
                        )}
                    </Combobox.List>
                    <Combobox.Empty>
                        <div className="flex items-center gap-2 py-1">
                            <SearchX className="size-4" />
                            <span>No matching timezones</span>
                        </div>
                    </Combobox.Empty>
                </Combobox.Content>
            </Combobox>
        </>
    );
}
