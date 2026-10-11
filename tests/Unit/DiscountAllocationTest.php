<?php

namespace Tests\Unit;

use App\Domain\Store\CouponEvaluator;
use Tests\TestCase;

class DiscountAllocationTest extends TestCase
{
    public function test_allocation_always_adds_up_to_the_discount(): void
    {
        $evaluator = app(CouponEvaluator::class);

        foreach ([[2, [0 => 1, 1 => 1, 2 => 1]], [7, [0 => 3333, 2 => 6667]], [12345, [0 => 50001, 1 => 100000, 2 => 99999]]] as [$discount, $eligible]) {
            $allocations = $evaluator->allocate($discount, $eligible, 3);

            $this->assertSame($discount, array_sum($allocations));
            foreach ($allocations as $i => $amount) {
                $this->assertLessThanOrEqual($eligible[$i] ?? 0, $amount);
            }
        }
    }

    public function test_ineligible_lines_get_nothing(): void
    {
        $allocations = app(CouponEvaluator::class)->allocate(500, [1 => 1000], 3);

        $this->assertSame([0, 500, 0], $allocations);
    }
}
