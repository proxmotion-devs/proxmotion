<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Reports\Report;
use App\Domain\Reports\Reports;
use App\Domain\Reports\ReportTable;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports (docs/adr/0046): capacity, traffic, servers and operations,
 * computed when the page opens, each table also as CSV.
 */
class ReportController extends Controller
{
    public function index(): RedirectResponse
    {
        return to_route('admin.reports.show', array_key_first(Reports::PAGES));
    }

    public function show(Request $request, string $report): Response
    {
        $page = Reports::make($report);
        $filters = $this->filters($request, $page);

        return Inertia::render('admin/reports/Show', [
            'report' => $report,
            'filters' => $filters,
            'filterOptions' => $page->filterOptions(),
            'tables' => array_map(fn (ReportTable $table) => $table->toPayload(), $page->tables($filters)),
        ]);
    }

    /**
     * One table of a report as CSV, every row, with the filters of the page.
     */
    public function csv(Request $request, string $report, string $table): StreamedResponse
    {
        $page = Reports::make($report);
        $filters = $this->filters($request, $page);
        $found = collect($page->tables($filters))->firstWhere('key', $table);

        abort_if($found === null, 404);

        return response()->streamDownload(function () use ($found) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            foreach ($found->csvLines() as $line) {
                fputcsv($out, $line, escape: '');
            }

            fclose($out);
        }, "{$report}-{$table}-".now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<string, string|int|null>
     */
    private function filters(Request $request, Report $page): array
    {
        return $page->filters($request->validate($page->rules()));
    }
}
