import { formatDate } from '@/lib/format';

/**
 * One series per chart (no dual axes). Bars are anchored to the baseline;
 * each bar has a hover tooltip and the numbers are available as a table.
 */
export function DailyBars({ title, data, valueKey }: { title: string; data: { date: string; clicks: number; orders: number }[]; valueKey: 'clicks' | 'orders' }) {
    const max = Math.max(1, ...data.map((d) => d[valueKey]));
    const total = data.reduce((n, d) => n + d[valueKey], 0);

    return (
        <figure className="rounded-xl border border-line bg-surface p-4 shadow-sm">
            <figcaption className="flex items-baseline justify-between text-sm">
                <span className="font-semibold text-ink">{title}</span>
                <span className="text-muted tabular-nums">{total} total</span>
            </figcaption>
            <div className="mt-3 flex h-28 items-end gap-0.5 border-b border-line" aria-hidden="true">
                {data.map((d) => (
                    <div key={d.date} className="group relative flex h-full flex-1 items-end" title={`${formatDate(d.date)}: ${d[valueKey]}`}>
                        <div
                            className="w-full rounded-t bg-brand-500 group-hover:bg-primary"
                            style={{ height: d[valueKey] ? `${Math.max(4, (d[valueKey] / max) * 100)}%` : 0 }}
                        />
                    </div>
                ))}
            </div>
            <div className="mt-1 flex justify-between text-xs text-muted" aria-hidden="true">
                <span>{data[0] && formatDate(data[0].date)}</span>
                <span>{data.at(-1) && formatDate(data.at(-1)?.date)}</span>
            </div>
            <details className="mt-2 text-xs">
                <summary className="cursor-pointer text-muted">Show as table</summary>
                <table className="mt-2 w-full text-left">
                    <thead>
                        <tr className="text-muted">
                            <th className="py-1 font-medium">Date</th>
                            <th className="py-1 text-right font-medium">{title}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {data.map((d) => (
                            <tr key={d.date}>
                                <td className="py-0.5">{formatDate(d.date)}</td>
                                <td className="py-0.5 text-right tabular-nums">{d[valueKey]}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </details>
        </figure>
    );
}
