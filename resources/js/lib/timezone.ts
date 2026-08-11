type DateTimeParts = {
    year: number;
    month: number;
    day: number;
    hour: number;
    minute: number;
    second: number;
};

function partsForTimezone(date: Date, timezone: string): DateTimeParts {
    const formatter = new Intl.DateTimeFormat('en-US', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23',
    });

    const parts = Object.fromEntries(
        formatter
            .formatToParts(date)
            .filter((part) => part.type !== 'literal')
            .map((part) => [part.type, Number(part.value)]),
    ) as Partial<DateTimeParts>;

    return {
        year: parts.year ?? 1970,
        month: parts.month ?? 1,
        day: parts.day ?? 1,
        hour: parts.hour ?? 0,
        minute: parts.minute ?? 0,
        second: parts.second ?? 0,
    };
}

function timezoneOffsetMilliseconds(date: Date, timezone: string): number {
    const parts = partsForTimezone(date, timezone);
    const zonedTimestamp = Date.UTC(
        parts.year,
        parts.month - 1,
        parts.day,
        parts.hour,
        parts.minute,
        parts.second,
    );

    return zonedTimestamp - date.getTime();
}

export function zonedDateTimeLocalToIso(
    value: string,
    timezone: string,
): string {
    const match = value.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/);

    if (!match) {
        return '';
    }

    const [, year, month, day, hour, minute] = match.map(Number);
    const localAsUtc = Date.UTC(year, month - 1, day, hour, minute, 0);
    const firstOffset = timezoneOffsetMilliseconds(
        new Date(localAsUtc),
        timezone,
    );
    const firstUtc = localAsUtc - firstOffset;
    const secondOffset = timezoneOffsetMilliseconds(
        new Date(firstUtc),
        timezone,
    );
    const utcTimestamp = localAsUtc - secondOffset;

    return new Date(utcTimestamp).toISOString();
}
