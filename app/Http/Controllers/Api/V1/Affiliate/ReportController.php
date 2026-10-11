<?php

namespace App\Http\Controllers\Api\V1\Affiliate;

use App\Domain\Audit\AuditLogger;
use App\Domain\Reporting\Filters;
use App\Domain\Reporting\Report;
use App\Domain\Reporting\ReportExporter;
use App\Domain\Reporting\ReportRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The member's own reports, always filtered to their profile.
 */
class ReportController extends Controller
{
    use ResolvesAffiliate;

    public function index(ReportRegistry $registry): array
    {
        return ['data' => ['reports' => array_values(array_map(fn (Report $r) => $r->describe(), $registry->affiliate()))]];
    }

    public function export(Request $request, string $report, ReportRegistry $registry, ReportExporter $exporter, AuditLogger $audit): StreamedResponse
    {
        $profile = $this->trackedProfile($request);
        $definition = $registry->affiliate()[$report] ?? abort(404, 'Report not found.');
        $format = $request->validate(['format' => ['required', 'in:csv,xlsx']])['format'];
        $period = DashboardController::period($request);

        $audit->log('report.exported', $profile, ['report' => $definition->key(), 'format' => $format, 'period' => $period->describe()], $request->user());

        return $exporter->stream($definition, $period, Filters::forAffiliate($profile->id), $format);
    }
}
