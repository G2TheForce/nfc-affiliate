<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\Gateways\EsewaGateway;
use App\Domain\Payments\ProviderPaymentService;
use App\Enums\PaymentAttemptStatus;
use App\Enums\Role;
use App\Models\Commission;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentAttempt;
use App\Models\WebhookEvent;
use App\Notifications\Finance\PaymentNeedsReview;
use App\Notifications\Store\OrderPaymentReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commissions\CommissionTestHelpers;
use Tests\TestCase;

/**
 * eSewa and Khalti against recorded responses in the shapes their
 * documentation gives. No request leaves the test.
 */
class OnlinePaymentTest extends TestCase
{
    use CommissionTestHelpers;
    use RefreshDatabase;

    // eSewa's published UAT credentials.
    private const ESEWA_KEY = '8gBm/:&EnhH.1/q';

    private const ESEWA_STATUS = 'https://rc.esewa.com.np/api/epay/transaction/status/*';

    private const KHALTI = 'https://dev.khalti.com/api/v2/epayment/';

    /** What eSewa's status API answers in the current test. */
    private ?\Closure $esewaReply = null;

    private bool $ruleCreated = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([self::ESEWA_STATUS => fn () => ($this->esewaReply ?? fn () => throw new \LogicException('No eSewa reply set'))()]);
        config([
            'app.url' => 'https://shop.test',
            'payments.providers.esewa.enabled' => true,
            'payments.providers.esewa.product_code' => 'EPAYTEST',
            'payments.providers.esewa.secret_key' => self::ESEWA_KEY,
            'payments.providers.khalti.enabled' => true,
            'payments.providers.khalti.secret_key' => 'test-secret-key',
        ]);
    }

    /** @return array{0: Order, 1: string} */
    private function guestOrder(int $quantity = 1): array
    {
        if (! $this->ruleCreated) {
            $this->rule(['rate_bp' => 1000]);
            $this->ruleCreated = true;
        }
        $affiliate = $this->approvedAffiliate();
        $product = $this->product(['price_minor' => 150000]);
        $this->app['auth']->forgetGuards();
        $response = $this->placeOrder($product, null, ['items' => [['product_id' => $product->public_id, 'quantity' => $quantity]], 'referral_code' => $affiliate->referral_code])->assertCreated();
        $this->flushHeaders();

        return [Order::query()->latest('id')->firstOrFail(), $response->json('meta.access_token')];
    }

    private function startPayment(Order $order, string $token, string $provider = 'esewa'): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson("/api/v1/store/orders/{$order->public_id}/payments", ['provider' => $provider, 'access_token' => $token]);
    }

    /** The base64 data eSewa appends to the success URL, signed as eSewa signs it. */
    private function esewaCallback(PaymentAttempt $attempt, string $status = 'COMPLETE', ?string $amount = null, ?string $key = null): string
    {
        $data = [
            'transaction_code' => '000AWEO',
            'status' => $status,
            'total_amount' => $amount ?? EsewaGateway::amount($attempt->amount_minor),
            'transaction_uuid' => $attempt->provider_reference,
            'product_code' => 'EPAYTEST',
            'signed_field_names' => 'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names',
        ];
        $message = collect(explode(',', $data['signed_field_names']))->map(fn ($f) => "$f={$data[$f]}")->implode(',');
        $data['signature'] = base64_encode(hash_hmac('sha256', $message, $key ?? self::ESEWA_KEY, true));

        return base64_encode(json_encode($data));
    }

    private function esewaStatus(string $status, PaymentAttempt $attempt, ?string $amount = null): void
    {
        $body = [
            'product_code' => 'EPAYTEST',
            'transaction_uuid' => $attempt->provider_reference,
            'total_amount' => $amount ?? EsewaGateway::amount($attempt->amount_minor),
            'status' => $status,
            'ref_id' => $status === 'COMPLETE' ? '0001TS9' : null,
        ];
        $this->esewaReply = fn () => Http::response($body);
    }

    public function test_esewa_signature_matches_the_published_example(): void
    {
        // The sample form in eSewa's ePay v2 documentation.
        $example = ['total_amount' => '110', 'transaction_uuid' => '241028', 'product_code' => 'EPAYTEST',
            'signed_field_names' => 'total_amount,transaction_uuid,product_code', 'signature' => 'i94zsd3oXF6ZsSr/kGqT4sSzYQzjj1W/waxjWyRwaME='];
        $gateway = app(EsewaGateway::class);

        $this->assertTrue($gateway->readCallback(['data' => base64_encode(json_encode($example))])->signatureValid);
        $this->assertFalse($gateway->readCallback(['data' => base64_encode(json_encode(['total_amount' => '1'] + $example))])->signatureValid);
        $this->assertFalse($gateway->readCallback(['data' => 'not-base64!'])->signatureValid);
        $this->assertSame(150050, EsewaGateway::toMinor('1,500.5'));
        $this->assertSame('1500', EsewaGateway::amount(150000));
    }

    public function test_esewa_payment_is_confirmed_by_the_status_api_once(): void
    {
        Notification::fake();
        [$order, $token] = $this->guestOrder();

        $this->getJson('/api/v1/store/payment-methods')->assertOk()->assertJsonPath('data.0.key', 'esewa')->assertJsonPath('data.1.key', 'khalti');

        $start = $this->startPayment($order, $token)->assertCreated();
        $fields = $start->json('data.redirect.fields');
        $this->assertSame('form', $start->json('data.redirect.type'));
        $this->assertSame('https://rc-epay.esewa.com.np/api/epay/main/v2/form', $start->json('data.redirect.url'));
        $this->assertSame('1500', $fields['total_amount']);
        $this->assertSame('https://shop.test/payments/esewa/return', $fields['success_url']);
        // The form's signature verifies with eSewa's algorithm.
        $this->assertSame(base64_encode(hash_hmac('sha256', "total_amount=1500,transaction_uuid={$fields['transaction_uuid']},product_code=EPAYTEST", self::ESEWA_KEY, true)), $fields['signature']);

        $attempt = PaymentAttempt::query()->sole();
        $this->esewaStatus('COMPLETE', $attempt);
        $callback = $this->esewaCallback($attempt);

        $this->get('/payments/esewa/return?data='.urlencode($callback))
            ->assertRedirect("/order-confirmed?order={$order->public_id}&payment=paid");
        // The same callback again (a reload) changes nothing.
        $this->get('/payments/esewa/return?data='.urlencode($callback))->assertRedirect();

        $order->refresh();
        $this->assertSame('paid', $order->status->value);
        $payment = OrderPayment::query()->sole();
        $this->assertSame(['esewa', 'esewa', '0001TS9', 150000, $attempt->id], [$payment->provider, $payment->method->value, $payment->reference, $payment->amount_minor, $payment->payment_attempt_id]);
        $this->assertNull($payment->confirmed_by);
        $this->assertSame(1, Commission::query()->count());
        $this->assertSame(15000, (int) Commission::query()->value('amount_minor'));
        $this->assertSame(PaymentAttemptStatus::Completed, $attempt->refresh()->status);

        $event = WebhookEvent::query()->sole();
        $this->assertSame(['verified_paid', true, 2], [$event->outcome, $event->signature_valid, $event->times_received]);
        $this->assertArrayNotHasKey('signature', $event->payload);
        Http::assertSentCount(1);
        Notification::assertSentOnDemand(OrderPaymentReceived::class);

        // The customer's page reads the result with the token; nobody else can.
        $this->getJson("/api/v1/store/orders/{$order->public_id}/payment-status?access_token={$token}")
            ->assertOk()->assertJsonPath('data.paid', true)->assertJsonPath('data.attempt.outcome', 'paid');
        $this->getJson("/api/v1/store/orders/{$order->public_id}/payment-status?access_token=wrong")->assertNotFound();
        $this->startPayment($order, $token)->assertUnprocessable()->assertJsonValidationErrors('order');
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.payment_confirmed']);
    }

    public function test_a_signed_redirect_alone_does_not_mark_the_order_paid(): void
    {
        [$order, $token] = $this->guestOrder();
        $this->startPayment($order, $token)->assertCreated();
        $attempt = PaymentAttempt::query()->sole();

        // The redirect says COMPLETE, but eSewa's server still says PENDING.
        $this->esewaStatus('PENDING', $attempt);
        $this->get('/payments/esewa/return?data='.urlencode($this->esewaCallback($attempt)))
            ->assertRedirect("/order-confirmed?order={$order->public_id}&payment=pending");
        $this->assertSame('pending_payment', $order->refresh()->status->value);
        $this->assertSame(PaymentAttemptStatus::Pending, $attempt->refresh()->status);
        $this->assertSame(0, OrderPayment::query()->count());

        // Later the scheduled re-check sees it complete.
        $this->travel(20)->minutes();
        $this->esewaStatus('COMPLETE', $attempt);
        $this->artisan('payments:recheck')->expectsOutputToContain('1 now paid')->assertSuccessful();
        $this->assertSame('paid', $order->refresh()->status->value);
        $this->artisan('payments:recheck')->expectsOutputToContain('Checked 0')->assertSuccessful();
        $this->assertSame(1, OrderPayment::query()->count());
    }

    public function test_a_forged_callback_is_rejected_without_trusting_it(): void
    {
        [$order, $token] = $this->guestOrder();
        $this->startPayment($order, $token)->assertCreated();
        $attempt = PaymentAttempt::query()->sole();
        Http::fake();

        $this->get('/payments/esewa/return?data='.urlencode($this->esewaCallback($attempt, key: 'not-the-secret')))
            ->assertRedirect('/order-confirmed?payment=unknown');

        Http::assertNothingSent();
        $this->assertSame('pending_payment', $order->refresh()->status->value);
        $this->assertSame(['rejected', false], [WebhookEvent::query()->value('outcome'), (bool) WebhookEvent::query()->value('signature_valid')]);
    }

    public function test_a_wrong_amount_goes_to_finance_review(): void
    {
        Notification::fake();
        $finance = $this->userWithRole(Role::FinanceManager);
        [$order, $token] = $this->guestOrder();
        $this->startPayment($order, $token)->assertCreated();
        $attempt = PaymentAttempt::query()->sole();

        $this->esewaStatus('COMPLETE', $attempt, '15');
        app(ProviderPaymentService::class)->refresh($attempt);
        $this->assertSame(PaymentAttemptStatus::NeedsReview, $attempt->refresh()->status);
        $this->assertStringContainsString('15.00 paid', $attempt->review_reason);
        $this->assertSame('pending_payment', $order->refresh()->status->value);
        Notification::assertSentTo($finance, PaymentNeedsReview::class);
        // Not re-checked automatically once it needs a person.
        $this->assertNull($attempt->next_check_at);
    }

    public function test_an_online_payment_after_a_manual_one_is_flagged_not_applied(): void
    {
        Notification::fake();
        [$order, $token] = $this->guestOrder();
        $this->startPayment($order, $token)->assertCreated();
        $attempt = PaymentAttempt::query()->sole();
        $this->pay($order)->assertCreated();

        $this->esewaStatus('COMPLETE', $attempt);
        app(ProviderPaymentService::class)->refresh($attempt);

        $this->assertSame(PaymentAttemptStatus::NeedsReview, $attempt->refresh()->status);
        $this->assertStringContainsString('already paid another way', $attempt->review_reason);
        $this->assertSame(1, OrderPayment::query()->count());
        $this->assertSame(1, Commission::query()->count());

        // Finance lists it, then closes the review after refunding.
        $finance = $this->finance();
        $this->actingAs($finance)->getJson('/api/v1/admin/payment-attempts')->assertOk()->assertJsonPath('data.0.id', $attempt->public_id);
        $this->actingAs($finance)->postJson("/api/v1/admin/payment-attempts/{$attempt->public_id}/resolve", ['note' => 'Refunded in the eSewa merchant portal, ref R-1'])->assertOk();
        $this->actingAs($finance)->getJson('/api/v1/admin/payment-attempts')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->userWithRole(Role::Support))->postJson("/api/v1/admin/payment-attempts/{$attempt->public_id}/recheck")->assertForbidden();
    }

    public function test_an_unreachable_provider_leaves_the_attempt_to_be_checked_again(): void
    {
        [$order, $token] = $this->guestOrder();
        $this->startPayment($order, $token)->assertCreated();
        $attempt = PaymentAttempt::query()->sole();
        $this->esewaReply = fn () => throw new ConnectionException('timed out');

        $this->get('/payments/esewa/return?data='.urlencode($this->esewaCallback($attempt)))
            ->assertRedirect("/order-confirmed?order={$order->public_id}&payment=pending");

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Initiated, $attempt->status);
        $this->assertSame(1, $attempt->check_count);
        $this->assertTrue($attempt->next_check_at->isFuture());
        $this->assertSame('pending_payment', $order->refresh()->status->value);
    }

    public function test_a_late_failure_does_not_undo_a_payment_and_abandoned_attempts_expire(): void
    {
        [$order, $token] = $this->guestOrder();
        $this->startPayment($order, $token)->assertCreated();
        $attempt = PaymentAttempt::query()->sole();
        $this->esewaStatus('COMPLETE', $attempt);
        app(ProviderPaymentService::class)->refresh($attempt);

        // A failure redirect arriving afterwards (out of order).
        $this->get("/payments/esewa/failure/{$attempt->public_id}")->assertRedirect("/order-confirmed?order={$order->public_id}&payment=paid");
        $this->assertSame(PaymentAttemptStatus::Completed, $attempt->refresh()->status);

        // A different order whose customer never came back.
        [$other, $otherToken] = $this->guestOrder();
        $this->startPayment($other, $otherToken)->assertCreated();
        $abandoned = PaymentAttempt::query()->latest('id')->first();
        $this->esewaReply = fn () => Http::response(['status' => 'NOT_FOUND']);
        $this->travel(49)->hours();
        $this->artisan('payments:recheck')->assertSuccessful();
        $this->assertSame(PaymentAttemptStatus::Failed, $abandoned->refresh()->status);
        $this->assertSame('pending_payment', $other->refresh()->status->value);
    }

    public function test_khalti_payment_is_confirmed_by_lookup(): void
    {
        [$order, $token] = $this->guestOrder();
        Http::fake([
            self::KHALTI.'initiate/' => Http::response(['pidx' => 'bZQLD9wRVWo4CdESSfuSsB', 'payment_url' => 'https://test-pay.khalti.com/?pidx=bZQLD9wRVWo4CdESSfuSsB', 'expires_at' => now()->addHour()->toIso8601String(), 'expires_in' => 3600]),
            self::KHALTI.'lookup/' => Http::response(['pidx' => 'bZQLD9wRVWo4CdESSfuSsB', 'total_amount' => 150000, 'status' => 'Completed', 'transaction_id' => 'GFq9PFS7b2iYvL8Lir9oXe', 'fee' => 0, 'refunded' => false]),
        ]);

        $this->startPayment($order, $token, 'khalti')->assertCreated()
            ->assertJsonPath('data.redirect.type', 'redirect')
            ->assertJsonPath('data.redirect.url', 'https://test-pay.khalti.com/?pidx=bZQLD9wRVWo4CdESSfuSsB');
        Http::assertSent(fn ($r) => $r->url() === self::KHALTI.'initiate/'
            && $r->hasHeader('Authorization', 'Key test-secret-key')
            && $r['amount'] === 150000
            && $r['return_url'] === 'https://shop.test/payments/khalti/return');

        $this->get('/payments/khalti/return?pidx=bZQLD9wRVWo4CdESSfuSsB&status=Completed&transaction_id=GFq9PFS7b2iYvL8Lir9oXe&amount=150000&purchase_order_id=x&mobile=98XXXXX904')
            ->assertRedirect("/order-confirmed?order={$order->public_id}&payment=paid");

        $this->assertSame('paid', $order->refresh()->status->value);
        $this->assertSame(['khalti', 'GFq9PFS7b2iYvL8Lir9oXe'], [OrderPayment::query()->value('provider'), OrderPayment::query()->value('reference')]);
        // The payer's mobile number is not kept.
        $this->assertArrayNotHasKey('mobile', WebhookEvent::query()->sole()->payload);
    }

    public function test_khalti_cancelled_payment_and_minimum(): void
    {
        [$order, $token] = $this->guestOrder();
        Http::fake([
            self::KHALTI.'initiate/' => Http::response(['pidx' => 'PIDX1', 'payment_url' => 'https://test-pay.khalti.com/?pidx=PIDX1', 'expires_at' => null]),
            self::KHALTI.'lookup/' => Http::response(['pidx' => 'PIDX1', 'total_amount' => 150000, 'status' => 'User canceled', 'transaction_id' => null, 'fee' => 0, 'refunded' => false], 400),
        ]);
        $this->startPayment($order, $token, 'khalti')->assertCreated();

        $this->get('/payments/khalti/return?pidx=PIDX1&status=User%20canceled')
            ->assertRedirect("/order-confirmed?order={$order->public_id}&payment=failed");
        $this->assertSame(PaymentAttemptStatus::Failed, PaymentAttempt::query()->sole()->status);
        $this->assertSame('pending_payment', $order->refresh()->status->value);

        config(['payments.providers.khalti.minimum_minor' => 200000]);
        $this->startPayment($order, $token, 'khalti')->assertUnprocessable()->assertJsonValidationErrors('provider');
    }

    public function test_sandbox_mode_is_refused_in_production_and_tokens_are_required(): void
    {
        [$order, $token] = $this->guestOrder();
        $this->startPayment($order, '')->assertNotFound();
        $this->startPayment($order, 'guess')->assertNotFound();

        $this->app['env'] = 'production';
        $this->getJson('/api/v1/store/payment-methods')->assertOk()->assertJsonCount(0, 'data');
        $this->startPayment($order, $token)->assertUnprocessable()->assertJsonValidationErrors('provider');

        config(['payments.providers.esewa.mode' => 'production']);
        $this->getJson('/api/v1/store/payment-methods')->assertOk()->assertJsonPath('data.0.key', 'esewa');
    }

    public function test_provider_refund_on_a_paid_attempt_is_flagged_for_recording(): void
    {
        Notification::fake();
        [$order, $token] = $this->guestOrder();
        $this->startPayment($order, $token)->assertCreated();
        $attempt = PaymentAttempt::query()->sole();
        $this->esewaStatus('COMPLETE', $attempt);
        app(ProviderPaymentService::class)->refresh($attempt);

        $this->esewaStatus('FULL_REFUND', $attempt);
        $this->actingAs($this->finance())->postJson("/api/v1/admin/payment-attempts/{$attempt->public_id}/recheck")
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.provider_status', 'FULL_REFUND');
        $this->assertStringContainsString('Record the refund', $attempt->refresh()->review_reason);
        // Nothing was reversed automatically: the refund is recorded by staff with its lines.
        $this->assertSame('paid', $order->refresh()->status->value);
    }

    public function test_two_checks_racing_on_stale_copies_record_one_payment(): void
    {
        [$order, $token] = $this->guestOrder();
        $this->startPayment($order, $token)->assertCreated();
        // The callback and the scheduler both loaded the attempt before either applied it.
        $fromCallback = PaymentAttempt::query()->sole();
        $fromScheduler = PaymentAttempt::query()->sole();
        $this->esewaStatus('COMPLETE', $fromCallback);

        app(ProviderPaymentService::class)->refresh($fromCallback);
        app(ProviderPaymentService::class)->refresh($fromScheduler);

        $this->assertSame(1, OrderPayment::query()->count());
        $this->assertSame(1, Commission::query()->count());
        $this->assertSame(PaymentAttemptStatus::Completed, $fromCallback->refresh()->status);
    }
}
