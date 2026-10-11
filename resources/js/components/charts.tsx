import { useEffect, useId, useRef, useState, type KeyboardEvent, type PointerEvent } from 'react';
import { formatMoney } from '@/lib/format';
import { bucketLabel } from '@/reporting/period';

/**
 * Small SVG charts for the dashboards. One y-axis per chart, thin 2px lines,
 * series colours in fixed order (chart-1, chart-2, chart-3), a crosshair
 * tooltip on hover or arrow keys, a legend when there is more than one
 * series, and every chart can be read as a table.
 */

export type ValueFormat = 'count' | 'money' | 'percent';

const SERIES_COLORS = ['var(--color-chart-1)', 'var(--color-chart-2)', 'var(--color-chart-3)'];

const countFormat = new Intl.NumberFormat('en-IN');
const compact = new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 });

export function formatValue(value: number | null | undefined, format: ValueFormat, currency = 'NPR'): string {
    if (value === null || value === undefined) return '—';
    if (format === 'money') return formatMoney(value, currency);
    if (format === 'percent') return `${value.toFixed(value >= 10 ? 1 : 2)}%`;
    return countFormat.format(value);
}

function axisValue(value: number, format: ValueFormat): string {
    if (format === 'money') return compact.format(value / 100);
    if (format === 'percent') return `${compact.format(value)}%`;
    return compact.format(value);
}

/** Round axis maximum and step so gridlines land on readable numbers. */
function niceScale(max: number, format: ValueFormat): { top: number; ticks: number[] } {
    const unit = format === 'money' ? 100 : 1;
    const raw = Math.max(max / unit, format === 'percent' ? 1 : 4);
    const magnitude = 10 ** Math.floor(Math.log10(raw / 4));
    const step = [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((s) => s * 4 >= raw) ?? magnitude * 10;
    const integerStep = format === 'count' ? Math.max(1, Math.ceil(step)) : step;
    const ticks = [0, 1, 2, 3, 4].map((i) => i * integerStep * unit);
    return { top: 4 * integerStep * unit, ticks };
}

function useWidth<T extends HTMLElement>() {
    const ref = useRef<T>(null);
    const [width, setWidth] = useState(0);
    useEffect(() => {
        if (!ref.current) return;
        const observer = new ResizeObserver(([entry]) => entry && setWidth(Math.floor(entry.contentRect.width)));
        observer.observe(ref.current);
        return () => observer.disconnect();
    }, []);
    return [ref, width] as const;
}

export interface Series {
    key: string;
    label: string;
}

type Row = { bucket: string };

export function LineChart({
    title,
    data,
    series,
    format = 'count',
    currency = 'NPR',
    total,
    empty = 'Nothing in this period yet.',
}: {
    title: string;
    data: Row[];
    series: Series[];
    format?: ValueFormat;
    currency?: string;
    /** Optional headline shown next to the title, e.g. the period total. */
    total?: string;
    empty?: string;
}) {
    const [ref, width] = useWidth<HTMLDivElement>();
    const [active, setActive] = useState<number | null>(null);
    const titleId = useId();

    const value = (row: Row | undefined, key: string) => {
        const v = row ? (row as Record<string, unknown>)[key] : undefined;
        return typeof v === 'number' ? v : null;
    };
    const max = Math.max(0, ...data.flatMap((row) => series.map((s) => value(row, s.key) ?? 0)));
    // A 0% conversion is still a reading; zero clicks or sales everywhere is not.
    const hasData = data.some((row) => series.some((s) => {
        const v = value(row, s.key);
        return v !== null && (format === 'percent' || v !== 0);
    }));

    const height = 200;
    const margin = { top: 12, right: 12, bottom: 28, left: 44 };
    const plotW = Math.max(0, width - margin.left - margin.right);
    const plotH = height - margin.top - margin.bottom;
    const { top, ticks } = niceScale(max, format);
    const x = (i: number) => margin.left + (data.length <= 1 ? plotW / 2 : (i / (data.length - 1)) * plotW);
    const y = (v: number) => margin.top + plotH - (v / top) * plotH;

    const path = (key: string) => {
        let d = '';
        let pen = false;
        data.forEach((row, i) => {
            const v = value(row, key);
            if (v === null) {
                pen = false;
                return;
            }
            d += `${pen ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`;
            pen = true;
        });
        return d;
    };

    const labelIndexes = data.length <= 1 ? [0] : [0, Math.floor((data.length - 1) / 2), data.length - 1];

    const pick = (e: PointerEvent<SVGRectElement>) => {
        const box = e.currentTarget.getBoundingClientRect();
        const ratio = (e.clientX - box.left) / box.width;
        setActive(Math.max(0, Math.min(data.length - 1, Math.round(ratio * (data.length - 1)))));
    };
    const onKey = (e: KeyboardEvent<HTMLDivElement>) => {
        if (e.key === 'ArrowRight') setActive((a) => Math.min(data.length - 1, (a ?? -1) + 1));
        else if (e.key === 'ArrowLeft') setActive((a) => Math.max(0, (a ?? data.length) - 1));
        else if (e.key === 'Escape') setActive(null);
        else return;
        e.preventDefault();
    };

    const activeRow = active !== null ? data[active] : null;
    const tooltipLeft = active !== null ? x(active) : 0;
    const flip = tooltipLeft > width / 2;

    return (
        <figure aria-labelledby={titleId} className="min-w-0 rounded-xl border border-line bg-surface p-4 shadow-sm">
            <figcaption className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 text-sm">
                <span id={titleId} className="font-semibold text-ink">
                    {title}
                </span>
                {total && <span className="text-muted tabular-nums">{total}</span>}
            </figcaption>

            {series.length > 1 && (
                <ul className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                    {series.map((s, i) => (
                        <li key={s.key} className="flex items-center gap-1.5">
                            <span aria-hidden="true" className="inline-block h-0.5 w-4 rounded-full" style={{ background: SERIES_COLORS[i] }} />
                            {s.label}
                        </li>
                    ))}
                </ul>
            )}

            <div
                ref={ref}
                className="relative mt-2 outline-offset-4"
                tabIndex={hasData ? 0 : undefined}
                onKeyDown={onKey}
                onBlur={() => setActive(null)}
                aria-label={hasData ? `${title}. Use the left and right arrow keys to read each point.` : undefined}
            >
                {!hasData ? (
                    <div className="flex h-[200px] items-center justify-center rounded-lg bg-canvas text-sm text-muted">{empty}</div>
                ) : (
                    width > 0 && (
                        <svg width={width} height={height} role="img" aria-hidden="true" className="block">
                            {ticks.map((t) => (
                                <g key={t}>
                                    <line x1={margin.left} x2={width - margin.right} y1={y(t)} y2={y(t)} stroke="var(--color-line)" strokeWidth={1} />
                                    <text x={margin.left - 6} y={y(t)} dy="0.32em" textAnchor="end" className="fill-muted text-[11px] tabular-nums">
                                        {axisValue(t, format)}
                                    </text>
                                </g>
                            ))}
                            {labelIndexes.map((i, n) => (
                                <text
                                    key={`${i}-${n}`}
                                    x={x(i)}
                                    y={height - 8}
                                    textAnchor={n === 0 && data.length > 1 ? 'start' : n === labelIndexes.length - 1 && data.length > 1 ? 'end' : 'middle'}
                                    className="fill-muted text-[11px]"
                                >
                                    {bucketLabel(data[i]?.bucket ?? '')}
                                </text>
                            ))}
                            {series.map((s, i) => (
                                <path key={s.key} d={path(s.key)} fill="none" stroke={SERIES_COLORS[i]} strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />
                            ))}
                            {/* Single points have no line to show them, so draw a marker. */}
                            {series.map((s, i) =>
                                data.map((row, n) => {
                                    const v = value(row, s.key);
                                    const prev = n > 0 ? value(data[n - 1], s.key) : null;
                                    const next = n < data.length - 1 ? value(data[n + 1], s.key) : null;
                                    return v !== null && prev === null && next === null ? <circle key={`${s.key}-${n}`} cx={x(n)} cy={y(v)} r={3} fill={SERIES_COLORS[i]} /> : null;
                                }),
                            )}
                            {activeRow && active !== null && (
                                <g>
                                    <line x1={x(active)} x2={x(active)} y1={margin.top} y2={margin.top + plotH} stroke="var(--color-muted)" strokeWidth={1} strokeDasharray="3 3" />
                                    {series.map((s, i) => {
                                        const v = value(activeRow, s.key);
                                        return v === null ? null : <circle key={s.key} cx={x(active)} cy={y(v)} r={4.5} fill={SERIES_COLORS[i]} stroke="var(--color-surface)" strokeWidth={2} />;
                                    })}
                                </g>
                            )}
                            <rect
                                x={margin.left}
                                y={margin.top}
                                width={plotW}
                                height={plotH}
                                fill="transparent"
                                onPointerMove={pick}
                                onPointerDown={pick}
                                onPointerLeave={() => setActive(null)}
                            />
                        </svg>
                    )
                )}

                {activeRow && (
                    <div
                        role="status"
                        className="pointer-events-none absolute top-2 z-10 min-w-36 rounded-lg border border-line bg-surface px-3 py-2 text-xs shadow-md"
                        style={flip ? { right: Math.max(0, width - tooltipLeft + 8) } : { left: tooltipLeft + 8 }}
                    >
                        <p className="font-semibold text-ink">{bucketLabel(activeRow.bucket, true)}</p>
                        {series.map((s, i) => (
                            <p key={s.key} className="mt-0.5 flex items-center justify-between gap-3 text-muted">
                                <span className="flex items-center gap-1.5">
                                    <span aria-hidden="true" className="inline-block h-2 w-2 rounded-full" style={{ background: SERIES_COLORS[i] }} />
                                    {s.label}
                                </span>
                                <span className="font-medium text-ink tabular-nums">{formatValue(value(activeRow, s.key), format, currency)}</span>
                            </p>
                        ))}
                    </div>
                )}
            </div>

            {hasData && (
                <details className="mt-2 text-xs">
                    <summary className="cursor-pointer text-muted">Show as table</summary>
                    <div className="mt-2 max-h-64 overflow-auto">
                        <table className="w-full text-left">
                            <thead className="sticky top-0 bg-surface">
                                <tr className="text-muted">
                                    <th className="py-1 pr-2 font-medium">Date</th>
                                    {series.map((s) => (
                                        <th key={s.key} className="py-1 pl-2 text-right font-medium">
                                            {s.label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {data.map((row) => (
                                    <tr key={row.bucket} className="border-t border-line">
                                        <td className="py-1 pr-2 whitespace-nowrap">{bucketLabel(row.bucket, true)}</td>
                                        {series.map((s) => (
                                            <td key={s.key} className="py-1 pl-2 text-right tabular-nums">
                                                {formatValue(value(row, s.key), format, currency)}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </details>
            )}
        </figure>
    );
}

/**
 * Ranked horizontal bars with the value written beside each one, for
 * product and campaign performance. One hue: the bars show size, not identity.
 */
export function BarList({
    title,
    items,
    format = 'money',
    currency = 'NPR',
    empty = 'Nothing in this period yet.',
}: {
    title: string;
    items: { key: string; label: string; value: number; detail?: string }[];
    format?: ValueFormat;
    currency?: string;
    empty?: string;
}) {
    const max = Math.max(1, ...items.map((i) => i.value));

    return (
        <section className="min-w-0 rounded-xl border border-line bg-surface p-4 shadow-sm">
            <h2 className="text-sm font-semibold text-ink">{title}</h2>
            {items.length === 0 ? (
                <p className="mt-3 rounded-lg bg-canvas px-3 py-6 text-center text-sm text-muted">{empty}</p>
            ) : (
                <ul className="mt-3 space-y-3">
                    {items.map((item) => (
                        <li key={item.key} className="text-sm">
                            <div className="flex items-baseline justify-between gap-3">
                                <span className="min-w-0 truncate text-ink" title={item.label}>
                                    {item.label}
                                </span>
                                <span className="shrink-0 font-medium text-ink tabular-nums">{formatValue(item.value, format, currency)}</span>
                            </div>
                            <div className="mt-1 h-2 rounded-full bg-canvas" aria-hidden="true">
                                <div className="h-2 rounded-full" style={{ width: `${Math.max(item.value > 0 ? 2 : 0, (item.value / max) * 100)}%`, background: SERIES_COLORS[0] }} />
                            </div>
                            {item.detail && <p className="mt-0.5 text-xs text-muted">{item.detail}</p>}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
