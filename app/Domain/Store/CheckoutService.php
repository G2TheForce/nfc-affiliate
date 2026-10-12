<?php

namespace App\Domain\Store;

use App\Domain\Referrals\AttributionResolver;
use App\Domain\Referrals\ReferralEventRecorder;
use App\Enums\AttributionStatus;
use App\Enums\OrderStatus;
use App\Enums\ReferralEventType;
use App\Models\AffiliateProfile;
use App\Models\Campaign;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\OrderAttribution;
use App\Models\Product;
use App\Models\ReferralClick;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Places an order: prices from the catalog, applies at most one coupon,
 * decides attribution and records the order_placed event, all in one
 * transaction. The order starts as pending_payment; it becomes paid only
 * from a verified payment (Phase 4/7).
 */
class CheckoutService
{
    public function __construct(
        private readonly CouponEvaluator $coupons,
        private readonly AttributionResolver $attribution,
        private readonly ReferralEventRecorder $events,
        private readonly OrderNumberGenerator $numbers,
    ) {}

    /**
     * @param  array{items: list<array{product_id: string, quantity: int}>, customer_name: string, customer_email: string, customer_phone: string, shipping_city: string, shipping_address: string, coupon_code?: ?string, referral_code?: ?string}  $data
     * @return array{order: Order, replayed: bool}
     */
    public function place(array $data, ?User $customer, ?ReferralClick $click, ?string $idempotencyKey): array
    {
        $email = $customer ? $customer->email : Str::lower(trim($data['customer_email']));
        $requestHash = hash('sha256', json_encode([$data, $customer?->id]));

        if ($idempotencyKey && ($existing = $this->replay($idempotencyKey, $requestHash))) {
            return ['order' => $existing, 'replayed' => true];
        }

        $lines = $this->priceLines($data['items']);

        try {
            $order = DB::transaction(function () use ($data, $customer, $click, $idempotencyKey, $requestHash, $email, $lines) {
                $coupon = null;
                $discount = ['discount' => 0, 'allocations' => array_fill(0, count($lines), 0)];

                if (! empty($data['coupon_code'])) {
                    $coupon = $this->coupons->find($data['coupon_code']);
                    // Serialise redemptions of this coupon so limits hold under concurrency.
                    Coupon::query()->whereKey($coupon->id)->lockForUpdate()->first();
                    $discount = $this->coupons->evaluate($coupon, $lines, $customer, $email, $data['customer_phone']);
                }

                $decision = $this->attribution->resolve(
                    $click, $coupon, $data['referral_code'] ?? null, $customer, $email, $data['customer_phone'],
                );

                $subtotal = array_sum(array_column($lines, 'subtotal'));
                $shipping = (int) config('affiliate.store.shipping_flat_minor');

                $order = new Order;
                $order->forceFill([
                    'order_number' => $this->numbers->next(),
                    'customer_user_id' => $customer?->id,
                    'customer_name' => trim($data['customer_name']),
                    'customer_email' => $email,
                    'customer_phone' => $data['customer_phone'],
                    'shipping_city' => trim($data['shipping_city']),
                    'shipping_address' => trim($data['shipping_address']),
                    'status' => OrderStatus::PendingPayment,
                    'currency' => config('affiliate.currency.default'),
                    'subtotal_minor' => $subtotal,
                    'discount_minor' => $discount['discount'],
                    'shipping_minor' => $shipping,
                    'total_minor' => $subtotal - $discount['discount'] + $shipping,
                    'coupon_id' => $coupon?->id,
                    'attribution_status' => $decision->status,
                    'attribution_block_reason' => $decision->blockReason,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $idempotencyKey ? $requestHash : null,
                    'placed_at' => now(),
                ])->save();

                foreach ($lines as $i => $line) {
                    $order->items()->forceCreate([
                        'product_id' => $line['product']->id,
                        'category_id' => $line['product']->category_id,
                        'sku' => $line['product']->sku,
                        'name' => $line['product']->name,
                        'unit_price_minor' => $line['product']->price_minor,
                        'quantity' => $line['quantity'],
                        'line_subtotal_minor' => $line['subtotal'],
                        'discount_minor' => $discount['allocations'][$i],
                        'line_total_minor' => $line['subtotal'] - $discount['allocations'][$i],
                    ]);
                }

                if ($coupon) {
                    CouponRedemption::create([
                        'coupon_id' => $coupon->id,
                        'order_id' => $order->id,
                        'customer_key' => CouponEvaluator::customerKey($email),
                        'discount_minor' => $discount['discount'],
                    ]);
                }

                if ($decision->status === AttributionStatus::Attributed) {
                    (new OrderAttribution)->forceFill([
                        'order_id' => $order->id,
                        'affiliate_profile_id' => $decision->affiliate->id,
                        'source' => $decision->source,
                        'referral_click_id' => $decision->click?->id,
                        'coupon_id' => $decision->coupon?->id,
                        'policy' => config('affiliate.attribution.policy'),
                        'window_days' => (int) config('affiliate.attribution.window_days'),
                        'clicked_at' => $decision->click?->created_at,
                        'campaign_id' => $this->campaignFor($decision->affiliate, $decision->coupon, $decision->click, $coupon)?->id,
                    ])->save();

                    $this->events->record(ReferralEventType::OrderPlaced, $decision->affiliate->id, $order, $subtotal - $discount['discount']);
                }

                return $order;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two identical submissions raced; the other one created the order.
            if ($idempotencyKey && ($existing = $this->replay($idempotencyKey, $requestHash))) {
                return ['order' => $existing, 'replayed' => true];
            }
            throw $e;
        }

        return ['order' => $order, 'replayed' => false];
    }

    /**
     * The campaign the order came through: the attributing coupon's, else the
     * tracking link's, else a store-wide coupon's. It counts only while the
     * campaign runs and the affiliate takes part.
     */
    private function campaignFor(AffiliateProfile $affiliate, ?Coupon $attributingCoupon, ?ReferralClick $click, ?Coupon $orderCoupon): ?Campaign
    {
        $storeCoupon = $orderCoupon && $orderCoupon->affiliate_profile_id === null ? $orderCoupon : null;

        foreach ([$attributingCoupon?->campaign, $click?->link?->campaign, $storeCoupon?->campaign] as $campaign) {
            if ($campaign && $campaign->isRunningAt(now()) && $campaign->includes($affiliate)) {
                return $campaign;
            }
        }

        return null;
    }

    private function replay(string $key, string $requestHash): ?Order
    {
        $existing = Order::where('idempotency_key', $key)->first();

        if ($existing && ! hash_equals((string) $existing->request_hash, $requestHash)) {
            throw new ConflictHttpException('This checkout key was already used for a different order.');
        }

        return $existing;
    }

    /**
     * @param  list<array{product_id: string, quantity: int}>  $items
     * @return list<array{product: Product, quantity: int, subtotal: int}>
     */
    private function priceLines(array $items): array
    {
        $quantities = [];
        foreach ($items as $item) {
            $quantities[$item['product_id']] = ($quantities[$item['product_id']] ?? 0) + (int) $item['quantity'];
        }

        $products = Product::active()->whereIn('public_id', array_keys($quantities))->get()->keyBy('public_id');
        $max = (int) config('affiliate.store.max_quantity_per_line');
        $currency = config('affiliate.currency.default');
        $lines = [];

        foreach ($quantities as $publicId => $quantity) {
            $product = $products->get($publicId);

            if (! $product || $product->currency !== $currency) {
                throw ValidationException::withMessages(['items' => 'One of the products in your cart is no longer available.']);
            }
            if ($quantity > $max) {
                throw ValidationException::withMessages(['items' => "You can order at most {$max} of one product."]);
            }

            $lines[] = ['product' => $product, 'quantity' => $quantity, 'subtotal' => $product->price_minor * $quantity];
        }

        return $lines;
    }
}
