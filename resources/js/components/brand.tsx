import { useRef, type CSSProperties, type PointerEvent, type ReactNode } from 'react';
import { setTheme, useTheme, type ThemeChoice } from '@/lib/theme';

/** The brand mark: a card with contactless waves. Decorative unless labelled. */
export function LogoMark({ className = 'h-8 w-8' }: { className?: string }) {
    return (
        <svg viewBox="0 0 64 64" className={className} aria-hidden>
            <defs>
                <linearGradient id="logo-g" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stopColor="#22d3ee" />
                    <stop offset="0.5" stopColor="#4f46e5" />
                    <stop offset="1" stopColor="#a855f7" />
                </linearGradient>
            </defs>
            <rect x="4" y="10" width="56" height="44" rx="10" fill="url(#logo-g)" />
            <rect x="12" y="20" width="12" height="9" rx="2" fill="#fff" opacity="0.85" />
            <g fill="none" stroke="#fff" strokeWidth="3.5" strokeLinecap="round">
                <path d="M36 25a10 10 0 0 1 0 14" opacity="0.6" />
                <path d="M42 21a16 16 0 0 1 0 22" opacity="0.8" />
                <path d="M48 17a22 22 0 0 1 0 30" />
            </g>
        </svg>
    );
}

export function Logo({ name = import.meta.env.VITE_APP_NAME ?? 'NFC Card Store', compact = false }: { name?: string; compact?: boolean }) {
    return (
        <span className="inline-flex items-center gap-2 font-bold tracking-tight text-ink">
            <LogoMark className="h-7 w-7 shrink-0" />
            <span className={compact ? 'sr-only sm:not-sr-only' : undefined}>{name}</span>
        </span>
    );
}

const THEME_ORDER: ThemeChoice[] = ['system', 'light', 'dark'];
const THEME_LABEL: Record<ThemeChoice, string> = {
    system: 'Theme: follows your device',
    light: 'Theme: light',
    dark: 'Theme: dark',
};

/** Cycles system → light → dark. */
export function ThemeToggle() {
    const { choice } = useTheme();
    const next = THEME_ORDER[(THEME_ORDER.indexOf(choice) + 1) % THEME_ORDER.length] ?? 'system';
    return (
        <button
            type="button"
            onClick={() => setTheme(next)}
            className="inline-flex h-9 w-9 items-center justify-center rounded-lg text-muted hover:bg-canvas hover:text-ink"
            title={THEME_LABEL[choice]}
        >
            <span className="sr-only">{THEME_LABEL[choice]}. Switch theme.</span>
            <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden>
                {choice === 'dark' ? (
                    <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" />
                ) : choice === 'light' ? (
                    <>
                        <circle cx="12" cy="12" r="4" />
                        <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
                    </>
                ) : (
                    <>
                        <rect x="3" y="4" width="18" height="12" rx="2" />
                        <path d="M8 20h8M12 16v4" />
                    </>
                )}
            </svg>
        </button>
    );
}

const reducedMotion = () => typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * A card that tilts toward the pointer with a holographic sheen. Wraps any
 * face (generated art, a product photo, a personalised card).
 */
export function TiltCard({ children, className = '' }: { children: ReactNode; className?: string }) {
    const ref = useRef<HTMLDivElement>(null);
    const move = (e: PointerEvent<HTMLDivElement>) => {
        const el = ref.current;
        if (!el || reducedMotion()) return;
        const r = el.getBoundingClientRect();
        const px = (e.clientX - r.left) / r.width - 0.5;
        const py = (e.clientY - r.top) / r.height - 0.5;
        el.style.setProperty('--ry', `${px * 16}deg`);
        el.style.setProperty('--rx', `${-py * 16}deg`);
        el.style.setProperty('--gx', `${(px + 0.5) * 100}%`);
    };
    const leave = () => {
        ref.current?.style.setProperty('--ry', '0deg');
        ref.current?.style.setProperty('--rx', '0deg');
    };
    return (
        <div className={`[perspective:1000px] ${className}`}>
            <div
                ref={ref}
                onPointerMove={move}
                onPointerLeave={leave}
                style={
                    {
                        transform: 'rotateX(var(--rx, 0deg)) rotateY(var(--ry, 0deg))',
                    } as CSSProperties
                }
                className="relative overflow-hidden rounded-2xl shadow-2xl shadow-primary/25 transition-transform duration-200 ease-out [transform-style:preserve-3d]"
            >
                {children}
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-0 mix-blend-overlay"
                    style={{
                        background: 'radial-gradient(circle at var(--gx, 30%) 20%, rgb(255 255 255 / 0.45), transparent 45%)',
                    }}
                />
                <div aria-hidden className="holo-sheen pointer-events-none absolute inset-0 animate-shine" />
            </div>
        </div>
    );
}

/** Contactless waves, used on card faces. */
function Waves({ x, y, color = '#fff' }: { x: number; y: number; color?: string }) {
    return (
        <g transform={`translate(${x} ${y})`} fill="none" stroke={color} strokeWidth="5" strokeLinecap="round">
            <path d="M0 -14a16 16 0 0 1 0 28" opacity="0.55" />
            <path d="M12 -24a28 28 0 0 1 0 48" opacity="0.75" />
            <path d="M24 -34a40 40 0 0 1 0 68" />
        </g>
    );
}

const PALETTES: [string, string, string][] = [
    ['#0b1026', '#4f46e5', '#22d3ee'],
    ['#140a24', '#a855f7', '#f472b6'],
    ['#03191c', '#0d9488', '#5eead4'],
    ['#1a1203', '#b45309', '#fcd34d'],
    ['#0a0a0a', '#334155', '#e2e8f0'],
    ['#06121f', '#2563eb', '#a855f7'],
];

/** Stable small hash so each product keeps the same artwork. */
export function hashString(s: string): number {
    let h = 2166136261;
    for (let i = 0; i < s.length; i++) h = Math.imul(h ^ s.charCodeAt(i), 16777619);
    return h >>> 0;
}

/**
 * Generated product artwork for items without a photo: a card face in one
 * of a few finishes, picked from the product slug so it never changes.
 */
export function CardArt({
    seed,
    title,
    subtitle,
    className = '',
    palette,
}: {
    seed: string;
    title?: string;
    subtitle?: string;
    className?: string;
    palette?: [string, string, string];
}) {
    const h = hashString(seed);
    const [bg, mid, glow] = palette ?? PALETTES[h % PALETTES.length] ?? PALETTES[0]!;
    const id = `art-${h.toString(36)}`;
    const angle = (h >> 4) % 360;
    return (
        <svg
            viewBox="0 0 400 252"
            className={`block h-auto w-full ${className}`}
            role={title ? 'img' : undefined}
            aria-label={title}
            aria-hidden={title ? undefined : true}
        >
            <defs>
                <linearGradient id={`${id}-bg`} gradientTransform={`rotate(${angle % 90} .5 .5)`}>
                    <stop offset="0" stopColor={bg} />
                    <stop offset="0.65" stopColor={mid} />
                    <stop offset="1" stopColor={glow} />
                </linearGradient>
                <radialGradient id={`${id}-glow`} cx="0.85" cy="0.1" r="0.7">
                    <stop offset="0" stopColor={glow} stopOpacity="0.55" />
                    <stop offset="1" stopColor={glow} stopOpacity="0" />
                </radialGradient>
                <pattern id={`${id}-grid`} width="22" height="22" patternUnits="userSpaceOnUse">
                    <path d="M22 0H0V22" fill="none" stroke="#fff" strokeOpacity="0.06" />
                </pattern>
            </defs>
            <rect width="400" height="252" rx="22" fill={`url(#${id}-bg)`} />
            <rect width="400" height="252" rx="22" fill={`url(#${id}-grid)`} />
            <rect width="400" height="252" rx="22" fill={`url(#${id}-glow)`} />
            <circle cx={60 + (h % 260)} cy={200 - ((h >> 8) % 120)} r={70 + ((h >> 12) % 60)} fill={glow} opacity="0.12" />
            <rect x="32" y="40" width="52" height="40" rx="8" fill="#fde68a" opacity="0.9" />
            <path d="M32 60h52M58 40v40M44 40v40M70 40v40" stroke="#b45309" strokeOpacity="0.35" strokeWidth="2" />
            <Waves x={318} y={66} />
            {title && (
                <text x="32" y="196" fill="#fff" fontSize="24" fontWeight="700" fontFamily="ui-sans-serif, system-ui, sans-serif">
                    {title.length > 24 ? `${title.slice(0, 23)}…` : title}
                </text>
            )}
            {subtitle && (
                <text x="32" y="222" fill="#fff" fillOpacity="0.75" fontSize="15" fontFamily="ui-sans-serif, system-ui, sans-serif">
                    {subtitle.length > 36 ? `${subtitle.slice(0, 35)}…` : subtitle}
                </text>
            )}
        </svg>
    );
}

/** Animated phone-meets-card scene for the storefront hero. */
export function HeroScene() {
    return (
        <div className="relative mx-auto aspect-square w-full max-w-md [perspective:1200px]" aria-hidden>
            <div className="absolute inset-[18%] rounded-full bg-signal opacity-40 blur-3xl" />
            {[0, 0.8, 1.6].map((d) => (
                <span
                    key={d}
                    className="absolute left-1/2 top-1/2 -ml-28 -mt-28 h-56 w-56 animate-ripple rounded-full border-2 border-signal-cyan/60"
                    style={{ animationDelay: `${d}s` }}
                />
            ))}
            <div className="absolute inset-x-[8%] top-[24%] animate-float [transform-style:preserve-3d]">
                <div className="relative overflow-hidden rounded-2xl shadow-2xl shadow-signal-violet/40">
                    <CardArt seed="hero" palette={['#0b1026', '#4f46e5', '#22d3ee']} title="Sita Sharma" subtitle="Founder · Himal Studio" />
                    <div className="holo-sheen absolute inset-0 animate-shine" />
                </div>
            </div>
            <div className="absolute bottom-[4%] right-[6%] w-[30%] rotate-12 rounded-[1.4rem] border-4 border-slate-700 bg-slate-900 p-1.5 shadow-xl">
                <div className="flex aspect-[9/17] flex-col items-center justify-center gap-2 rounded-[1rem] bg-gradient-to-b from-slate-800 to-slate-950">
                    <div className="h-8 w-8 rounded-full bg-signal" />
                    <div className="h-1.5 w-3/5 rounded bg-white/70" />
                    <div className="h-1.5 w-2/5 rounded bg-white/40" />
                    <div className="mt-1 h-4 w-3/5 rounded-md bg-signal-cyan/80" />
                </div>
            </div>
        </div>
    );
}

type IllustrationKind = 'tap' | 'share' | 'earn' | 'empty' | 'lost';

/** Small spot illustrations for steps and empty states. Purely decorative. */
export function Illustration({ kind, className = 'h-24 w-24' }: { kind: IllustrationKind; className?: string }) {
    return (
        <svg viewBox="0 0 120 120" className={className} aria-hidden>
            <defs>
                <linearGradient id={`il-${kind}`} x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stopColor="#22d3ee" />
                    <stop offset="1" stopColor="#a855f7" />
                </linearGradient>
            </defs>
            <circle cx="60" cy="60" r="54" fill="var(--color-brand-50)" />
            {kind === 'tap' && (
                <>
                    <rect x="22" y="44" width="50" height="32" rx="6" fill={`url(#il-${kind})`} />
                    <rect x="62" y="28" width="30" height="56" rx="6" fill="var(--color-surface)" stroke="var(--color-ink)" strokeWidth="3" />
                    <g fill="none" stroke="var(--color-brand-600)" strokeWidth="3" strokeLinecap="round">
                        <path d="M54 52a9 9 0 0 1 0 14" />
                        <path d="M48 46a17 17 0 0 1 0 26" opacity="0.6" />
                    </g>
                </>
            )}
            {kind === 'share' && (
                <>
                    <circle cx="40" cy="60" r="10" fill={`url(#il-${kind})`} />
                    <circle cx="82" cy="38" r="9" fill="var(--color-brand-600)" />
                    <circle cx="82" cy="82" r="9" fill="var(--color-brand-600)" />
                    <path d="M49 55l25-12M49 65l25 12" stroke="var(--color-ink)" strokeWidth="3" strokeLinecap="round" />
                </>
            )}
            {kind === 'earn' && (
                <>
                    <rect x="28" y="70" width="12" height="20" rx="2" fill="var(--color-brand-500)" opacity="0.5" />
                    <rect x="46" y="58" width="12" height="32" rx="2" fill="var(--color-brand-500)" opacity="0.7" />
                    <rect x="64" y="44" width="12" height="46" rx="2" fill={`url(#il-${kind})`} />
                    <circle cx="86" cy="36" r="12" fill="#fbbf24" stroke="#b45309" strokeWidth="2.5" />
                    <text x="86" y="41" textAnchor="middle" fontSize="13" fontWeight="700" fill="#78350f">
                        रु
                    </text>
                </>
            )}
            {kind === 'empty' && (
                <>
                    <rect
                        x="30"
                        y="38"
                        width="60"
                        height="44"
                        rx="8"
                        fill="var(--color-surface)"
                        stroke="var(--color-line)"
                        strokeWidth="3"
                        strokeDasharray="6 5"
                    />
                    <circle cx="60" cy="60" r="8" fill={`url(#il-${kind})`} opacity="0.8" />
                </>
            )}
            {kind === 'lost' && (
                <>
                    <rect x="26" y="40" width="68" height="44" rx="8" fill={`url(#il-${kind})`} transform="rotate(-8 60 62)" />
                    <text x="60" y="70" textAnchor="middle" fontSize="22" fontWeight="800" fill="#fff" transform="rotate(-8 60 62)">
                        404
                    </text>
                </>
            )}
        </svg>
    );
}

/** A friendly empty state: illustration, one line, optional action. */
export function EmptyState({ title, children, action }: { title: string; children?: ReactNode; action?: ReactNode }) {
    return (
        <div className="flex flex-col items-center px-4 py-10 text-center">
            <Illustration kind="empty" className="h-20 w-20" />
            <p className="mt-3 font-medium text-ink">{title}</p>
            {children && <p className="mt-1 max-w-sm text-sm text-muted">{children}</p>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}
