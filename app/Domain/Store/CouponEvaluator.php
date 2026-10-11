<?php

namespace App\Domain\Store;

use App\Domain\Referrals\AttributionResolver;
use App\Enums\DiscountType;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Product;
use App\Models\User;
use App\Support\Privacy\KeyedHash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validates a coupon against a cart and computes the discount. One coupon
 * per order; coupons do not stack. Usage limits are checked again inside
 * the checkout transaction with the coupon row locked.
 */
class CouponEvaluator
{
    public function __construct(private readonly AttributionResolver $attribution) {}

    public function find(string $code): Coupon
    {
        $coupon = Coupon::query()->with('affiliate.user')->where('code', Str::upper(trim($code)))->first();

        if (! $coupon) {
            throw $this->invalid('This code is not valid.');
        }

        return $coupon;
    }

    public static function customerKey(string $email): string
    {
        return KeyedHash::make('customer-email', Str::lower(trim($email)));
    }

    /**
     * @param  list<array{product: Product, subtotal: int}>  $lines
     * @return array{discount: int, allocations: list<int>}
     */
    public function evaluate(Coupon $coupon, array $lines, ?User $customer, string $email, string $phone): array
    {
        $now = now();

        if (! $coupon->is_active || ($coupon->starts_at && $coupon->starts_at->isAfter($now)) || ($coupon->ends_at && $coupon->ends_at->isBefore($now))) {
            throw $this->invalid('This code is not active.');
        }

        if ($coupon->affiliate_profile_id) {
            $affiliate = $coupon->affiliate;

            if (! $affiliate->status->isEligibleToEarn()) {
                throw $this->invalid('This code is not active.');
            }

            if ($this->attribution->isSelfReferral($affiliate, $customer, $email, $phone)) {
                throw $this->invalid('You cannot use your own affiliate code.');
            }
        }

        $subtotal = array_sum(array_column($lines, 'subtotal'));

        if ($subtotal < $coupon->min_order_minor) {
            throw $this->invalid('Your order does not reach the minimum amount for this code.');
        }

        $eligibleIds = $coupon->products()->pluck('products.id')->all();
        $eligible = [];
        foreach ($lines as $i => $line) {
            if ($eligibleIds === [] || in_array($line['product']->id, $eligibleIds, true)) {
                $eligible[$i] = $line['subtotal'];
            }
        }

        if ($eligible === []) {
            throw $this->invalid('This code does not apply to the products in your cart.');
        }

        $this->checkLimits($coupon, $email);

        $base = array_sum($eligible);
        $discount = $coupon->discount_type === DiscountType::Percent
            ? intdiv($base * $coupon->discount_value, 10000)
            : $coupon->discount_value;

        if ($coupon->max_discount_minor !== null) {
            $discount = min($discount, $coupon->max_discount_minor);
        }
        $discount = min($discount, $base);

        return ['discount' => $discount, 'allocations' => $this->allocate($discount, $eligible, count($lines))];
    }

    public function checkLimits(Coupon $coupon, string $email): void
    {
        $active = CouponRedemption::query()->where('coupon_id', $coupon->id)->whereNull('voided_at');

        if ($coupon->usage_limit !== null && (clone $active)->count() >= $coupon->usage_limit) {
            throw $this->invalid('This code has reached its usage limit.');
        }

        if ($coupon->per_customer_limit !== null
            && (clone $active)->where('customer_key', self::customerKey($email))->count() >= $coupon->per_customer_limit) {
            throw $this->invalid('You have already used this code the maximum number of times.');
        }
    }

    /**
     * Splits the discount across eligible lines in proportion to their
     * subtotals (largest-remainder method), so line totals always add up.
     *
     * @param  array<int, int>  $eligible  line index => subtotal
     * @return list<int>
     */
    public function allocate(int $discount, array $eligible, int $lineCount): array
    {
        $allocations = array_fill(0, $lineCount, 0);
        $base = array_sum($eligible);

        if ($discount === 0 || $base === 0) {
            return $allocations;
        }

        $remainders = [];
        foreach ($eligible as $i => $subtotal) {
            $share = $discount * $subtotal;
            $allocations[$i] = intdiv($share, $base);
            $remainders[$i] = $share % $base;
        }

        $left = $discount - array_sum($allocations);
        arsort($remainders);
        foreach (array_keys($remainders) as $i) {
            if ($left-- <= 0) {
                break;
            }
            $allocations[$i]++;
        }

        return $allocations;
    }

    private function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['coupon_code' => $message]);
    }
}
