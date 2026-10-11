<?php

namespace Tests\Feature\Referrals;

use App\Models\ReferralClick;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneReferralDataTest extends TestCase
{
    use ReferralTestHelpers;
    use RefreshDatabase;

    public function test_old_click_metadata_is_cleared_and_expired_clicks_are_deleted(): void
    {
        $affiliate = $this->affiliate();
        $this->travel(-400)->days();
        $this->visit('/ref/'.$affiliate->referral_code);
        $this->travelBack();
        $this->travel(-100)->days();
        $this->visit('/ref/'.$affiliate->referral_code);
        $this->travelBack();
        $this->visit('/ref/'.$affiliate->referral_code);

        $this->artisan('affiliate:prune-referral-data')->assertSuccessful();

        $clicks = ReferralClick::orderBy('created_at')->get();
        $this->assertCount(2, $clicks);
        $this->assertNull($clicks[0]->visitor_hash);
        $this->assertNull($clicks[0]->ip_hash);
        $this->assertNotNull($clicks[1]->visitor_hash);
    }
}
