<?php

namespace App\Domain\Referrals;

use App\Models\AffiliateLink;
use App\Models\Product;

/**
 * Turns a referral request into a store path. Destinations come from the
 * database (a link's product) or an allow-list of internal store paths, so
 * /ref/... can never be used as an open redirect.
 */
class DestinationResolver
{
    public const HOME = '/';

    public const SHOP = '/shop';

    /**
     * @return array{path: string, product: ?Product}
     */
    public function resolve(?AffiliateLink $link, ?string $requested): array
    {
        if ($link?->product_id) {
            $product = Product::active()->find($link->product_id);

            return $product
                ? ['path' => self::SHOP.'/'.$product->slug, 'product' => $product]
                : ['path' => self::SHOP, 'product' => null];
        }

        return $this->fromRequested($requested);
    }

    /**
     * @return array{path: string, product: ?Product}
     */
    private function fromRequested(?string $requested): array
    {
        $requested = (string) $requested;

        if ($requested === self::SHOP) {
            return ['path' => self::SHOP, 'product' => null];
        }

        if (preg_match('#^/shop/([a-z0-9][a-z0-9-]{0,219})$#', $requested, $m)) {
            $product = Product::active()->where('slug', $m[1])->first();

            return $product
                ? ['path' => self::SHOP.'/'.$product->slug, 'product' => $product]
                : ['path' => self::SHOP, 'product' => null];
        }

        // Anything else (absolute URLs, //host, backslashes, unknown paths) goes home.
        return ['path' => self::HOME, 'product' => null];
    }
}
