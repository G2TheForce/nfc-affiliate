import { useEffect, useState, type FormEvent } from 'react';
import { useAuth } from '@/auth/AuthContext';
import { canWriteNfc, writeUrlToTag } from '@/cards/nfc';
import { ProfileView } from '@/cards/ProfileView';
import { QrCode } from '@/cards/QrCode';
import { SOCIALS, THEMES, type OwnCardProfile, type SocialKey, type ThemeKey } from '@/cards/types';
import { CardArt, TiltCard } from '@/components/brand';
import { DailyBars } from '@/components/DailyBars';
import { Alert, Button, Card, Field, Spinner, TextArea } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useFormErrors } from '@/lib/useFormErrors';

type Form = {
    handle: string;
    display_name: string;
    headline: string;
    company: string;
    bio: string;
    phone: string;
    whatsapp: string;
    email: string;
    website: string;
    location: string;
    theme: ThemeKey;
    is_published: boolean;
    socials: Partial<Record<SocialKey, string>>;
};

const TEXT_FIELDS = ['headline', 'company', 'bio', 'phone', 'whatsapp', 'email', 'website', 'location'] as const;

const suggestHandle = (name: string) =>
    name
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 30);

const toForm = (p: OwnCardProfile): Form =>
    ({
        ...p,
        ...Object.fromEntries(TEXT_FIELDS.map((k) => [k, p[k] ?? ''])),
        socials: { ...p.socials },
    }) as Form;

interface Daily {
    day: string;
    taps: number;
    saves: number;
}

/** The customer's digital tap profile: edit, preview, publish, then put it on a card. */
export default function TapCardPage() {
    const { user } = useAuth();
    const [loaded, setLoaded] = useState(false);
    const [saved, setSaved] = useState<OwnCardProfile | null>(null);
    const [form, setForm] = useState<Form | null>(null);
    const [saving, setSaving] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [daily, setDaily] = useState<Daily[] | null>(null);
    const errors = useFormErrors();

    useEffect(() => {
        api<{ data: OwnCardProfile | null }>('/card-profile')
            .then((r) => {
                setSaved(r.data);
                setForm(
                    r.data
                        ? toForm(r.data)
                        : {
                              handle: suggestHandle(user?.name ?? ''),
                              display_name: user?.name ?? '',
                              headline: '',
                              company: '',
                              bio: '',
                              phone: user?.phone ?? '',
                              whatsapp: '',
                              email: user?.email ?? '',
                              website: '',
                              location: '',
                              theme: 'aurora',
                              is_published: true,
                              socials: {},
                          },
                );
                setLoaded(true);
            })
            .catch((e) => setLoadError(e instanceof ApiError ? e.message : 'Could not load your tap card.'));
    }, [user]);

    useEffect(() => {
        if (!saved) return;
        api<{ data: { daily: Daily[] } }>('/card-profile/stats')
            .then((r) => setDaily(r.data.daily))
            .catch(() => undefined);
    }, [saved]);

    if (loadError) return <Alert tone="error">{loadError}</Alert>;
    if (!loaded || !form) return <Spinner />;

    const set = <K extends keyof Form>(key: K, value: Form[K]) => setForm((f) => (f ? { ...f, [key]: value } : f));
    const blankToNull = (v: string) => (v.trim() === '' ? null : v.trim());

    const onSubmit = async (e: FormEvent) => {
        e.preventDefault();
        errors.reset();
        setNotice(null);
        setSaving(true);
        try {
            const body = {
                ...form,
                ...Object.fromEntries(TEXT_FIELDS.map((k) => [k, blankToNull(form[k])])),
                socials: Object.fromEntries(
                    Object.entries(form.socials)
                        .filter(([, v]) => v && v.trim() !== '')
                        .map(([k, v]) => [k, v!.trim()]),
                ),
            };
            const r = await api<{ data: OwnCardProfile }>('/card-profile', { method: 'PUT', body });
            setSaved(r.data);
            setForm(toForm(r.data));
            setNotice(r.data.is_published ? 'Saved. Your profile is live.' : 'Saved. Your profile is hidden until you publish it.');
        } catch (err) {
            errors.capture(err);
        } finally {
            setSaving(false);
        }
    };

    const preview = {
        ...form,
        ...Object.fromEntries(TEXT_FIELDS.map((k) => [k, blankToNull(form[k])])),
        website: /^https?:\/\//.test(form.website) ? form.website : null,
        socials: SOCIALS.filter((s) => form.socials[s.key]?.trim()).map((s) => ({ network: s.key, url: '#' })),
    } as Parameters<typeof ProfileView>[0]['data'];
    const origin = window.location.origin;

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-2xl font-semibold text-ink">My tap card</h1>
                <p className="mt-1 text-sm text-muted">
                    Your NFC card opens this page. People can call you, message you, follow you and save your contact with one tap. Change it any time; the card
                    never needs reprinting.
                </p>
            </div>

            {saved && <ShareCard profile={saved} />}

            <div className="grid gap-6 lg:grid-cols-[1fr_22rem]">
                <form onSubmit={onSubmit} className="space-y-6" noValidate>
                    {errors.message && <Alert tone="error">{errors.message}</Alert>}
                    {notice && <Alert tone="success">{notice}</Alert>}

                    <Card title="About you">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Name on the profile"
                                value={form.display_name}
                                maxLength={80}
                                required
                                onChange={(e) => set('display_name', e.target.value)}
                                error={errors.fieldErrors.display_name}
                            />
                            <Field
                                label="Job title"
                                value={form.headline}
                                maxLength={100}
                                onChange={(e) => set('headline', e.target.value)}
                                error={errors.fieldErrors.headline}
                            />
                            <Field
                                label="Company"
                                value={form.company}
                                maxLength={100}
                                onChange={(e) => set('company', e.target.value)}
                                error={errors.fieldErrors.company}
                            />
                            <Field
                                label="Location"
                                value={form.location}
                                maxLength={100}
                                placeholder="e.g. Lalitpur"
                                onChange={(e) => set('location', e.target.value)}
                                error={errors.fieldErrors.location}
                            />
                        </div>
                        <div className="mt-4">
                            <TextArea
                                label="Short bio"
                                value={form.bio}
                                maxLength={500}
                                onChange={(e) => set('bio', e.target.value)}
                                error={errors.fieldErrors.bio}
                                hint={`${form.bio.length}/500`}
                            />
                        </div>
                    </Card>

                    <Card title="Contact buttons">
                        <p className="-mt-1 mb-4 text-sm text-muted">Only what you fill in is shown. These are public once you publish.</p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Phone"
                                type="tel"
                                value={form.phone}
                                onChange={(e) => set('phone', e.target.value)}
                                error={errors.fieldErrors.phone}
                            />
                            <Field
                                label="WhatsApp number"
                                type="tel"
                                value={form.whatsapp}
                                placeholder="+977…"
                                onChange={(e) => set('whatsapp', e.target.value)}
                                error={errors.fieldErrors.whatsapp}
                            />
                            <Field
                                label="Email"
                                type="email"
                                value={form.email}
                                onChange={(e) => set('email', e.target.value)}
                                error={errors.fieldErrors.email}
                            />
                            <Field
                                label="Website"
                                type="url"
                                value={form.website}
                                placeholder="https://"
                                onChange={(e) => set('website', e.target.value)}
                                error={errors.fieldErrors.website}
                            />
                        </div>
                    </Card>

                    <Card title="Social profiles">
                        <p className="-mt-1 mb-4 text-sm text-muted">Your username, or a full https:// link.</p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {SOCIALS.map((s) => (
                                <Field
                                    key={s.key}
                                    label={s.label}
                                    value={form.socials[s.key] ?? ''}
                                    placeholder="@username"
                                    onChange={(e) => set('socials', { ...form.socials, [s.key]: e.target.value })}
                                    error={errors.fieldErrors[`socials.${s.key}`]}
                                />
                            ))}
                        </div>
                    </Card>

                    <Card title="Look and address">
                        <fieldset>
                            <legend className="mb-2 text-sm font-medium text-ink">Colour theme</legend>
                            <div className="grid grid-cols-3 gap-3 sm:grid-cols-6">
                                {(Object.keys(THEMES) as ThemeKey[]).map((key) => (
                                    <label key={key} className="cursor-pointer text-center text-xs text-muted">
                                        <input
                                            type="radio"
                                            name="theme"
                                            value={key}
                                            checked={form.theme === key}
                                            onChange={() => set('theme', key)}
                                            className="peer sr-only"
                                        />
                                        <span className="block overflow-hidden rounded-lg ring-2 ring-transparent peer-checked:ring-brand-500 peer-focus-visible:ring-brand-500">
                                            <CardArt seed={key} palette={THEMES[key].palette} />
                                        </span>
                                        <span className="mt-1 block">{THEMES[key].label}</span>
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                        <div className="mt-4">
                            <Field
                                label="Profile address"
                                value={form.handle}
                                maxLength={30}
                                onChange={(e) => set('handle', e.target.value.toLowerCase())}
                                error={errors.fieldErrors.handle}
                                hint={`${origin}/p/${form.handle || 'your-name'}. Changing it breaks links already written to cards.`}
                            />
                        </div>
                        <label className="mt-4 flex items-start gap-3">
                            <input
                                type="checkbox"
                                checked={form.is_published}
                                onChange={(e) => set('is_published', e.target.checked)}
                                className="mt-1 h-4 w-4"
                            />
                            <span className="text-sm">
                                <span className="block font-medium text-ink">Published</span>
                                <span className="text-muted">Untick to hide the page. Taps then show "not available".</span>
                            </span>
                        </label>
                    </Card>

                    <Button type="submit" loading={saving}>
                        {saved ? 'Save changes' : 'Create my tap card'}
                    </Button>
                </form>

                <aside className="space-y-4 lg:sticky lg:top-22 lg:self-start" aria-label="Preview">
                    <p className="text-sm font-medium text-muted">Live preview</p>
                    <TiltCard>
                        <CardArt
                            seed={form.handle || 'card'}
                            palette={THEMES[form.theme].palette}
                            title={form.display_name || 'Your name'}
                            subtitle={[form.headline, form.company].filter(Boolean).join(' · ')}
                        />
                    </TiltCard>
                    <div className="origin-top scale-95">
                        <ProfileView data={preview} preview />
                    </div>
                </aside>
            </div>

            {saved && daily && (
                <section aria-labelledby="stats-heading" className="space-y-3">
                    <h2 id="stats-heading" className="text-lg font-semibold text-ink">
                        Last 30 days
                    </h2>
                    <p className="text-sm text-muted">
                        All time: {saved.taps_total} profile views and {saved.saves_total} contact saves. Repeat visits from the same phone within half an hour
                        count once.
                    </p>
                    <div className="grid gap-4 md:grid-cols-2">
                        <DailyBars title="Profile views" data={daily.map((d) => ({ date: d.day, clicks: d.taps, orders: d.saves }))} valueKey="clicks" />
                        <DailyBars title="Contact saves" data={daily.map((d) => ({ date: d.day, clicks: d.taps, orders: d.saves }))} valueKey="orders" />
                    </div>
                </section>
            )}
        </div>
    );
}

/** The live link: copy it, download its QR code, or write it to an NFC tag from the phone. */
function ShareCard({ profile }: { profile: OwnCardProfile }) {
    const [copied, setCopied] = useState(false);
    const [nfc, setNfc] = useState<'idle' | 'waiting' | 'done' | 'error'>('idle');
    const [nfcError, setNfcError] = useState<string | null>(null);

    const write = async () => {
        setNfc('waiting');
        setNfcError(null);
        try {
            await writeUrlToTag(profile.public_url);
            setNfc('done');
        } catch (e) {
            setNfc('error');
            setNfcError(
                e instanceof Error && e.name === 'NotAllowedError'
                    ? 'NFC permission was not given.'
                    : 'Could not write the tag. Hold it still against the back of the phone and try again.',
            );
        }
    };

    return (
        <section className="bg-night-sky overflow-hidden rounded-3xl p-6 text-white sm:p-8">
            <div className="flex flex-col gap-6 sm:flex-row sm:items-center">
                <QrCode value={profile.public_url} size={150} label="QR code for your profile" download={`${profile.handle}-qr.png`} />
                <div className="min-w-0 flex-1 space-y-3 [&_a]:text-cyan-200">
                    <p className="text-sm text-slate-300">{profile.is_published ? 'Your profile is live at' : 'Hidden. When published, it will be at'}</p>
                    <p className="break-all text-lg font-semibold">{profile.public_url}</p>
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() => navigator.clipboard?.writeText(profile.public_url).then(() => setCopied(true))}
                            className="min-h-10 rounded-lg bg-white px-4 text-sm font-semibold text-slate-900 hover:bg-cyan-50"
                        >
                            {copied ? 'Copied' : 'Copy link'}
                        </button>
                        <a
                            href={profile.public_url}
                            target="_blank"
                            rel="noopener"
                            className="inline-flex min-h-10 items-center rounded-lg border border-white/25 px-4 text-sm font-semibold !text-white hover:bg-white/10"
                        >
                            Open
                        </a>
                        {canWriteNfc() && (
                            <button
                                type="button"
                                onClick={write}
                                disabled={nfc === 'waiting'}
                                className="bg-signal min-h-10 rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-70"
                            >
                                {nfc === 'waiting' ? 'Hold a tag to your phone…' : 'Write to an NFC tag'}
                            </button>
                        )}
                    </div>
                    {nfc === 'done' && <p className="text-sm text-emerald-300">Written. Tap the tag with any phone to test it.</p>}
                    {nfcError && <p className="text-sm text-rose-300">{nfcError}</p>}
                    {!canWriteNfc() && <p className="text-xs text-slate-400">To write your own NFC tags, open this page in Chrome on an Android phone.</p>}
                </div>
            </div>
        </section>
    );
}
