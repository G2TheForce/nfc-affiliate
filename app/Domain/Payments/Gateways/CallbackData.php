<?php

namespace App\Domain\Payments\Gateways;

final class CallbackData
{
    /**
     * @param  array<string, mixed>  $safePayload  The callback without anything secret or personal.
     */
    public function __construct(
        public readonly ?string $reference,
        public readonly ?bool $signatureValid,
        public readonly array $safePayload,
    ) {}
}
