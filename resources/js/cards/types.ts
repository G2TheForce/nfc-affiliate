export const THEMES = {
    aurora: { label: 'Aurora', palette: ['#0b1026', '#4f46e5', '#22d3ee'] },
    violet: { label: 'Violet', palette: ['#140a24', '#a855f7', '#f472b6'] },
    teal: { label: 'Teal', palette: ['#03191c', '#0d9488', '#5eead4'] },
    gold: { label: 'Gold', palette: ['#1a1203', '#b45309', '#fcd34d'] },
    graphite: { label: 'Graphite', palette: ['#0a0a0a', '#334155', '#e2e8f0'] },
    ocean: { label: 'Ocean', palette: ['#06121f', '#2563eb', '#a855f7'] },
} satisfies Record<string, { label: string; palette: [string, string, string] }>;

export type ThemeKey = keyof typeof THEMES;

export const SOCIALS = [
    { key: 'instagram', label: 'Instagram' },
    { key: 'facebook', label: 'Facebook' },
    { key: 'linkedin', label: 'LinkedIn' },
    { key: 'tiktok', label: 'TikTok' },
    { key: 'youtube', label: 'YouTube' },
    { key: 'x', label: 'X' },
] as const;

export type SocialKey = (typeof SOCIALS)[number]['key'];

interface ProfileFields {
    handle: string;
    display_name: string;
    headline: string | null;
    company: string | null;
    bio: string | null;
    phone: string | null;
    whatsapp: string | null;
    email: string | null;
    website: string | null;
    location: string | null;
    theme: ThemeKey;
    public_url: string;
}

export interface OwnCardProfile extends ProfileFields {
    socials: Partial<Record<SocialKey, string>>;
    is_published: boolean;
    taps_total: number;
    saves_total: number;
}

export interface PublicCardProfile extends ProfileFields {
    socials: { network: SocialKey; url: string }[];
    vcard_url: string;
    get_a_card_url: string;
}

export const themeOf = (key: string) => THEMES[key as ThemeKey] ?? THEMES.aurora;

export const initials = (name: string) =>
    name
        .replace(/\[[^\]]*\]/g, '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase())
        .join('') || '?';
