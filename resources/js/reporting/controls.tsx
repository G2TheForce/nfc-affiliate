import { useId, useState } from 'react';
import { useSearchParams } from 'react-router';
import { Button } from '@/components/ui';
import { ApiError } from '@/lib/api';
import { downloadFile } from '@/lib/download';
import { DEFAULT_PRESET, PRESETS, presetFor, type Range } from './period';
import type { Definitions } from './types';

const count = new Intl.NumberFormat('en-IN');

export const formatCount = (n: number | null | undefined) => (n === null || n === undefined ? '—' : count.format(n));
export const formatPercent = (n: number | null | undefined) => (n === null || n === undefined ? '—' : `${n.toFixed(n >= 10 ? 1 : 2)}%`);

/**
 * The selected range and filters live in the address bar, so a dashboard
 * view can be bookmarked or shared with a colleague.
 */
export function useReportParams(filterKeys: string[] = []) {
    const [params, setParams] = useSearchParams();
    const fallback = PRESETS.find((p) => p.key === DEFAULT_PRESET)!.range();
    const range: Range = { from: params.get('from') ?? fallback.from, to: params.get('to') ?? fallback.to };
    const filters = Object.fromEntries(filterKeys.map((k) => [k, params.get(k) ?? '']));

    const update = (changes: Record<string, string>) => {
        const next = new URLSearchParams(params);
        for (const [k, v] of Object.entries(changes)) {
            if (v) next.set(k, v);
            else next.delete(k);
        }
        setParams(next, { replace: true });
    };

    const query = new URLSearchParams({ from: range.from, to: range.to });
    for (const [k, v] of Object.entries(filters)) if (v) query.set(k, v);

    return { range, filters, update, query: query.toString(), params };
}

export function PeriodPicker({ range, onChange }: { range: Range; onChange: (r: Range) => void }) {
    const uid = useId();
    const preset = presetFor(range);
    const [custom, setCustom] = useState(preset === 'custom');
    const showDates = custom || preset === 'custom';

    return (
        <div className="flex flex-wrap items-end gap-3">
            <div className="block space-y-1 text-sm">
                <label htmlFor={`${uid}-1`} className="block font-medium text-ink">Period</label>
                <select
                    id={`${uid}-1`}
                    value={showDates ? 'custom' : preset}
                    onChange={(e) => {
                        if (e.target.value === 'custom') return setCustom(true);
                        setCustom(false);
                        onChange(PRESETS.find((p) => p.key === e.target.value)!.range());
                    }}
                    className="block min-h-11 rounded-lg border border-line bg-surface px-3 text-base text-ink shadow-sm sm:text-sm"
                >
                    {PRESETS.map((p) => (
                        <option key={p.key} value={p.key}>
                            {p.label}
                        </option>
                    ))}
                    <option value="custom">Custom dates</option>
                </select>
            </div>
            {showDates && (
                <>
                    <div className="block space-y-1 text-sm">
                        <label htmlFor={`${uid}-2`} className="block font-medium text-ink">From</label>
                        <input
                            id={`${uid}-2`}
                            type="date"
                            value={range.from}
                            max={range.to}
                            onChange={(e) => e.target.value && onChange({ ...range, from: e.target.value })}
                            className="block min-h-11 rounded-lg border border-line bg-surface px-3 text-base text-ink shadow-sm sm:text-sm"
                        />
                    </div>
                    <div className="block space-y-1 text-sm">
                        <label htmlFor={`${uid}-3`} className="block font-medium text-ink">To</label>
                        <input
                            id={`${uid}-3`}
                            type="date"
                            value={range.to}
                            min={range.from}
                            onChange={(e) => e.target.value && onChange({ ...range, to: e.target.value })}
                            className="block min-h-11 rounded-lg border border-line bg-surface px-3 text-base text-ink shadow-sm sm:text-sm"
                        />
                    </div>
                </>
            )}
        </div>
    );
}

/** CSV and XLSX buttons for one report. Errors are shown next to them. */
export function ExportButtons({ path, query, label }: { path: string; query: string; label?: string }) {
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const run = async (format: 'csv' | 'xlsx') => {
        setBusy(format);
        setError(null);
        try {
            await downloadFile(`${path}?${query}${query ? '&' : ''}format=${format}`);
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'The export could not be created.');
        } finally {
            setBusy(null);
        }
    };

    return (
        <div className="shrink-0 space-y-1">
            <div className="flex flex-wrap gap-2">
                <Button variant="secondary" loading={busy === 'csv'} disabled={busy !== null} onClick={() => run('csv')} aria-label={label ? `${label} as CSV` : undefined}>
                    CSV
                </Button>
                <Button variant="secondary" loading={busy === 'xlsx'} disabled={busy !== null} onClick={() => run('xlsx')} aria-label={label ? `${label} as Excel` : undefined}>
                    Excel
                </Button>
            </div>
            {error && (
                <p role="alert" className="text-sm text-danger">
                    {error}
                </p>
            )}
        </div>
    );
}

const DEFINITION_LABELS: Record<string, string> = {
    clicks: 'Clicks',
    unique_clicks: 'Unique clicks',
    attributed_orders: 'Attributed orders',
    conversion_rate: 'Conversion',
    paid_orders: 'Paid orders',
    revenue_minor: 'Sales',
    commissionable_minor: 'Commissionable sales',
    commission_earned_minor: 'Commission earned',
    commission_reversed_minor: 'Commission reversed',
    refunded_minor: 'Refunded',
    pending: 'Pending',
    approved: 'Approved',
    available: 'Available',
    reserved: 'In payout',
    paid: 'Paid',
    reversed: 'Reversed',
};

export function DefinitionList({ definitions, timezone }: { definitions: Definitions; timezone: string }) {
    return (
        <details className="rounded-xl border border-line bg-surface p-4 text-sm shadow-sm">
            <summary className="cursor-pointer font-medium text-ink">How these numbers are counted</summary>
            <dl className="mt-3 grid gap-3 sm:grid-cols-2">
                {Object.entries(definitions).map(([key, text]) => (
                    <div key={key}>
                        <dt className="font-medium text-ink">{DEFINITION_LABELS[key] ?? key}</dt>
                        <dd className="text-muted">{text}</dd>
                    </div>
                ))}
            </dl>
            <p className="mt-3 text-xs text-muted">Days start and end at midnight {timezone} time. Amounts are in Nepali rupees.</p>
        </details>
    );
}
