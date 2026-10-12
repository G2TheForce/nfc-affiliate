<?php

namespace Tests\Feature\Fraud;

use App\Enums\Role;
use App\Models\AffiliatePayoutMethod;
use App\Models\AffiliateProfile;
use App\Models\FraudFlag;
use App\Models\Order;
use App\Notifications\Fraud\FraudFlagsRaised;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Payouts\PayoutTestHelpers;
use Tests\TestCase;

class FraudDetectionTest extends TestCase
{
    use PayoutTestHelpers;
    use RefreshDatabase;

    private function clicks(AffiliateProfile $affiliate, int $count): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['public_id' => (string) Str::ulid(), 'affiliate_profile_id' => $affiliate->id, 'landing_path' => '/', 'is_unique' => true, 'set_attribution' => true, 'created_at' => now()->subDays(2)];
        }
        DB::table('referral_clicks')->insert($rows);
    }

    /** @return list<Order> */
    private function paidOrders(AffiliateProfile $affiliate, int $count, string $source = 'click', int $price = 50000): array
    {
        $product = $this->product(['price_minor' => $price]);
        $orders = [];
        for ($i = 0; $i < $count; $i++) {
            $this->app['auth']->forgetGuards();
            $order = $this->attributedOrder($affiliate, $product, 1);
            $this->pay($order)->assertCreated();
            $orders[] = $order;
        }
        DB::table('order_attributions')->whereIn('order_id', array_map(fn ($o) => $o->id, $orders))->update(['source' => $source]);

        return $orders;
    }

    public function test_conversion_far_above_normal_is_flagged_once_and_reviewers_are_told(): void
    {
        Notification::fake();
        $manager = $this->userWithRole(Role::AffiliateManager);
        $affiliate = $this->approvedAffiliate();
        $this->clicks($affiliate, 6);
        $this->paidOrders($affiliate, 5);

        $this->artisan('affiliate:detect-fraud')->expectsOutputToContain('1 new fraud flag')->assertSuccessful();
        $this->artisan('affiliate:detect-fraud')->expectsOutputToContain('No new fraud flags')->assertSuccessful();

        $flag = FraudFlag::sole();
        $this->assertSame('high_conversion', $flag->signal->value);
        $this->assertSame(5, $flag->details['orders_from_clicks']);
        Notification::assertSentTo($manager, FraudFlagsRaised::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'fraud_flag.raised', 'subject_id' => $flag->public_id]);
    }

    public function test_normal_conversion_and_coupon_orders_are_not_flagged(): void
    {
        $normal = $this->approvedAffiliate();
        $this->clicks($normal, 100);
        $this->paidOrders($normal, 5);
        $coupons = $this->approvedAffiliate();
        $this->paidOrders($coupons, 5, source: 'coupon');

        $this->artisan('affiliate:detect-fraud')->assertSuccessful();

        $this->assertSame(0, FraudFlag::count());
    }

    public function test_high_refund_rate_is_flagged(): void
    {
        $affiliate = $this->approvedAffiliate();
        $this->clicks($affiliate, 100);
        $orders = $this->paidOrders($affiliate, 5);
        foreach (array_slice($orders, 0, 2) as $order) {
            $item = $order->items()->value('public_id');
            $this->refund($order, [['item_id' => $item, 'amount_minor' => 50000]])->assertCreated();
        }

        $this->artisan('affiliate:detect-fraud')->assertSuccessful();

        $flag = FraudFlag::sole();
        $this->assertSame('refund_rate', $flag->signal->value);
        $this->assertEquals(40, $flag->details['refund_percent']);
    }

    public function test_sudden_earnings_and_clicks_without_orders_are_flagged(): void
    {
        config(['affiliate.fraud.clicks_without_orders' => 50]);
        $this->rule(['rate_bp' => 1000]);
        $earner = $this->approvedAffiliate();
        $this->clicks($earner, 100);
        $this->paidOrders($earner, 1, price: 10000000); // NPR 100,000 order, NPR 10,000 commission
        $clicker = $this->approvedAffiliate();
        $this->clicks($clicker, 60);

        $this->artisan('affiliate:detect-fraud')->assertSuccessful();

        $this->assertSame('rapid_earnings', FraudFlag::where('affiliate_profile_id', $earner->id)->value('signal')->value);
        $this->assertSame('clicks_without_orders', FraudFlag::where('affiliate_profile_id', $clicker->id)->value('signal')->value);
    }

    public function test_large_payout_and_payout_after_detail_change_are_flagged_and_shown_to_finance(): void
    {
        Notification::fake();
        config(['affiliate.fraud.large_payout_minor' => 100000]);
        $finance = $this->finance();
        $affiliate = $this->readyAffiliate();
        AffiliatePayoutMethod::where('affiliate_profile_id', $affiliate->id)->update(['created_at' => now()->subDays(30), 'updated_at' => now()->subDay()]);

        $id = $this->requestPayout($affiliate)->assertCreated()->json('data.id');

        $this->assertEqualsCanonicalizing(['large_payout', 'payout_after_detail_change'], FraudFlag::pluck('signal')->map->value->all());
        Notification::assertSentTo($finance, FraudFlagsRaised::class);
        $this->actingAs($finance)->getJson("/api/v1/admin/payouts/{$id}")->assertOk()
            ->assertJsonCount(2, 'data.open_fraud_flags');
    }

    public function test_first_payout_details_are_not_a_change(): void
    {
        $affiliate = $this->readyAffiliate();

        $this->requestPayout($affiliate)->assertCreated();

        $this->assertSame(0, FraudFlag::count());
    }

    public function test_reviewer_dismisses_or_confirms_with_a_note_and_only_fraud_reviewers_see_the_queue(): void
    {
        $affiliate = $this->approvedAffiliate();
        $this->clicks($affiliate, 6);
        $this->paidOrders($affiliate, 5);
        $this->artisan('affiliate:detect-fraud');
        $flag = FraudFlag::sole();
        $manager = $this->userWithRole(Role::AffiliateManager);

        $this->actingAs($this->finance())->getJson('/api/v1/admin/fraud-flags')->assertForbidden();
        $this->actingAs($manager)->getJson('/api/v1/admin/fraud-flags')->assertOk()
            ->assertJsonPath('meta.open', 1)->assertJsonPath('data.0.signal_label', 'Unusually high conversion');

        $this->actingAs($manager)->postJson("/api/v1/admin/fraud-flags/{$flag->public_id}/review", ['outcome' => 'dismissed', 'note' => 'no'])
            ->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->actingAs($manager)->postJson("/api/v1/admin/fraud-flags/{$flag->public_id}/review", ['outcome' => 'dismissed', 'note' => 'Office colleagues bought cards; checked addresses'])
            ->assertOk()->assertJsonPath('data.status', 'dismissed')->assertJsonPath('data.reviewed_by', $manager->name);
        $this->actingAs($manager)->postJson("/api/v1/admin/fraud-flags/{$flag->public_id}/review", ['outcome' => 'confirmed', 'note' => 'Changed my mind here'])
            ->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'fraud_flag.reviewed', 'subject_id' => $flag->public_id]);
    }
}
