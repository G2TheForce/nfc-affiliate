<?php

namespace Tests\Feature;

use App\Enums\AffiliateStatus;
use App\Models\User;
use Database\Seeders\DevelopmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevelopmentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_affiliate_login_has_an_approved_membership_and_seeding_twice_is_safe(): void
    {
        $this->seed(DevelopmentSeeder::class);
        $this->seed(DevelopmentSeeder::class);

        $member = User::where('email', 'affiliate@dev.invalid')->sole();
        $this->assertSame(AffiliateStatus::Approved, $member->affiliateProfile->status);
        $this->assertNotNull($member->affiliateProfile->referral_code);

        $this->actingAs($member)->getJson('/api/v1/affiliate/earnings/summary')->assertOk();
    }
}
