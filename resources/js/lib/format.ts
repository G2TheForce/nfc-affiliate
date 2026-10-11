const dateTime = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Kathmandu' });
const dateOnly = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeZone: 'Asia/Kathmandu' });

export const formatDateTime = (iso: string | null | undefined) => (iso ? dateTime.format(new Date(iso)) : '—');
export const formatDate = (iso: string | null | undefined) => (iso ? dateOnly.format(new Date(iso)) : '—');

export const CHANNELS: { value: string; label: string }[] = [
    { value: 'website', label: 'Website or blog' },
    { value: 'facebook', label: 'Facebook' },
    { value: 'instagram', label: 'Instagram' },
    { value: 'tiktok', label: 'TikTok' },
    { value: 'youtube', label: 'YouTube' },
    { value: 'whatsapp', label: 'WhatsApp' },
    { value: 'viber', label: 'Viber' },
    { value: 'telegram', label: 'Telegram' },
    { value: 'email', label: 'Email newsletter' },
    { value: 'offline', label: 'Offline / in person' },
    { value: 'other', label: 'Other' },
];

export const channelLabel = (value: string) => CHANNELS.find((c) => c.value === value)?.label ?? value;

const grouping = new Intl.NumberFormat('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 2 });

/** Minor units (paisa) to a display amount, e.g. 150000 → "NPR 1,500". */
export const formatMoney = (minor: number | null | undefined, currency = 'NPR') =>
    minor === null || minor === undefined ? '—' : `${currency} ${grouping.format(minor / 100)}`;

/** Major-unit text typed by staff (e.g. "1500.50") to minor units. */
export const toMinor = (major: string): number => Math.round(Number(major) * 100);
