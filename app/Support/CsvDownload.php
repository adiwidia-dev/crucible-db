<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvDownload
{
    /**
     * @param  array<int, string>  $header
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    public function rows(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fputcsv($output, $header);

            foreach ($rows as $row) {
                fputcsv($output, $row);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $rows
     */
    public function sampleRows(string $filename, ?array $rows): StreamedResponse
    {
        $rows ??= [];

        if ($rows === []) {
            return $this->rows($filename, ['message'], [
                ['No sample rows recorded.'],
            ]);
        }

        $columns = collect($rows)
            ->flatMap(fn (array $row): array => array_keys($row))
            ->unique()
            ->values()
            ->all();

        return $this->rows($filename, $columns, collect($rows)->map(
            fn (array $row): array => collect($columns)
                ->map(fn (string $column): mixed => $this->stringableValue($row[$column] ?? null))
                ->all(),
        ));
    }

    private function stringableValue(mixed $value): mixed
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $value;
    }
}
