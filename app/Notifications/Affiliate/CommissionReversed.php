<?php

namespace App\Notifications\Affiliate;

use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an affiliate that commission was taken back: after a refund (or a
 * lost dispute, which is recorded as a refund) or when staff cancel it.
 * Staff reasons are never included; they may hold investigation notes.
 */
class CommissionReversed extends Notification implements ShouldQueue
{
    use Queueable;

    public const CAUSE_REFUND = 'refund';

    public const CAUSE_CANCELLED = 'cancelled';

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 30;

    public function __construct(
        public readonly string $orderReference,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $cause,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Commission reversed')
            ->greeting("Hello {$notifiable->name},")
            ->line($this->message())
            ->action('View your earnings', rtrim((string) config('affiliate.frontend_url'), '/').'/account/earnings');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'affiliate.commission_reversed',
            'title' => 'Commission reversed',
            'body' => $this->message(),
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
            'link' => '/account/earnings',
        ];
    }

    private function message(): string
    {
        $amount = Money::format($this->amountMinor, $this->currency);

        return $this->cause === self::CAUSE_REFUND
            ? "Order {$this->orderReference} was refunded, so {$amount} of commission on it was reversed. If it had already been paid to you, it is taken from your next balance."
            : "{$amount} of commission on order {$this->orderReference} was cancelled by our team. Contact us if you have questions.";
    }
}
