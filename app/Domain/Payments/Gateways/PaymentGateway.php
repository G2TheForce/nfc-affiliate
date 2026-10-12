<?php

namespace App\Domain\Payments\Gateways;

use App\Models\Order;
use App\Models\PaymentAttempt;

/**
 * A customer payment provider. Adapters only talk to the provider; the
 * decision to mark an order paid is ProviderPaymentService's, and only on
 * a server-side status check.
 */
interface PaymentGateway
{
    public function key(): string;

    public function label(): string;

    /** Enabled, configured, and not a sandbox in production. */
    public function available(): bool;

    public function minimumMinor(): int;

    /**
     * Starts the payment with the provider. Returns where to send the
     * customer: a form to post (eSewa) or a URL to open (Khalti), and the
     * provider's reference for the attempt.
     *
     * @return array{reference: string, expires_at: ?\DateTimeInterface, redirect: array{type: 'form'|'redirect', url: string, fields?: array<string, string>}}
     */
    public function start(PaymentAttempt $attempt, Order $order): array;

    /**
     * Reads the provider's browser callback: which attempt it is about and
     * whether its signature is valid (null when the provider does not sign).
     *
     * @param  array<string, mixed>  $query
     */
    public function readCallback(array $query): CallbackData;

    /**
     * Asks the provider for the attempt's current status.
     *
     * @throws GatewayUnavailable when the provider cannot be reached or answers unexpectedly
     */
    public function check(PaymentAttempt $attempt): GatewayStatus;
}
