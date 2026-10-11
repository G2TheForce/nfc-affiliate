<?php

namespace App\Domain\Payments\Gateways;

use App\Models\Order;
use App\Models\PaymentAttempt;
use Illuminate\Http\Client\ConnectionException;

/**
 * eSewa ePay v2. The customer's browser posts a signed form to eSewa; eSewa
 * redirects back with a signed, base64-encoded result. The result is only a
 * hint: payment is confirmed by eSewa's transaction status API.
 *
 * Signatures are HMAC-SHA256, base64, over "field=value" pairs joined by
 * commas in the order given by signed_field_names.
 */
class EsewaGateway extends ConfiguredGateway
{
    public function key(): string
    {
        return 'esewa';
    }

    public function label(): string
    {
        return 'eSewa';
    }

    public function minimumMinor(): int
    {
        return 100;
    }

    protected function hasCredentials(): bool
    {
        return filled($this->config()['product_code'] ?? null) && filled($this->config()['secret_key'] ?? null);
    }

    public function start(PaymentAttempt $attempt, Order $order): array
    {
        // Our attempt id doubles as eSewa's transaction_uuid (letters and digits only).
        $uuid = $attempt->public_id;
        $total = self::amount($attempt->amount_minor);
        $fields = [
            'amount' => $total,
            'tax_amount' => '0',
            'total_amount' => $total,
            'transaction_uuid' => $uuid,
            'product_code' => (string) $this->config()['product_code'],
            'product_service_charge' => '0',
            'product_delivery_charge' => '0',
            // eSewa appends "?data=..." to the success URL, so it has no query of its own.
            'success_url' => $this->callbackUrl('/payments/esewa/return'),
            'failure_url' => $this->callbackUrl("/payments/esewa/failure/{$uuid}"),
            'signed_field_names' => 'total_amount,transaction_uuid,product_code',
        ];
        $fields['signature'] = $this->sign($fields, $fields['signed_field_names']);

        return [
            'reference' => $uuid,
            'expires_at' => null,
            'redirect' => ['type' => 'form', 'url' => $this->config()['urls'][$this->mode()]['form'], 'fields' => $fields],
        ];
    }

    public function readCallback(array $query): CallbackData
    {
        $raw = is_string($query['data'] ?? null) ? $query['data'] : null;
        $decoded = $raw !== null ? json_decode((string) base64_decode($raw, true), true) : null;

        if (! is_array($decoded)) {
            return new CallbackData(null, false, ['data' => $raw === null ? null : 'unreadable']);
        }

        $signed = (string) ($decoded['signed_field_names'] ?? '');
        $valid = $signed !== ''
            && str_contains(",$signed,", ',transaction_uuid,')
            && str_contains(",$signed,", ',total_amount,')
            && is_string($decoded['signature'] ?? null)
            && hash_equals($this->sign($decoded, $signed), $decoded['signature']);

        return new CallbackData(
            is_string($decoded['transaction_uuid'] ?? null) ? $decoded['transaction_uuid'] : null,
            $valid,
            array_intersect_key($decoded, array_flip(['transaction_code', 'status', 'total_amount', 'transaction_uuid', 'product_code'])),
        );
    }

    public function check(PaymentAttempt $attempt): GatewayStatus
    {
        try {
            $response = $this->http()->get($this->config()['urls'][$this->mode()]['status'], [
                'product_code' => $this->config()['product_code'],
                'total_amount' => self::amount($attempt->amount_minor),
                'transaction_uuid' => $attempt->provider_reference,
            ]);
        } catch (ConnectionException $e) {
            throw new GatewayUnavailable('eSewa could not be reached: '.$e->getMessage(), 0, $e);
        }

        $status = $response->json('status');
        if (! $response->successful() || ! is_string($status)) {
            throw new GatewayUnavailable("eSewa status check failed (HTTP {$response->status()}).");
        }
        if ($response->json('transaction_uuid') !== null && $response->json('transaction_uuid') !== $attempt->provider_reference) {
            throw new GatewayUnavailable('eSewa answered about a different transaction.');
        }

        $amount = self::toMinor($response->json('total_amount'));
        $ref = $response->json('ref_id');
        $ref = is_scalar($ref) && (string) $ref !== '' ? (string) $ref : null;

        return match ($status) {
            'COMPLETE' => new GatewayStatus(GatewayStatus::PAID, $status, $amount, $ref),
            'PENDING', 'AMBIGUOUS' => new GatewayStatus(GatewayStatus::PENDING, $status, $amount, $ref),
            'NOT_FOUND' => new GatewayStatus(GatewayStatus::NOT_FOUND, $status),
            'CANCELED' => new GatewayStatus(GatewayStatus::FAILED, $status),
            'FULL_REFUND', 'PARTIAL_REFUND' => new GatewayStatus(GatewayStatus::REFUNDED, $status, $amount, $ref),
            default => throw new GatewayUnavailable("Unexpected eSewa status \"{$status}\"."),
        };
    }

    /** @param  array<string, mixed>  $values */
    private function sign(array $values, string $signedFieldNames): string
    {
        $message = collect(explode(',', $signedFieldNames))
            ->map(fn ($field) => $field.'='.(is_scalar($values[$field] ?? null) ? (string) $values[$field] : ''))
            ->implode(',');

        return base64_encode(hash_hmac('sha256', $message, (string) $this->config()['secret_key'], true));
    }

    /** Paisa to the rupee string eSewa expects: "1500" or "1500.50", no thousands separators. */
    public static function amount(int $minor): string
    {
        return $minor % 100 === 0 ? (string) intdiv($minor, 100) : number_format($minor / 100, 2, '.', '');
    }

    /** eSewa may answer "1,500.0"; convert to paisa, or null when unreadable. */
    public static function toMinor(mixed $value): ?int
    {
        if (! is_scalar($value)) {
            return null;
        }
        $clean = str_replace(',', '', trim((string) $value));
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $clean)) {
            return null;
        }
        [$rupees, $paisa] = array_pad(explode('.', $clean), 2, '0');

        return (int) $rupees * 100 + (int) str_pad($paisa, 2, '0');
    }
}
