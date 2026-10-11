import { Alert, Card, Spinner } from '@/components/ui';
import { ExportButtons, PeriodPicker, useReportParams } from '@/reporting/controls';
import { DataTable } from '@/reporting/DataTable';
import { FilterBar } from '@/reporting/FilterBar';
import { rangeLabel } from '@/reporting/period';
import type { PeriodInfo, ReportInfo } from '@/reporting/types';
import { useApiData } from '@/reporting/useApiData';

interface Preview extends ReportInfo {
    period: PeriodInfo | null;
    rows: Record<string, unknown>[];
    truncated: boolean;
}

const FILTERS = ['affiliate_id', 'product_id', 'coupon_id'];

export default function ReportsPage() {
    const { range, filters, update, params } = useReportParams(FILTERS);
    const index = useApiData<{ reports: ReportInfo[]; can_export: boolean }>('/admin/reports');

    if (!index.data && index.error) return <Alert tone="error">{index.error}</Alert>;
    if (!index.data) return <Spinner />;

    const reports = index.data.reports;
    const selected = reports.find((r) => r.key === params.get('report')) ?? reports[0];
    if (!selected) {
        return (
            <div className="space-y-6">
                <h1 className="text-2xl font-semibold text-ink">Reports</h1>
                <Alert>Your role does not include any reports.</Alert>
            </div>
        );
    }

    // Send only the period and filters this report uses.
    const query = new URLSearchParams();
    if (selected.uses_period) {
        query.set('from', range.from);
        query.set('to', range.to);
    }
    for (const k of selected.filters) if (filters[k]) query.set(k, filters[k]);

    return (
        <div className="space-y-6">
            <h1 className="text-2xl font-semibold text-ink">Reports</h1>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-[16rem_1fr]">
                <nav aria-label="Reports" className="min-w-0">
                    <label className="block space-y-1 text-sm lg:hidden">
                        <span className="block font-medium text-ink">Report</span>
                        <select
                            value={selected.key}
                            onChange={(e) => update({ report: e.target.value })}
                            className="block min-h-11 w-full rounded-lg border border-line bg-surface px-3 text-base text-ink shadow-sm"
                        >
                            {reports.map((r) => (
                                <option key={r.key} value={r.key}>
                                    {r.title}
                                </option>
                            ))}
                        </select>
                    </label>
                    <ul className="hidden space-y-1 lg:block">
                        {reports.map((r) => (
                            <li key={r.key}>
                                <button
                                    type="button"
                                    onClick={() => update({ report: r.key })}
                                    aria-current={r.key === selected.key ? 'page' : undefined}
                                    className={`w-full rounded-lg px-3 py-2 text-left text-sm ${
                                        r.key === selected.key ? 'bg-brand-50 font-semibold text-brand-700' : 'text-ink hover:bg-canvas'
                                    }`}
                                >
                                    {r.title}
                                </button>
                            </li>
                        ))}
                    </ul>
                </nav>

                <div className="min-w-0 space-y-4">
                    <div>
                        <h2 className="text-lg font-semibold text-ink">{selected.title}</h2>
                        <p className="text-sm text-muted">{selected.description}</p>
                    </div>
                    <div className="flex flex-col gap-4">
                        {selected.uses_period ? (
                            <PeriodPicker range={range} onChange={(r) => update({ from: r.from, to: r.to })} />
                        ) : (
                            <p className="text-sm text-muted">This report shows the position right now, so it has no date range.</p>
                        )}
                        <FilterBar values={filters} onChange={update} show={selected.filters} />
                    </div>
                    <ReportPreview reportKey={selected.key} query={query.toString()} canExport={index.data.can_export} />
                </div>
            </div>
        </div>
    );
}

function ReportPreview({ reportKey, query, canExport }: { reportKey: string; query: string; canExport: boolean }) {
    const { data, error, loading } = useApiData<Preview>(`/admin/reports/${reportKey}?${query}`);
    const current = data?.key === reportKey ? data : null;

    return (
        <Card>
            <div className="mb-3 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <p className="text-sm text-muted">
                    {current?.period ? rangeLabel(current.period.from, current.period.to) : current ? 'As of now' : ''}
                    {current && ` · ${current.truncated ? 'first 100 rows shown, the export has them all' : `${current.rows.length} row${current.rows.length === 1 ? '' : 's'}`}`}
                    {loading && <span className="ml-2">Updating…</span>}
                </p>
                {canExport ? (
                    <ExportButtons path={`/admin/reports/${reportKey}/export`} query={query} label={current?.title} />
                ) : (
                    <p className="text-xs text-muted">Your role can view reports but not export them.</p>
                )}
            </div>
            {error && <Alert tone="error">{error}</Alert>}
            {!current ? (
                !error && <p className="py-6 text-center text-sm text-muted">Loading…</p>
            ) : (
                <div className={loading ? 'opacity-60' : ''}>
                    <DataTable columns={current.columns} rows={current.rows} rowKey={(_, i) => String(i)} empty="No rows for this period and filters." />
                </div>
            )}
        </Card>
    );
}
