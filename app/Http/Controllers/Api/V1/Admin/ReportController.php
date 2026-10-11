<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Reporting\Report;
use App\Domain\Reporting\ReportExporter;
use App\Domain\Reporting\ReportRegistry;
use App\Enums\Permission;
use App\Http\Controllers\Api\V1\Affiliate\DashboardController as AffiliateDashboard;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Staff reports. Each report needs its own view permission; exporting also
 * needs reports.export and is audited.
 */
class ReportController extends Controller
{
    private const PREVIEW_ROWS = 100;

    public function __construct(private readonly ReportRegistry $registry, private readonly ReportExporter $exporter) {}

    public function index(Request $request): array
    {
        return ['data' => [
            'reports' => array_values(array_map(fn (Report $r) => $r->describe(), $this->registry->staffFor($request->user()))),
            'can_export' => $request->user()->can(Permission::ReportsExport->value),
        ]];
    }

    public function show(Request $request, string $report): array
    {
        $definition = $this->resolve($request, $report);
        $period = AffiliateDashboard::period($request);
        $filters = DashboardController::filters($request);

        $rows = [];
        $truncated = false;
        foreach ($definition->rows($period, $filters) as $row) {
            if (count($rows) === self::PREVIEW_ROWS) {
                $truncated = true;
                break;
            }
            $rows[] = $this->exporter->preview($definition->columns(), $row);
        }

        return ['data' => $definition->describe() + [
            'period' => $definition->usesPeriod() ? $period->describe() : null,
            'rows' => $rows,
            'truncated' => $truncated,
        ]];
    }

    public function export(Request $request, string $report, AuditLogger $audit): StreamedResponse
    {
        abort_unless($request->user()->can(Permission::ReportsExport->value), 403, 'You do not have permission to export reports.');
        $definition = $this->resolve($request, $report);
        $format = $request->validate(['format' => ['required', 'in:csv,xlsx']])['format'];
        $period = AffiliateDashboard::period($request);
        $filters = DashboardController::filters($request);

        $audit->log('report.exported', null, [
            'report' => $definition->key(),
            'format' => $format,
            'period' => $definition->usesPeriod() ? $period->describe() : null,
            'filters' => $request->only(['affiliate_id', 'product_id', 'coupon_id']),
        ], $request->user());

        return $this->exporter->stream($definition, $period, $filters, $format);
    }

    private function resolve(Request $request, string $key): Report
    {
        $report = $this->registry->staff()[$key] ?? abort(404, 'Report not found.');
        abort_unless($request->user()->can($report->permission()->value), 403, 'You do not have permission to view this report.');

        return $report;
    }
}
