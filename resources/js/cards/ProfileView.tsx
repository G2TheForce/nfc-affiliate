import type { ReactNode } from 'react';
import { initials, SOCIALS, themeOf, type SocialKey } from '@/cards/types';

export interface ProfileViewData {
    display_name: string;
    headline: string | null;
    company: string | null;
    bio: string | null;
    phone: string | null;
    whatsapp: string | null;
    email: string | null;
    website: string | null;
    location: string | null;
    theme: string;
    socials: { network: SocialKey; url: string }[];
}

const digits = (phone: string) => phone.replace(/[^\d+]/g, '');

/**
 * The tap profile as visitors see it. Used by the public page and as the
 * live preview in the editor (where links are inert).
 */
export function ProfileView({ data, preview = false, footer }: { data: ProfileViewData; preview?: boolean; footer?: ReactNode }) {
    const [bg, mid, glow] = themeOf(data.theme).palette;
    const sub = [data.headline, data.company].filter(Boolean).join(' · ');
    const actions = [
        data.phone && {
            label: 'Call',
            href: `tel:${digits(data.phone)}`,
            icon: 'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2',
        },
        data.whatsapp && {
            label: 'WhatsApp',
            href: `https://wa.me/${digits(data.whatsapp).replace(/^\+/, '')}`,
            icon: 'M3 21l1.6-4.6A8.5 8.5 0 1 1 7.7 19.6zM9 8.5c0 3.5 3 6.5 6.5 6.5l1-1.6-2-1-1 1a5 5 0 0 1-2.4-2.4l1-1-1-2z',
        },
        data.email && { label: 'Email', href: `mailto:${data.email}`, icon: 'M3 6h18v12H3zM3 6l9 7 9-7' },
        data.website && {
            label: 'Website',
            href: data.website,
            icon: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM3 12h18M12 3c3 3.5 3 14.5 0 18M12 3c-3 3.5-3 14.5 0 18',
        },
    ].filter((a): a is { label: string; href: string; icon: string } => !!a);

    const linkProps = (href: string) =>
        preview
            ? { href: undefined, 'aria-disabled': true, tabIndex: -1 }
            : { href, ...(href.startsWith('http') ? { target: '_blank', rel: 'noopener noreferrer' } : {}) };

    return (
        <div className="overflow-hidden rounded-3xl bg-surface shadow-2xl shadow-primary/10 ring-1 ring-line">
            <div className="relative h-36" style={{ background: `linear-gradient(120deg, ${bg}, ${mid} 65%, ${glow})` }}>
                <div className="holo-sheen absolute inset-0 animate-shine" aria-hidden />
                <svg viewBox="0 0 40 40" className="absolute right-5 top-5 h-8 w-8 opacity-80" aria-hidden>
                    <g fill="none" stroke="#fff" strokeWidth="3" strokeLinecap="round">
                        <path d="M10 14a8 8 0 0 1 0 12" opacity="0.55" />
                        <path d="M17 9a15 15 0 0 1 0 22" opacity="0.75" />
                        <path d="M24 4a22 22 0 0 1 0 32" />
                    </g>
                </svg>
            </div>
            <div className="-mt-12 px-6 pb-6 text-center">
                <div
                    className="relative mx-auto flex h-24 w-24 items-center justify-center rounded-full border-4 border-surface text-3xl font-bold text-white shadow-lg"
                    style={{ background: `linear-gradient(135deg, ${glow}, ${mid})` }}
                    aria-hidden
                >
                    {initials(data.display_name)}
                </div>
                <h1 className="mt-3 text-2xl font-bold tracking-tight text-ink">{data.display_name || 'Your name'}</h1>
                {sub && <p className="mt-1 text-muted">{sub}</p>}
                {data.location && <p className="mt-1 text-sm text-muted">{data.location}</p>}
                {data.bio && <p className="mx-auto mt-4 max-w-sm whitespace-pre-line text-sm text-ink">{data.bio}</p>}

                {actions.length > 0 && (
                    <ul className="mt-6 grid grid-cols-2 gap-2 sm:grid-cols-4">
                        {actions.map((a) => (
                            <li key={a.label}>
                                <a
                                    {...linkProps(a.href)}
                                    className="flex flex-col items-center gap-1 rounded-2xl border border-line bg-canvas px-2 py-3 text-xs font-semibold text-ink transition hover:border-brand-500"
                                >
                                    <svg
                                        viewBox="0 0 24 24"
                                        className="h-5 w-5 text-brand-600"
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth="2"
                                        strokeLinejoin="round"
                                        aria-hidden
                                    >
                                        <path d={a.icon} />
                                    </svg>
                                    {a.label}
                                </a>
                            </li>
                        ))}
                    </ul>
                )}

                {data.socials.length > 0 && (
                    <ul className="mt-4 flex flex-wrap justify-center gap-2" aria-label="Social profiles">
                        {data.socials.map((s) => (
                            <li key={s.network}>
                                <a
                                    {...linkProps(s.url)}
                                    className="inline-flex rounded-full border border-line px-3 py-1.5 text-sm font-medium text-ink hover:border-brand-500"
                                >
                                    {SOCIALS.find((x) => x.key === s.network)?.label ?? s.network}
                                </a>
                            </li>
                        ))}
                    </ul>
                )}
                {footer}
            </div>
        </div>
    );
}
