import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router';
import { ProfileView } from '@/cards/ProfileView';
import { QrCode } from '@/cards/QrCode';
import type { PublicCardProfile } from '@/cards/types';
import { Illustration, LogoMark, ThemeToggle } from '@/components/brand';
import { Spinner } from '@/components/ui';
import { api, ApiError } from '@/lib/api';

/** What an NFC card opens: the owner's contact page, with Save contact. */
export default function TapProfilePage() {
    const { handle = '' } = useParams();
    const [profile, setProfile] = useState<PublicCardProfile | null>(null);
    const [missing, setMissing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [showQr, setShowQr] = useState(false);
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        let active = true;
        api<{ data: PublicCardProfile }>(`/cards/${encodeURIComponent(handle.toLowerCase())}`)
            .then((r) => active && setProfile(r.data))
            .catch((e) => active && (e instanceof ApiError && e.status === 404 ? setMissing(true) : setError('Could not load this profile. Try again.')));
        return () => {
            active = false;
        };
    }, [handle]);

    const share = async () => {
        if (!profile) return;
        if (navigator.share) {
            await navigator.share({ title: profile.display_name, url: profile.public_url }).catch(() => undefined);
        } else {
            await navigator.clipboard?.writeText(profile.public_url).catch(() => undefined);
            setCopied(true);
        }
    };

    return (
        <div className="bg-night-sky min-h-screen px-4 py-6 sm:py-10">
            <div className="mx-auto max-w-md">
                <div className="mb-4 flex items-center justify-between">
                    <Link to="/" className="inline-flex items-center gap-2 text-sm font-semibold text-white/80 hover:text-white">
                        <LogoMark className="h-6 w-6" />
                        <span className="sr-only">Shop</span>
                    </Link>
                    <span className="[&_button]:text-white/70 [&_button:hover]:bg-white/10">
                        <ThemeToggle />
                    </span>
                </div>

                {!profile && !missing && !error && <Spinner />}
                {error && <p className="rounded-2xl bg-surface p-6 text-center text-sm text-danger">{error}</p>}
                {missing && (
                    <div className="rounded-3xl bg-surface p-8 text-center">
                        <Illustration kind="lost" className="mx-auto h-24 w-24" />
                        <h1 className="mt-3 text-lg font-semibold text-ink">This profile is not available</h1>
                        <p className="mt-1 text-sm text-muted">The link may be mistyped, or the owner has unpublished it.</p>
                        <Link
                            to="/shop"
                            className="mt-5 inline-flex min-h-11 items-center rounded-lg bg-primary px-5 font-semibold text-white hover:bg-primary-hover"
                        >
                            Get your own NFC card
                        </Link>
                    </div>
                )}
                {profile && (
                    <div className="animate-rise space-y-4">
                        <ProfileView
                            data={profile}
                            footer={
                                <div className="mt-6 space-y-2">
                                    <a
                                        href={profile.vcard_url}
                                        className="bg-signal flex min-h-12 w-full items-center justify-center rounded-xl font-semibold text-white shadow-lg shadow-primary/30"
                                    >
                                        Save contact
                                    </a>
                                    <div className="grid grid-cols-2 gap-2">
                                        <button
                                            type="button"
                                            onClick={share}
                                            className="min-h-11 rounded-xl border border-line text-sm font-semibold text-ink hover:bg-canvas"
                                        >
                                            {copied ? 'Link copied' : 'Share'}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setShowQr((v) => !v)}
                                            aria-expanded={showQr}
                                            className="min-h-11 rounded-xl border border-line text-sm font-semibold text-ink hover:bg-canvas"
                                        >
                                            {showQr ? 'Hide QR code' : 'Show QR code'}
                                        </button>
                                    </div>
                                    {showQr && (
                                        <div className="pt-3">
                                            <QrCode value={profile.public_url} label={`QR code for ${profile.display_name}'s profile`} />
                                        </div>
                                    )}
                                </div>
                            }
                        />
                        <Link
                            to={profile.get_a_card_url}
                            reloadDocument={profile.get_a_card_url.startsWith('/ref/')}
                            className="glass flex items-center justify-between rounded-2xl px-5 py-4 text-sm text-ink ring-1 ring-line hover:ring-brand-500"
                        >
                            <span>
                                <span className="block font-semibold">Want a card like this?</span>
                                <span className="text-muted">Smart NFC cards, delivered across Nepal.</span>
                            </span>
                            <span aria-hidden>→</span>
                        </Link>
                    </div>
                )}
            </div>
        </div>
    );
}
