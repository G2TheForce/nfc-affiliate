<?php

namespace App\Domain\Payments\Gateways;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Shared configuration handling for the provider adapters. */
abstract class ConfiguredGateway implements PaymentGateway
{
    /** @return array<string, mixed> */
    protected function config(): array
    {
        return (array) config("payments.providers.{$this->key()}");
    }

    protected function mode(): string
    {
        return $this->config()['mode'] === 'production' ? 'production' : 'sandbox';
    }

    /** Credentials this provider needs before it can be offered. */
    abstract protected function hasCredentials(): bool;

    public function available(): bool
    {
        if (! ($this->config()['enabled'] ?? false) || ! $this->hasCredentials()) {
            return false;
        }

        // Test payments must never mark real orders paid.
        return ! (app()->environment('production') && $this->mode() === 'sandbox');
    }

    protected function http(): PendingRequest
    {
        return Http::acceptJson()->timeout((int) config('payments.http_timeout', 15))->connectTimeout(10);
    }

    protected function callbackUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }
}
