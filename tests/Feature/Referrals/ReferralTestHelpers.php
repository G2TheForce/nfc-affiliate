<?php

namespace Tests\Feature\Referrals;

use App\Enums\AffiliateStatus;
use App\Enums\Role;
use App\Models\AffiliateProfile;
use App\Models\Product;
use App\Models\User;
use Illuminate\Testing\TestResponse;

trait ReferralTestHelpers
{
    protected const BROWSER = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/128.0 Mobile Safari/537.36';

    protected function affiliate(AffiliateStatus $status = AffiliateStatus::Approved, array $user = []): AffiliateProfile
    {
        $owner = $this->userWithRole(Role::Affiliate, $user);
        $owner->assignRole(Role::Customer->value);

        return AffiliateProfile::factory()->forUser($owner)->status($status)->create()->load('user');
    }

    protected function product(array $attributes = []): Product
    {
        return Product::factory()->create($attributes);
    }

    protected function visit(string $path, array $headers = []): TestResponse
    {
        return $this->withHeaders(['User-Agent' => self::BROWSER, ...$headers])->get($path);
    }

    /**
     * Follows a referral link and returns the attribution cookie value
     * (the click's public id), as the browser would store it.
     */
    protected function clickCookie(TestResponse $response): ?string
    {
        return $response->getCookie((string) config('affiliate.attribution.cookie'))?->getValue();
    }

    /**
     * Requests from the first-party SPA: Sanctum treats them as stateful,
     * so encrypted cookies are read.
     */
    protected function spa(?string $clickId = null): static
    {
        $this->withCredentials()->withHeaders(['Referer' => 'http://localhost/checkout', 'Accept' => 'application/json']);

        if ($clickId) {
            $this->withCookie((string) config('affiliate.attribution.cookie'), $clickId);
        }

        return $this;
    }

    protected function checkoutPayload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'items' => [['product_id' => $product->public_id, 'quantity' => 2]],
            'customer_name' => 'Sita Sharma',
            'customer_email' => 'sita@example.com',
            'customer_phone' => '+9779811111111',
            'shipping_city' => 'Lalitpur',
            'shipping_address' => 'Jhamsikhel 3',
        ], $overrides);
    }

    protected function placeOrder(Product $product, ?string $clickId = null, array $overrides = [], ?User $as = null): TestResponse
    {
        $request = $this->spa($clickId);
        if ($as) {
            $request = $request->actingAs($as);
        }

        return $request->postJson('/api/v1/store/orders', $this->checkoutPayload($product, $overrides));
    }
}
