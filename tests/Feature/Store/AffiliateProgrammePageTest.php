<?php

namespace Tests\Feature\Store;

use App\Models\AffiliateTermsVersion;
use App\Models\CommissionRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AffiliateProgrammePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_programme_facts_come_from_live_settings_without_a_rate_when_none_is_set(): void
    {
        config(['affiliate.payouts.minimum_minor' => 150000, 'affiliate.commissions.holding_period_days' => 21]);

        $this->getJson('/api/v1/store/affiliate-programme')->assertOk()
            ->assertJsonPath('data.standard_rate', null)
            ->assertJsonPath('data.terms', null)
            ->assertJsonPath('data.payout_minimum_minor', 150000)
            ->assertJsonPath('data.holding_period_days', 21)
            ->assertJsonPath('data.payout_methods', ['Bank transfer', 'eSewa', 'Khalti']);
    }

    public function test_shows_the_global_rate_and_current_terms_but_not_plan_or_affiliate_rates(): void
    {
        $base = ['kind' => 'percent', 'is_active' => true, 'starts_at' => now()->subDay(), 'version' => 1];
        CommissionRule::query()->forceCreate($base + ['public_id' => (string) str()->ulid(), 'name' => 'Standard', 'scope' => 'global', 'rate_bp' => 1000]);
        AffiliateTermsVersion::query()->forceCreate(['version' => '2026-10', 'title' => 'Affiliate terms', 'body' => "1. Be honest.\n2. No spam.", 'published_at' => now()]);

        $this->getJson('/api/v1/store/affiliate-programme')->assertOk()
            ->assertJsonPath('data.standard_rate.rate_bp', 1000)
            ->assertJsonPath('data.terms.version', '2026-10')
            ->assertJsonPath('data.terms.body', "1. Be honest.\n2. No spam.");
    }
}
