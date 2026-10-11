import type { ReactNode } from 'react';
import { formatValue } from '@/components/charts';
import type { ReportColumn } from './types';

type Cell = string | number | null | undefined | ReactNode;

/**
 * A plain table that scrolls sideways inside its card on small screens
 * instead of widening the page. Numbers are right-aligned.
 */
export function DataTable({
    columns,
    rows,
    rowKey,
    currency = 'NPR',
    empty = 'Nothing in this period yet.',
}: {
    columns: (ReportColumn & { render?: (row: Record<string, unknown>) => ReactNode })[];
    rows: Record<string, unknown>[];
    rowKey: (row: Record<string, unknown>, index: number) => string;
    currency?: string;
    empty?: string;
}) {
    if (rows.length === 0) return <p className="rounded-lg bg-canvas px-3 py-6 text-center text-sm text-muted">{empty}</p>;

    const numeric = (c: ReportColumn) => c.type === 'int' || c.type === 'money' || c.type === 'percent';
    const show = (c: ReportColumn, value: unknown): Cell => {
        if (c.type === 'money') return formatValue(value as number | null, 'money', currency);
        if (c.type === 'int') return formatValue(value as number | null, 'count');
        if (c.type === 'percent') return formatValue(value === null || value === undefined ? null : Number(value), 'percent');
        return value === null || value === undefined || value === '' ? '—' : String(value);
    };

    return (
        <div className="-mx-1 overflow-x-auto px-1">
            <table className="w-full text-left text-sm">
                <thead>
                    <tr className="border-b border-line text-xs text-muted">
                        {columns.map((c) => (
                            <th key={c.key} scope="col" className={`px-2 py-2 font-medium whitespace-nowrap ${numeric(c) ? 'text-right' : ''}`}>
                                {c.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => (
                        <tr key={rowKey(row, i)} className="border-b border-line last:border-0">
                            {columns.map((c) => (
                                <td key={c.key} className={`px-2 py-2 ${numeric(c) ? 'text-right whitespace-nowrap tabular-nums' : 'text-ink'}`}>
                                    {c.render ? c.render(row) : show(c, row[c.key])}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
