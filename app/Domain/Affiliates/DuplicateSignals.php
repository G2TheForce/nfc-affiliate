<?php

namespace App\Domain\Affiliates;

use App\Models\AffiliateProfile;

/**
 * Finds other affiliate profiles that share a phone number or website with
 * this one. These are prompts for a reviewer to look closer, not proof of
 * anything: families share phones and agencies share websites.
 */
class DuplicateSignals
{
    /**
     * @return list<array{id: string, display_name: string, status: string, matched_on: list<string>}>
     */
    public function for(AffiliateProfile $profile): array
    {
        $matches = [];

        $phone = $profile->user->phone;
        if ($phone) {
            AffiliateProfile::query()
                ->whereKeyNot($profile->getKey())
                ->whereHas('user', fn ($q) => $q->where('phone', $phone))
                ->limit(10)
                ->get()
                ->each(function (AffiliateProfile $other) use (&$matches) {
                    $matches[$other->public_id] ??= $this->row($other);
                    $matches[$other->public_id]['matched_on'][] = 'phone';
                });
        }

        $hosts = collect($profile->website_urls ?? [])
            ->map(fn ($url) => $this->host($url))
            ->filter()
            ->unique();

        if ($hosts->isNotEmpty()) {
            // Website lists are short JSON arrays; filter in PHP after a
            // coarse LIKE so this stays portable across MySQL and SQLite.
            AffiliateProfile::query()
                ->whereKeyNot($profile->getKey())
                ->whereNotNull('website_urls')
                ->where(function ($q) use ($hosts) {
                    foreach ($hosts as $host) {
                        $q->orWhere('website_urls', 'like', '%'.addcslashes($host, '%_\\').'%');
                    }
                })
                ->limit(25)
                ->get()
                ->filter(fn (AffiliateProfile $other) => collect($other->website_urls)
                    ->map(fn ($url) => $this->host($url))
                    ->intersect($hosts)
                    ->isNotEmpty())
                ->each(function (AffiliateProfile $other) use (&$matches) {
                    $matches[$other->public_id] ??= $this->row($other);
                    $matches[$other->public_id]['matched_on'][] = 'website';
                });
        }

        return array_values($matches);
    }

    /**
     * @return array{id: string, display_name: string, status: string, matched_on: list<string>}
     */
    private function row(AffiliateProfile $profile): array
    {
        return [
            'id' => $profile->public_id,
            'display_name' => $profile->display_name,
            'status' => $profile->status->value,
            'matched_on' => [],
        ];
    }

    private function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host ? preg_replace('/^www\./', '', strtolower($host)) : null;
    }
}
