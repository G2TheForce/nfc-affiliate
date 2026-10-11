<?php

namespace Tests\Feature\Payouts;

use App\Domain\Commissions\CommissionMaturation;
use App\Models\AffiliateProfile;
use App\Models\CommissionRule;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commissions\CommissionTestHelpers;
use Tests\Feature\Commissions\RefundDatabaseHelpers;

trait PayoutTestHelpers
{
    use CommissionTestHelpers;
    use RefundDatabaseHelpers;

    /**
     * Earns commission for the affiliate through a real paid order (10%
     * global rule) and matures it into the available balance.
     */
    protected function earn(AffiliateProfile $affiliate, int $price, int $quantity = 1): void
    {
        if (! CommissionRule::query()->where('scope', 'global')->exists()) {
            $this->rule(['rate_bp' => 1000]);
        }
        // Checkout as a guest: an order placed while signed in as the
        // affiliate would be a self-referral and earn nothing.
        $this->app['auth']->forgetGuards();
        $order = $this->attributedOrder($affiliate, $this->product(['price_minor' => $price]), $quantity);
        $this->pay($order)->assertCreated();
        $this->travelTo(now()->addDays(15));
        app(CommissionMaturation::class)->run();
    }

    protected function saveBankMethod(AffiliateProfile $affiliate, array $overrides = []): TestResponse
    {
        return $this->actingAs($affiliate->user)->putJson('/api/v1/affiliate/payout-method', array_merge([
            'type' => 'bank_transfer',
            'account_name' => 'Sita Sharma',
            'bank_name' => 'Nabil Bank',
            'branch' => 'Thamel',
            'account_number' => '01234567891234',
            'password' => 'Password1234',
        ], $overrides));
    }

    /** An affiliate with NPR 2,000 available and bank details saved. */
    protected function readyAffiliate(int $price = 2000000): AffiliateProfile
    {
        $affiliate = $this->approvedAffiliate();
        $this->earn($affiliate, $price);
        $this->saveBankMethod($affiliate)->assertOk();

        return $affiliate;
    }

    protected function requestPayout(AffiliateProfile $affiliate): TestResponse
    {
        return $this->actingAs($affiliate->user)->postJson('/api/v1/affiliate/payouts');
    }

    protected function approve(Payout|string $payout, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->finance())->postJson('/api/v1/admin/payouts/'.$this->payoutId($payout).'/approve', ['note' => 'Balance checked']);
    }

    protected function markPaid(Payout|string $payout, string $reference = 'NABIL-TXN-0001', ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->finance())->postJson('/api/v1/admin/payouts/'.$this->payoutId($payout).'/mark-paid', ['reference' => $reference]);
    }

    private function payoutId(Payout|string $payout): string
    {
        return $payout instanceof Payout ? $payout->public_id : $payout;
    }
}
