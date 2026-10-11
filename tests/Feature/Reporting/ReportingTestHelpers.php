<?php

namespace Tests\Feature\Reporting;

use App\Models\AffiliateProfile;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Commissions\CommissionTestHelpers;

trait ReportingTestHelpers
{
    use CommissionTestHelpers;

    protected function clicks(AffiliateProfile $affiliate, int $count, int $unique, ?string $at = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('referral_clicks')->insert([
                'public_id' => (string) Str::ulid(),
                'affiliate_profile_id' => $affiliate->id,
                'landing_path' => '/shop',
                'is_unique' => $i < $unique,
                'set_attribution' => true,
                'created_at' => $at ?? now(),
            ]);
        }
    }

    /** A guest order through the real checkout, credited by referral code or coupon. */
    protected function order(AffiliateProfile $affiliate, Product $product, int $quantity, ?Coupon $coupon = null): Order
    {
        $this->app['auth']->forgetGuards();
        $overrides = $coupon ? ['coupon_code' => $coupon->code] : ['referral_code' => $affiliate->referral_code];

        return $this->attributedOrder($affiliate, $product, $quantity, $overrides);
    }

    /**
     * Two affiliates with clicks, paid and unpaid orders, a coupon order and
     * a partial refund, all on 2026-09-01 Nepal time.
     *
     * @return array<string, mixed>
     */
    protected function scenario(): array
    {
        $this->travelTo('2026-09-01 04:00:00');
        $this->rule(['rate_bp' => 1000]);

        $a = $this->approvedAffiliate();
        $b = $this->approvedAffiliate();
        $card = $this->product(['name' => 'Metal card', 'price_minor' => 100000]);
        $stand = $this->product(['name' => 'Review stand', 'price_minor' => 50000]);
        $coupon = Coupon::factory()->create(['code' => 'ASHA10', 'affiliate_profile_id' => $a->id, 'discount_value' => 1000]);

        $this->clicks($a, 3, 2);
        $this->clicks($b, 1, 1);

        $paid = $this->order($a, $card, 2);                 // NPR 2,000, commission 200
        $this->pay($paid)->assertCreated();
        $unpaid = $this->order($a, $stand, 1);              // placed only
        $couponOrder = $this->order($a, $stand, 2, $coupon); // NPR 1,000 - 10% = 900, commission 90
        $this->pay($couponOrder)->assertCreated();
        $other = $this->order($b, $card, 1);                // affiliate B, NPR 1,000, commission 100
        $this->pay($other)->assertCreated();

        // Half of the first order's card line comes back.
        $this->refund($paid, [['item_id' => $paid->items()->value('public_id'), 'amount_minor' => 100000]])->assertCreated();

        return compact('a', 'b', 'card', 'stand', 'coupon', 'paid', 'unpaid', 'couponOrder', 'other');
    }
}
