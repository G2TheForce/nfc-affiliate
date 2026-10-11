<?php

namespace Tests\Feature\Payments;

use App\Enums\CommissionStatus;
use App\Enums\Role;
use App\Models\Commission;
use App\Models\Order;
use App\Notifications\Store\OrderShipped;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Commissions\CommissionTestHelpers;
use Tests\TestCase;

class OrderOperationsTest extends TestCase
{
    use CommissionTestHelpers;
    use RefreshDatabase;

    private function paidOrder(int $quantity = 2): Order
    {
        $this->rule(['rate_bp' => 1000]);
        $affiliate = $this->approvedAffiliate();
        $product = $this->product(['price_minor' => 100000]);
        $order = $this->attributedOrder($affiliate, $product, $quantity);
        $this->pay($order)->assertCreated();

        return $order->refresh();
    }

    public function test_a_lost_dispute_reverses_commission_through_a_refund(): void
    {
        $order = $this->paidOrder();
        $finance = $this->finance();
        $commission = Commission::query()->sole();
        $this->assertSame(20000, $commission->amount_minor);

        $this->actingAs($finance)->postJson("/api/v1/admin/orders/{$order->public_id}/disputes", [
            'reference' => 'CB-2026-001', 'amount_minor' => 200000, 'reason' => 'Customer says the card was not received',
        ])->assertCreated()->assertJsonPath('data.disputes.0.status', 'open')->assertJsonPath('data.disputes.0.held_commissions', 1);
        $this->assertTrue($commission->refresh()->isHeld());

        // Only one open dispute per order.
        $this->actingAs($finance)->postJson("/api/v1/admin/orders/{$order->public_id}/disputes", [
            'reference' => 'CB-2026-002', 'amount_minor' => 1000, 'reason' => 'Second claim for the same order',
        ])->assertUnprocessable()->assertJsonValidationErrors('reference');

        $dispute = $order->disputes()->sole();
        $this->actingAs($finance)->postJson("/api/v1/admin/disputes/{$dispute->public_id}/resolve", ['outcome' => 'lost', 'note' => 'Bank ruled for the customer'])
            ->assertOk()->assertJsonPath('data.status', 'refunded')->assertJsonPath('data.disputes.0.status', 'lost');

        $commission->refresh();
        $this->assertSame(CommissionStatus::Reversed, $commission->status);
        $this->assertSame(20000, $commission->reversed_minor);
        $this->assertSame('DISPUTE-CB-2026-001', $order->refunds()->sole()->reference);
        $this->assertSame(200000, (int) $order->refunds()->sole()->amount_minor);

        $this->actingAs($finance)->postJson("/api/v1/admin/disputes/{$dispute->public_id}/resolve", ['outcome' => 'won', 'note' => 'Trying to change it'])
            ->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.dispute_resolved']);
    }

    public function test_a_won_dispute_releases_the_hold_and_a_partial_loss_refunds_part(): void
    {
        $order = $this->paidOrder();
        $finance = $this->finance();
        $commission = Commission::query()->sole();

        $this->actingAs($finance)->postJson("/api/v1/admin/orders/{$order->public_id}/disputes", ['reference' => 'CB-1', 'amount_minor' => 200000, 'reason' => 'Unrecognised payment'])->assertCreated();
        $this->actingAs($finance)->postJson('/api/v1/admin/disputes/'.$order->disputes()->sole()->public_id.'/resolve', ['outcome' => 'won', 'note' => 'Delivery proof accepted'])->assertOk();
        $this->assertFalse($commission->refresh()->isHeld());
        $this->assertSame(CommissionStatus::Pending, $commission->status);

        // A later partial chargeback: half the order.
        $this->actingAs($finance)->postJson("/api/v1/admin/orders/{$order->public_id}/disputes", ['reference' => 'CB-2', 'amount_minor' => 100000, 'reason' => 'One card damaged'])->assertCreated();
        $this->actingAs($finance)->postJson('/api/v1/admin/disputes/'.$order->disputes()->where('reference', 'CB-2')->sole()->public_id.'/resolve', ['outcome' => 'lost', 'note' => 'Partial chargeback'])
            ->assertOk()->assertJsonPath('data.status', 'partially_refunded');
        $this->assertSame(10000, $commission->refresh()->reversed_minor);
        $this->assertFalse($commission->isHeld());
    }

    public function test_dispute_rules_and_access(): void
    {
        $order = $this->paidOrder();
        $this->actingAs($this->finance())->postJson("/api/v1/admin/orders/{$order->public_id}/disputes", ['reference' => 'CB-9', 'amount_minor' => 999999, 'reason' => 'Too much'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount_minor');
        $this->actingAs($this->userWithRole(Role::Support))->postJson("/api/v1/admin/orders/{$order->public_id}/disputes", ['reference' => 'CB-9', 'amount_minor' => 100, 'reason' => 'Not allowed'])
            ->assertForbidden();

        $unpaid = $this->attributedOrder($this->approvedAffiliate(), $this->product(), 1);
        $this->actingAs($this->finance())->postJson("/api/v1/admin/orders/{$unpaid->public_id}/disputes", ['reference' => 'CB-10', 'amount_minor' => 100, 'reason' => 'Unpaid order'])
            ->assertUnprocessable()->assertJsonValidationErrors('order');
    }

    public function test_fulfilment_moves_forward_and_tells_the_customer_when_shipped(): void
    {
        Notification::fake();
        $order = $this->paidOrder(1);
        $manager = $this->userWithRole(Role::SuperAdmin);
        $url = "/api/v1/admin/orders/{$order->public_id}/fulfilment";

        $this->actingAs($manager)->postJson($url, ['status' => 'processing'])->assertOk()->assertJsonPath('data.fulfilment_status', 'processing');
        $this->actingAs($manager)->postJson($url, ['status' => 'shipped'])->assertUnprocessable()->assertJsonValidationErrors('carrier');
        $this->actingAs($manager)->postJson($url, ['status' => 'shipped', 'carrier' => 'Nepal Can Move', 'tracking_number' => 'NCM-12345'])
            ->assertOk()->assertJsonPath('data.fulfilment.carrier', 'Nepal Can Move')->assertJsonPath('data.fulfilment.tracking_number', 'NCM-12345');
        Notification::assertSentOnDemand(OrderShipped::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === $order->customer_email && $n->tracking === 'NCM-12345');

        $this->actingAs($manager)->postJson($url, ['status' => 'processing'])->assertUnprocessable();
        $this->actingAs($manager)->postJson($url, ['status' => 'delivered'])->assertOk()->assertJsonPath('data.fulfilment_status', 'delivered');
        $this->assertNotNull($order->refresh()->delivered_at);
        $this->actingAs($this->userWithRole(Role::Support))->postJson($url, ['status' => 'delivered'])->assertForbidden();

        $cancelled = $this->attributedOrder($this->approvedAffiliate(), $this->product(), 1);
        $this->actingAs($manager)->postJson("/api/v1/admin/orders/{$cancelled->public_id}/cancel", ['reason' => 'Customer changed their mind'])->assertOk();
        $this->actingAs($manager)->postJson("/api/v1/admin/orders/{$cancelled->public_id}/fulfilment", ['status' => 'processing'])->assertUnprocessable();
    }

    public function test_cash_on_delivery_can_ship_before_payment(): void
    {
        $order = $this->attributedOrder($this->approvedAffiliate(), $this->product(), 1);
        $manager = $this->userWithRole(Role::SuperAdmin);

        $this->actingAs($manager)->postJson("/api/v1/admin/orders/{$order->public_id}/fulfilment", ['status' => 'shipped', 'carrier' => 'Pathao'])
            ->assertOk()->assertJsonPath('data.status', 'pending_payment')->assertJsonPath('data.fulfilment_status', 'shipped');
        $this->assertNotNull($order->refresh()->processing_at);
    }
}
