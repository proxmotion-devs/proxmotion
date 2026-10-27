<?php

declare(strict_types=1);

namespace App\Domain\Reports;

/**
 * One table of a report (docs/adr/0046): shown on the reports page with at
 * most a page of rows, and exported whole as CSV.
 *
 * @phpstan-type Row array<string, string|int|float|bool|null>
 */
final readonly class ReportTable
{
    /** Rows shown on the page; the CSV export has all of them. */
    public const int SHOWN_ROWS = 100;

    /**
     * @param  string  $title  translated
     * @param  string|null  $description  translated
     * @param  list<ReportColumn>  $columns
     * @param  list<Row>  $rows  may carry keys that are no column, e.g. server_uuid
     * @param  Row|null  $totals  a last row summing up the table
     */
    public function __construct(
        public string $key,
        public string $title,
        public ?string $description,
        public array $columns,
        public array $rows,
        public ?array $totals = null,
    ) {}

    /**
     * @return array{key: string, title: string, description: string|null, columns: list<array{key: string, label: string, type: string}>, rows: list<Row>, row_count: int, totals: Row|null}
     */
    public function toPayload(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'description' => $this->description,
            'columns' => array_map(fn (ReportColumn $column) => $column->toPayload(), $this->columns),
            'rows' => array_slice($this->rows, 0, self::SHOWN_ROWS),
            'row_count' => count($this->rows),
            'totals' => $this->totals,
        ];
    }

    /**
     * The CSV lines: column keys, then every row and the totals.
     *
     * @return iterable<list<string|int|float|null>>
     */
    public function csvLines(): iterable
    {
        yield array_map(fn (ReportColumn $column) => $column->key, $this->columns);

        foreach ([...$this->rows, ...($this->totals === null ? [] : [$this->totals])] as $row) {
            yield array_map(fn (ReportColumn $column) => self::csvValue($row[$column->key] ?? null), $this->columns);
        }
    }

    /**
     * A cell as written to CSV. Text a spreadsheet would read as a formula
     * (hostnames, names and errors come from administrators and billing
     * systems) gets a leading apostrophe.
     */
    private static function csvValue(string|int|float|bool|null $value): string|int|float|null
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
