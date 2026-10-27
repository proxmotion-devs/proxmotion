<?php

declare(strict_types=1);

namespace App\Domain\Reports;

/**
 * A page of the reports section (docs/adr/0046): figures computed from the
 * panel's database when the page is opened, nothing stored.
 */
interface Report
{
    /**
     * Validation rules of its filters (query string).
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * The filters in effect: the validated ones, defaults for the rest.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|int|null>
     */
    public function filters(array $input): array;

    /**
     * Choices of each filter. `all` is the label of the empty choice (no
     * filter), null when one must be chosen.
     *
     * @return array<string, array{label: string, all: string|null, options: list<array{value: string|int, label: string}>}>
     */
    public function filterOptions(): array;

    /**
     * @param  array<string, string|int|null>  $filters  from filters()
     * @return list<ReportTable>
     */
    public function tables(array $filters): array;
}
