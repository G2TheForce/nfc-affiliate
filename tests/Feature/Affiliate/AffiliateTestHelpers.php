<?php

namespace Tests\Feature\Affiliate;

use App\Models\AffiliateTermsVersion;

trait AffiliateTestHelpers
{
    protected function publishTerms(string $version = '2026-10'): AffiliateTermsVersion
    {
        return AffiliateTermsVersion::create([
            'version' => $version,
            'title' => 'Affiliate terms '.$version,
            'body' => str_repeat('Test terms text. ', 10),
            'published_at' => now()->subMinute(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function applicationPayload(array $overrides = []): array
    {
        return array_merge([
            'display_name' => 'Kathmandu Cards Blog',
            'country_code' => 'np',
            'city' => 'Kathmandu',
            'promotion_channels' => ['website', 'facebook'],
            'website_urls' => ['https://www.example-blog.com/nfc'],
            'audience_description' => 'Small business owners in the valley.',
            'terms_version' => '2026-10',
            'accept_terms' => true,
        ], $overrides);
    }
}
