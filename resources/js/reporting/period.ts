/**
 * Date ranges as Nepal calendar dates (YYYY-MM-DD). The API reads them in
 * Asia/Kathmandu, so "today" must be today there, not in the browser.
 */

const nepalDate = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Kathmandu', year: 'numeric', month: '2-digit', day: '2-digit' });

export const todayInNepal = () => nepalDate.format(new Date());

const toUtc = (ymd: string) => {
    const [y = 1970, m = 1, d = 1] = ymd.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d));
};
const fromUtc = (date: Date) => date.toISOString().slice(0, 10);

export const addDays = (ymd: string, days: number) => {
    const date = toUtc(ymd);
    date.setUTCDate(date.getUTCDate() + days);
    return fromUtc(date);
};

export interface Range {
    from: string;
    to: string;
}

export const PRESETS: { key: string; label: string; range: () => Range }[] = [
    { key: '7d', label: 'Last 7 days', range: () => ({ from: addDays(todayInNepal(), -6), to: todayInNepal() }) },
    { key: '30d', label: 'Last 30 days', range: () => ({ from: addDays(todayInNepal(), -29), to: todayInNepal() }) },
    { key: '90d', label: 'Last 90 days', range: () => ({ from: addDays(todayInNepal(), -89), to: todayInNepal() }) },
    { key: 'month', label: 'This month', range: () => ({ from: `${todayInNepal().slice(0, 8)}01`, to: todayInNepal() }) },
    {
        key: 'last-month',
        label: 'Last month',
        range: () => {
            const end = addDays(`${todayInNepal().slice(0, 8)}01`, -1);
            return { from: `${end.slice(0, 8)}01`, to: end };
        },
    },
    { key: '12m', label: 'Last 12 months', range: () => ({ from: addDays(todayInNepal(), -364), to: todayInNepal() }) },
];

export const DEFAULT_PRESET = '30d';

export const presetFor = (range: Range) => PRESETS.find((p) => {
    const r = p.range();
    return r.from === range.from && r.to === range.to;
})?.key ?? 'custom';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** A chart bucket ("2026-09-01" or "2026-09") as a short label, without timezone shifts. */
export const bucketLabel = (bucket: string, long = false) => {
    const [y, m, d] = bucket.split('-');
    const month = MONTHS[Number(m) - 1] ?? '';
    if (!d) return `${month} ${y}`;
    return long ? `${Number(d)} ${month} ${y}` : `${Number(d)} ${month}`;
};

export const rangeLabel = (from: string, to: string) => `${bucketLabel(from, true)} to ${bucketLabel(to, true)}`;
