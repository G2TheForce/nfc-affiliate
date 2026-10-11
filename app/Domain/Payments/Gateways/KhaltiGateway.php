<?php

namespace App\Domain\Payments\Gateways;

use App\Models\Order;
use App\Models\PaymentAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Validation\ValidationException;

/**
 * Khalti ePayment (KPG-2). The server starts the payment and gets a pidx
 * and a payment page URL; Khalti redirects the customer back with the pidx.
 * Khalti does not sign the redirect, so the lookup API is the only proof:
 * only "Completed" counts as paid.
 */
class KhaltiGateway extends ConfiguredGateway
{
    public function key(): string
    {
        return 'khalti';
    }

    public function label(): string
    {
        return 'Khalti';
    }

    public function minimumMinor(): int
    {
        return (int) ($this->config()['minimum_minor'] ?? 1000);
    }

    protected function hasCredentials(): bool
    {
        return filled($this->config()['secret_key'] ?? null);
    }

    private function api(): PendingRequest
    {
        return $this->http()->withHeaders(['Authorization' => 'Key '.$this->config()['secret_key']]);
    }

    private function url(string $path): string
    {
        return $this->config()['urls'][$this->mode()].$path;
    }

    public function start(PaymentAttempt $attempt, Order $order): array
    {
        try {
            $response = $this->api()->post($this->url('initiate/'), [
                'return_url' => $this->callbackUrl('/payments/khalti/return'),
                'website_url' => rtrim((string) config('app.url'), '/'),
                'amount' => $attempt->amount_minor,
                'purchase_order_id' => $attempt->public_id,
                'purchase_order_name' => "Order {$order->order_number}",
                'customer_info' => ['name' => $order->customer_name, 'email' => $order->customer_email, 'phone' => $order->customer_phone],
            ]);
        } catch (ConnectionException $e) {
            throw new GatewayUnavailable('Khalti could not be reached: '.$e->getMessage(), 0, $e);
        }

        $pidx = $response->json('pidx');
        $url = $response->json('payment_url');
        if ($response->status() === 400) {
            throw ValidationException::withMessages(['provider' => 'Khalti did not accept this payment. Please choose another way to pay.']);
        }
        if (! $response->successful() || ! is_string($pidx) || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new GatewayUnavailable("Khalti could not start the payment (HTTP {$response->status()}).");
        }

        $expires = $response->json('expires_at');

        return [
            'reference' => $pidx,
            'expires_at' => is_string($expires) ? CarbonImmutable::parse($expires) : null,
            'redirect' => ['type' => 'redirect', 'url' => $url],
        ];
    }

    public function readCallback(array $query): CallbackData
    {
        $pidx = is_string($query['pidx'] ?? null) ? $query['pidx'] : null;

        return new CallbackData(
            $pidx,
            null,
            array_intersect_key($query, array_flip(['pidx', 'status', 'transaction_id', 'amount', 'total_amount', 'purchase_order_id'])),
        );
    }

    public function check(PaymentAttempt $attempt): GatewayStatus
    {
        try {
            $response = $this->api()->post($this->url('lookup/'), ['pidx' => $attempt->provider_reference]);
        } catch (ConnectionException $e) {
            throw new GatewayUnavailable('Khalti could not be reached: '.$e->getMessage(), 0, $e);
        }

        $status = $response->json('status');
        if (! is_string($status) || ! in_array($response->status(), [200, 400, 404], true)) {
            throw new GatewayUnavailable("Khalti lookup failed (HTTP {$response->status()}).");
        }
        if ($response->json('pidx') !== null && $response->json('pidx') !== $attempt->provider_reference) {
            throw new GatewayUnavailable('Khalti answered about a different payment.');
        }

        $amount = is_int($response->json('total_amount')) ? $response->json('total_amount') : null;
        $txn = $response->json('transaction_id');
        $txn = is_string($txn) && $txn !== '' ? $txn : null;

        return match ($status) {
            'Completed' => new GatewayStatus(GatewayStatus::PAID, $status, $amount, $txn),
            'Pending', 'Initiated' => new GatewayStatus(GatewayStatus::PENDING, $status, $amount, $txn),
            'Expired', 'User canceled', 'Canceled', 'Failed' => new GatewayStatus(GatewayStatus::FAILED, $status),
            'Refunded', 'Partially Refunded' => new GatewayStatus(GatewayStatus::REFUNDED, $status, $amount, $txn),
            default => throw new GatewayUnavailable("Unexpected Khalti status \"{$status}\"."),
        };
    }
}
