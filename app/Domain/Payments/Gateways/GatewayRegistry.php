<?php

namespace App\Domain\Payments\Gateways;

use Illuminate\Validation\ValidationException;

class GatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $gateways;

    public function __construct(EsewaGateway $esewa, KhaltiGateway $khalti)
    {
        $this->gateways = [$esewa->key() => $esewa, $khalti->key() => $khalti];
    }

    /** @return array<string, PaymentGateway> */
    public function available(): array
    {
        return array_filter($this->gateways, fn (PaymentGateway $g) => $g->available());
    }

    /** Any configured gateway, available or not (for checking attempts started earlier). */
    public function get(string $key): PaymentGateway
    {
        return $this->gateways[$key] ?? throw ValidationException::withMessages(['provider' => 'Unknown payment provider.']);
    }

    public function availableOrFail(string $key): PaymentGateway
    {
        $gateway = $this->get($key);
        if (! $gateway->available()) {
            throw ValidationException::withMessages(['provider' => "{$gateway->label()} payments are not available right now."]);
        }

        return $gateway;
    }
}
