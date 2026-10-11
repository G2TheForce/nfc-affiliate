<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Domain\Referrals\ReferralTracker;
use App\Domain\Store\CheckoutService;
use App\Domain\Store\CouponEvaluator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\CheckoutRequest;
use App\Http\Requests\Store\QuoteRequest;
use App\Http\Resources\Store\OrderResource;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    /**
     * Prices a cart (and an optional coupon) without creating anything.
     */
    public function quote(QuoteRequest $request, CouponEvaluator $coupons): array
    {
        $customer = $this->customer($request);
        $products = Product::active()->whereIn('public_id', collect($request->validated('items'))->pluck('product_id'))->get()->keyBy('public_id');

        $lines = [];
        foreach ($request->validated('items') as $item) {
            $product = $products->get($item['product_id']);
            if (! $product) {
                throw ValidationException::withMessages(['items' => 'One of the products in your cart is no longer available.']);
            }
            $lines[] = ['product' => $product, 'quantity' => (int) $item['quantity'], 'subtotal' => $product->price_minor * (int) $item['quantity']];
        }

        $subtotal = array_sum(array_column($lines, 'subtotal'));
        $discount = 0;
        $code = $request->validated('coupon_code');

        if ($code) {
            $coupon = $coupons->find($code);
            $discount = $coupons->evaluate(
                $coupon, $lines, $customer,
                $customer?->email ?? (string) $request->validated('customer_email'),
                (string) $request->validated('customer_phone'),
            )['discount'];
        }

        $shipping = (int) config('affiliate.store.shipping_flat_minor');

        return ['data' => [
            'currency' => config('affiliate.currency.default'),
            'subtotal_minor' => $subtotal,
            'discount_minor' => $discount,
            'shipping_minor' => $shipping,
            'total_minor' => $subtotal - $discount + $shipping,
            'coupon_code' => $code ? strtoupper(trim($code)) : null,
            'lines' => array_map(fn ($l) => [
                'product_id' => $l['product']->public_id,
                'name' => $l['product']->name,
                'unit_price_minor' => $l['product']->price_minor,
                'quantity' => $l['quantity'],
                'subtotal_minor' => $l['subtotal'],
            ], $lines),
        ]];
    }

    public function store(CheckoutRequest $request, CheckoutService $checkout, ReferralTracker $tracker): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if ($key !== null && ! preg_match('/^[A-Za-z0-9-]{16,80}$/', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'The Idempotency-Key header is invalid.']);
        }

        $result = $checkout->place($request->validated(), $this->customer($request), $tracker->currentClick($request), $key);
        $order = $result['order']->load(['items', 'coupon']);

        return (new OrderResource($order))->response()->setStatusCode($result['replayed'] ? 200 : 201);
    }

    /**
     * Whether this visitor arrived through a referral link, so the store can
     * show who referred them. Only the affiliate's public display name.
     */
    public function referral(Request $request, ReferralTracker $tracker): array
    {
        $click = $tracker->currentClick($request);
        $affiliate = $click?->affiliate;

        return ['data' => $affiliate && $affiliate->status->isEligibleToEarn()
            ? ['referred' => true, 'affiliate_name' => $affiliate->display_name]
            : ['referred' => false, 'affiliate_name' => null]];
    }

    private function customer(Request $request): ?User
    {
        /** @var User|null $user */
        $user = $request->user('sanctum');

        if ($user && ! $user->isActive()) {
            abort(403, 'This account is disabled.');
        }

        return $user;
    }
}
