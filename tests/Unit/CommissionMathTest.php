<?php

namespace Tests\Unit;

use App\Domain\Commissions\CommissionMath;
use App\Enums\CommissionRuleKind;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CommissionMathTest extends TestCase
{
    public function test_percent_rounds_half_up_to_the_paisa(): void
    {
        $this->assertSame(20000, CommissionMath::percent(200000, 1000)); // NPR 2,000 at 10% = NPR 200
        $this->assertSame(1667, CommissionMath::percent(33333, 500));    // 16.6665 -> 16.67
        $this->assertSame(1666, CommissionMath::percent(33329, 500));    // 16.6645 -> 16.66
    }

    public function test_percent_half_values_round_up_and_below_half_round_down(): void
    {
        $this->assertSame(1, CommissionMath::percent(5, 1000));  // 0.5 -> 1
        $this->assertSame(0, CommissionMath::percent(4, 1000));  // 0.4 -> 0
        $this->assertSame(0, CommissionMath::percent(0, 1000));
    }

    public function test_fixed_amount_is_per_unit_and_never_exceeds_the_line(): void
    {
        $this->assertSame(30000, CommissionMath::amount(CommissionRuleKind::Fixed, 500000, 3, null, 10000));
        $this->assertSame(15000, CommissionMath::amount(CommissionRuleKind::Fixed, 15000, 3, null, 10000));
        $this->assertSame(0, CommissionMath::amount(CommissionRuleKind::Exclude, 15000, 3, null, null));
    }

    public function test_remaining_after_refund_recomputes_on_the_remaining_base(): void
    {
        $this->assertSame(15000, CommissionMath::remainingAfterRefund(CommissionRuleKind::Percent, 20000, 200000, 50000, 1000));
        $this->assertSame(0, CommissionMath::remainingAfterRefund(CommissionRuleKind::Percent, 20000, 200000, 200000, 1000));
        $this->assertSame(0, CommissionMath::remainingAfterRefund(CommissionRuleKind::Percent, 20000, 200000, 250000, 1000));
        // Fixed 300.00 on a 1,000.00 line, 250.00 refunded: 300 × 750/1000 = 225.00
        $this->assertSame(22500, CommissionMath::remainingAfterRefund(CommissionRuleKind::Fixed, 30000, 100000, 25000, null));
    }

    public function test_prorate_rounds_half_up(): void
    {
        $this->assertSame(3334, CommissionMath::prorate(10001, 1, 3)); // 3333.67
        $this->assertSame(5001, CommissionMath::prorate(10001, 1, 2)); // 5000.5
    }

    public function test_negative_inputs_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CommissionMath::percent(-1, 1000);
    }
}
