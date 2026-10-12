<?php

namespace App\Domain\Payments\Gateways;

/**
 * What the provider's status API says about an attempt.
 */
final class GatewayStatus
{
    public const PAID = 'paid';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    // The provider has no record yet: the customer may still be on its page.
    public const NOT_FOUND = 'not_found';

    public const REFUNDED = 'refunded';

    public function __construct(
        public readonly string $outcome,
        public readonly string $providerStatus,
        public readonly ?int $amountMinor = null,
        public readonly ?string $transactionId = null,
    ) {}
}
