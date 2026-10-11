<?php

namespace App\Domain\Referrals;

use App\Domain\Audit\AuditLogger;
use App\Models\AffiliateLink;
use App\Models\AffiliateProfile;
use App\Models\ReferralClick;
use App\Support\Privacy\KeyedHash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Handles a visit to /ref/{code}/{link?}: validates the affiliate, records
 * a minimised click, sets the attribution cookie according to the policy
 * and returns where to send the visitor.
 */
class ReferralTracker
{
    public function __construct(
        private readonly ReferralCodeLookup $lookup,
        private readonly DestinationResolver $destinations,
        private readonly BotDetector $bots,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{path: string, cookies: list<Cookie>}
     */
    public function handle(Request $request, string $code, ?string $linkSlug): array
    {
        $profile = $this->lookup->eligible($code);
        $link = $profile && $linkSlug ? $this->activeLink($profile, $linkSlug) : null;
        $destination = $this->destinations->resolve($link, $request->query('to') ? (string) $request->query('to') : null);

        // Unknown or ineligible affiliates, bots and floods are redirected
        // normally but leave no click and no cookie.
        if (! $profile || $this->bots->isBot($request) || $this->overIpLimit($request)) {
            return ['path' => $destination['path'], 'cookies' => []];
        }

        $cookies = [];
        $visitorId = $this->visitorId($request, $cookies);
        $visitorHash = KeyedHash::make('visitor', $visitorId);
        $keepExisting = $this->firstClickHoldsExisting($request);

        $click = ReferralClick::forceCreate([
            'affiliate_profile_id' => $profile->id,
            'affiliate_link_id' => $link?->id,
            'product_id' => $destination['product']?->id,
            'landing_path' => $destination['path'],
            'referrer_host' => $this->referrerHost($request),
            'utm_source' => $this->tag($request->query('utm_source')) ?? $link?->utm_source,
            'utm_medium' => $this->tag($request->query('utm_medium')) ?? $link?->utm_medium,
            'utm_campaign' => $this->tag($request->query('utm_campaign')) ?? $link?->utm_campaign,
            'sub_id' => $this->tag($request->query('sub')),
            'visitor_hash' => $visitorHash,
            'ip_hash' => $this->audit->hashIp($request->ip()),
            'is_unique' => ! $this->seenRecently($visitorHash, $profile),
            'set_attribution' => ! $keepExisting,
            'created_at' => now(),
        ]);

        if (! $keepExisting) {
            $cookies[] = $this->cookie((string) config('affiliate.attribution.cookie'), $click->public_id);
        }

        return ['path' => $destination['path'], 'cookies' => $cookies];
    }

    /**
     * The click currently held in the visitor's attribution cookie, if it is
     * still inside the attribution window.
     */
    public function currentClick(Request $request): ?ReferralClick
    {
        $value = $request->cookie((string) config('affiliate.attribution.cookie'));

        if (! is_string($value) || ! Str::isUlid($value)) {
            return null;
        }

        return ReferralClick::query()
            ->where('public_id', $value)
            ->where('created_at', '>=', now()->subDays((int) config('affiliate.attribution.window_days')))
            ->first();
    }

    private function activeLink(AffiliateProfile $profile, string $slug): ?AffiliateLink
    {
        return AffiliateLink::query()
            ->where('affiliate_profile_id', $profile->id)
            ->where('slug', Str::lower($slug))
            ->where('is_active', true)
            ->first();
    }

    /**
     * Under first-click, a valid earlier click for an eligible affiliate keeps
     * the cookie. Under last-click (the default) the newest click always wins.
     */
    private function firstClickHoldsExisting(Request $request): bool
    {
        if (config('affiliate.attribution.policy') !== 'first_click') {
            return false;
        }

        return (bool) $this->currentClick($request)?->affiliate?->status->isEligibleToEarn();
    }

    /**
     * @param  list<Cookie>  $cookies
     */
    private function visitorId(Request $request, array &$cookies): string
    {
        $name = (string) config('affiliate.attribution.visitor_cookie');
        $existing = $request->cookie($name);

        if (is_string($existing) && preg_match('/^[A-Za-z0-9]{40}$/', $existing)) {
            return $existing;
        }

        $id = Str::random(40);
        $cookies[] = $this->cookie($name, $id);

        return $id;
    }

    private function seenRecently(string $visitorHash, AffiliateProfile $profile): bool
    {
        return ReferralClick::query()
            ->where('visitor_hash', $visitorHash)
            ->where('affiliate_profile_id', $profile->id)
            ->where('created_at', '>=', now()->subHours((int) config('affiliate.attribution.unique_click_hours')))
            ->exists();
    }

    private function overIpLimit(Request $request): bool
    {
        $key = 'ref-click:'.$this->audit->hashIp($request->ip());
        $max = (int) config('affiliate.attribution.clicks_per_minute_per_ip');

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }

    private function referrerHost(Request $request): ?string
    {
        $host = parse_url((string) $request->headers->get('referer'), PHP_URL_HOST);

        return is_string($host) ? Str::limit(Str::lower($host), 250, '') : null;
    }

    private function tag(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = preg_replace('/[^A-Za-z0-9._\-]/', '', $value);

        return $clean === '' ? null : substr($clean, 0, 64);
    }

    private function cookie(string $name, string $value): Cookie
    {
        return cookie(
            $name,
            $value,
            (int) config('affiliate.attribution.window_days') * 24 * 60,
            '/',
            config('session.domain'),
            config('session.secure') ?? request()->isSecure(),
            true,
            false,
            'lax',
        );
    }
}
